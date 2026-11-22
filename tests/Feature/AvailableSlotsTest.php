<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\WorkingHour;
use App\Scheduling\AvailableSlots;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;

use function Pest\Laravel\travelTo;

/*
 * ISSUE-11: AvailableSlots wired to the database: weekly schedule, holidays,
 * exceptions, existing bookings and buffer. "Now" is Monday 2026-11-23 10:00
 * in Amsterdam; the date under test is Tuesday 2026-12-01 (UTC+1). The
 * employee works Tuesdays 09:00-12:00; the service takes 30 min + 15 min
 * buffer; the interval is 15 min.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);
});

/**
 * The employee (Tuesdays 09:00-12:00) and the linked service (30 + 15 min).
 *
 * @return array{Employee, Service}
 */
function tuesdaySetup(): array
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();

    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee->services()->attach($service);

    return [$employee, $service];
}

/**
 * @return list<string> local start times
 */
function freeSlots(Service $service, Employee $employee, string $date = '2026-12-01', ?CarbonImmutable $now = null): array
{
    $slots = app(AvailableSlots::class)->for(
        $service,
        $employee->fresh() ?? $employee,
        CarbonImmutable::parse($date, 'Europe/Amsterdam'),
        $now,
    );

    return array_map(fn (Slot $slot): string => $slot->label(), $slots);
}

/**
 * Every start on the free test Tuesday, 09:00-11:30.
 *
 * @return list<string>
 */
function fullTuesday(): array
{
    return ['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30'];
}

/**
 * An existing booking for the employee, given in local time.
 */
function bookedAt(Employee $employee, string $start, int $duration, int $buffer = 0, BookingStatus $status = BookingStatus::Confirmed, string $date = '2026-12-01'): Booking
{
    $startsAt = CarbonImmutable::parse("{$date} {$start}", 'Europe/Amsterdam')->utc();

    return Booking::factory()->create([
        'employee_id' => $employee->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes($duration),
        'blocked_until' => $startsAt->addMinutes($duration + $buffer),
        'duration_minutes' => $duration,
        'status' => $status,
    ]);
}

it('offers every start in the weekly schedule on a free day', function () {
    [$employee, $service] = tuesdaySetup();
    expect(freeSlots($service, $employee))->toBe(fullTuesday());
});

it('returns UTC moments with duration and buffer applied', function () {
    [$employee, $service] = tuesdaySetup();
    [$slot] = app(AvailableSlots::class)->for($service, $employee, CarbonImmutable::parse('2026-12-01', 'Europe/Amsterdam'));

    expect($slot->startsAt->toDateTimeString())->toBe('2026-12-01 08:00:00')
        ->and($slot->endsAt->toDateTimeString())->toBe('2026-12-01 08:30:00')
        ->and($slot->blockedUntil->toDateTimeString())->toBe('2026-12-01 08:45:00');
});

it('offers nothing on a day the employee does not work', function () {
    [$employee, $service] = tuesdaySetup();
    expect(freeSlots($service, $employee, '2026-12-02'))->toBe([]);
});

it('offers nothing for an inactive service, an inactive or an unlinked employee', function () {
    [$employee, $service] = tuesdaySetup();
    $inactiveService = Service::factory()->create(['is_active' => false]);
    $employee->services()->attach($inactiveService);

    $unlinked = Employee::factory()->create();
    WorkingHour::factory()->for($unlinked)->on(Weekday::Tuesday, '09:00', '12:00')->create();

    expect(freeSlots($inactiveService, $employee))->toBe([])
        ->and(freeSlots($service, $unlinked))->toBe([]);

    $employee->update(['is_active' => false]);

    expect(freeSlots($service, $employee))->toBe([]);
});

it('offers nothing on a closed public holiday, unless extra hours are added', function () {
    [$employee, $service] = tuesdaySetup();
    // Eerste Kerstdag 2026 is a Friday.
    WorkingHour::factory()->for($employee)->on(Weekday::Friday, '09:00', '12:00')->create();

    expect(freeSlots($service, $employee, '2026-12-25'))->toBe([]);

    ScheduleException::factory()->for($employee)->extraHours('2026-12-25', '10:00', '11:00')->create();

    expect(freeSlots($service, $employee, '2026-12-25'))->toBe(['10:00', '10:15', '10:30']);
});

it('leaves out time off from schedule exceptions', function () {
    [$employee, $service] = tuesdaySetup();
    ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '10:00', '12:00')->create();

    expect(freeSlots($service, $employee))->toBe(['09:00', '09:15', '09:30']);
});

it('offers nothing on a whole day off', function () {
    [$employee, $service] = tuesdaySetup();
    ScheduleException::factory()->for($employee)->unavailable('2026-12-01')->create();

    expect(freeSlots($service, $employee))->toBe([]);
});

it('keeps existing bookings and both buffers free', function () {
    [$employee, $service] = tuesdaySetup();
    // Another service, 10:00-10:30 with 15 min buffer: blocked until 10:45.
    bookedAt($employee, '10:00', 30, buffer: 15);

    // A new start needs its own 30 + 15 min free before 10:00, so 09:15 is the last one.
    expect(freeSlots($service, $employee))
        ->toBe(['09:00', '09:15', '10:45', '11:00', '11:15', '11:30']);
});

it('is blocked by requested and confirmed bookings only', function (BookingStatus $status, bool $blocks) {
    [$employee, $service] = tuesdaySetup();
    bookedAt($employee, '09:00', 180, status: $status);

    expect(freeSlots($service, $employee))->toBe($blocks ? [] : fullTuesday());
})->with([
    'requested' => [BookingStatus::Requested, true],
    'confirmed' => [BookingStatus::Confirmed, true],
    'cancelled' => [BookingStatus::Cancelled, false],
    'completed' => [BookingStatus::Completed, false],
    'no-show' => [BookingStatus::NoShow, false],
]);

it('ignores bookings of other employees and on other days', function () {
    [$employee, $service] = tuesdaySetup();
    bookedAt(Employee::factory()->create(), '09:00', 180);
    bookedAt($employee, '09:00', 180, date: '2026-12-08');

    expect(freeSlots($service, $employee))->toBe(fullTuesday());
});

it('sees a booking that started before working time and runs into it', function () {
    [$employee, $service] = tuesdaySetup();
    // 08:00-09:00 plus 30 min buffer: blocked until 09:30.
    bookedAt($employee, '08:00', 60, buffer: 30);

    expect(freeSlots($service, $employee))->toBe(array_slice(fullTuesday(), 2));
});

it('leaves out start times that have already passed today', function () {
    [$employee, $service] = tuesdaySetup();
    travelTo(CarbonImmutable::parse('2026-12-01 10:05', 'Europe/Amsterdam'));

    expect(freeSlots($service, $employee))
        ->toBe(['10:15', '10:30', '10:45', '11:00', '11:15', '11:30'])
        ->and(freeSlots($service, $employee, now: CarbonImmutable::parse('2026-12-01 11:30', 'Europe/Amsterdam')))
        ->toBe(['11:30']);
});

it('sees a booking right after working time that the new buffer would run into', function () {
    [$employee, $service] = tuesdaySetup();
    // 11:30 + 30 min + 15 min buffer = 12:15, overlapping a booking at 12:00.
    // Proves the booking query window is extended by the service buffer.
    bookedAt($employee, '12:00', 30);

    expect(freeSlots($service, $employee))->toBe(array_slice(fullTuesday(), 0, -1));
});

it('ignores a booking that ends exactly when working time starts', function () {
    [$employee, $service] = tuesdaySetup();
    bookedAt($employee, '08:00', 45, buffer: 15);

    expect(freeSlots($service, $employee))->toBe(fullTuesday());
});

it('offers the last start for a service without buffer, even with a booking at closing time', function () {
    [$employee] = tuesdaySetup();
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 0]);
    $employee->services()->attach($service);
    bookedAt($employee, '12:00', 30);

    expect(freeSlots($service, $employee))->toBe(fullTuesday());
});

it('offers slots in both blocks of a split weekly shift', function () {
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '10:00')->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '13:00', '14:00')->create();
    $service = Service::factory()->create(['duration_minutes' => 60, 'buffer_minutes' => 0]);
    $employee->services()->attach($service);

    expect(freeSlots($service, $employee))->toBe(['09:00', '13:00']);
});

it('offers nothing on a day that has already passed', function () {
    [$employee, $service] = tuesdaySetup();
    travelTo(CarbonImmutable::parse('2026-12-02 08:00', 'Europe/Amsterdam'));

    expect(freeSlots($service, $employee))->toBe([]);
});

it('accepts any DateTimeInterface as the date', function () {
    [$employee, $service] = tuesdaySetup();
    $slots = app(AvailableSlots::class)->for($service, $employee, new DateTimeImmutable('2026-12-01'));

    expect(array_map(fn (Slot $slot): string => $slot->label(), $slots))->toBe(fullTuesday());
});

it('takes the interval from config', function () {
    [$employee, $service] = tuesdaySetup();
    config(['booking.slot_interval' => 60]);

    expect(freeSlots($service, $employee))->toBe(['09:00', '10:00', '11:00']);
});
