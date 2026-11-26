<?php

use App\Bookings\BookingRefused;
use App\Bookings\CancelBooking;
use App\Bookings\CreateBooking;
use App\Enums\BookingStatus;
use App\Enums\ScheduleExceptionType;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Scheduling\AvailableSlots;
use App\Scheduling\Slot;
use Carbon\CarbonImmutable;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-23: edge cases of time slots and overlap, end to end through
 * AvailableSlots (what is offered) and CreateBooking (what is accepted), so
 * both sides are proven to agree:
 * - an appointment that fits a gap exactly, to the minute;
 * - slots around midnight (whole-day extra hours, buffers and bookings
 *   that run into the next day);
 * - a service longer than what is left of a block;
 * - buffer time: whose buffer counts, and where it may run;
 * - cancelled bookings giving their time back.
 *
 * "Now" is Monday 2026-11-23 10:00 in Amsterdam; the dates under test are
 * Tuesday 2026-12-01 and Wednesday 2026-12-02 (winter time, UTC+1). The
 * interval is 15 minutes unless a test says otherwise.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);
});

/**
 * An employee with weekly blocks on Tuesday.
 *
 * @param  list<string>  $tuesday  "HH:MM-HH:MM", local time
 */
function oecEmployee(array $tuesday = ['09:00-12:00']): Employee
{
    $employee = Employee::factory()->create();
    foreach ($tuesday as $block) {
        [$from, $until] = explode('-', $block);
        WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, $from, $until)->create();
    }

    return $employee;
}

/**
 * Extra hours for the whole local day, 00:00 up to the next midnight.
 */
function oecWholeDay(Employee $employee, string $date): void
{
    ScheduleException::factory()->for($employee)->create([
        'date' => $date,
        'type' => ScheduleExceptionType::ExtraHours,
        'starts_at' => null,
        'ends_at' => null,
    ]);
}

function oecService(Employee $employee, int $duration, int $buffer): Service
{
    $service = Service::factory()->create(['duration_minutes' => $duration, 'buffer_minutes' => $buffer]);
    $employee->services()->attach($service);

    return $service;
}

function oecAt(string $time, string $date = '2026-12-01'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Amsterdam');
}

/**
 * An existing booking of the employee, given in local time.
 */
function oecBooked(Employee $employee, string $start, int $duration, int $buffer = 0, string $date = '2026-12-01', BookingStatus $status = BookingStatus::Confirmed): Booking
{
    $startsAt = oecAt($start, $date)->utc();

    return Booking::factory()->create([
        'employee_id' => $employee->id,
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes($duration),
        'blocked_until' => $startsAt->addMinutes($duration + $buffer),
        'duration_minutes' => $duration,
        'status' => $status,
    ]);
}

/**
 * @return list<Slot>
 */
function oecSlots(Service $service, Employee $employee, string $date = '2026-12-01'): array
{
    return app(AvailableSlots::class)->for($service->fresh() ?? $service, $employee->fresh() ?? $employee, oecAt('00:00', $date));
}

/**
 * @return list<string> local start times
 */
function oecStarts(Service $service, Employee $employee, string $date = '2026-12-01'): array
{
    return array_map(fn (Slot $slot): string => $slot->label(), oecSlots($service, $employee, $date));
}

/**
 * The last local start time offered on the date.
 */
function oecLastStart(Service $service, Employee $employee, string $date = '2026-12-01'): ?string
{
    $starts = oecStarts($service, $employee, $date);

    return $starts === [] ? null : $starts[array_key_last($starts)];
}

/**
 * Books as a new customer, so only the employee's agenda can conflict.
 */
function oecBook(Service $service, Employee $employee, CarbonImmutable $startsAt): Booking
{
    return app(CreateBooking::class)->handle(
        User::factory()->customer()->create(),
        $service->fresh() ?? $service,
        $employee->fresh() ?? $employee,
        $startsAt,
    );
}

/**
 * The BookingRefused reason, or null when the booking was made.
 */
function oecRefusal(Service $service, Employee $employee, CarbonImmutable $startsAt): ?string
{
    try {
        oecBook($service, $employee, $startsAt);
    } catch (BookingRefused $refused) {
        return $refused->reason;
    }

    return null;
}

describe('an appointment that fits exactly', function () {
    it('offers and books a gap that is exactly the duration plus the buffer', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        oecBooked($employee, '09:00', 45);
        $next = oecBooked($employee, '10:30', 30);

        // 09:45 + 30 min + 15 min buffer = 10:30, the start of the next booking.
        expect(oecStarts($service, $employee))->toBe(['09:45', '11:00', '11:15', '11:30']);

        $booking = oecBook($service, $employee, oecAt('09:45'));

        expect($booking->blocked_until->equalTo($next->starts_at))->toBeTrue();
    });

    it('neither offers nor books the gap when it is one minute too short', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        oecBooked($employee, '09:00', 45);
        oecBooked($employee, '10:29', 30);

        expect(oecStarts($service, $employee))->toBe(['11:00', '11:15', '11:30'])
            ->and(oecRefusal($service, $employee, oecAt('09:45')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('is precise to the minute on both sides of a neighbour', function () {
        config(['booking.slot_interval' => 1]);
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        oecBooked($employee, '09:00', 30, buffer: 15);
        oecBooked($employee, '11:30', 30);

        // Before: the earlier booking is blocked until 09:45.
        expect(oecRefusal($service, $employee, oecAt('09:44')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(oecRefusal($service, $employee, oecAt('09:45')))->toBeNull()
            // After: the new buffer must end by 11:30, when the later booking starts.
            ->and(oecRefusal($service, $employee, oecAt('10:46')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(oecRefusal($service, $employee, oecAt('10:45')))->toBeNull();
    });

    it('chains bookings back to back, each starting at the previous blocked_until', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);

        $first = oecBook($service, $employee, oecAt('09:00'));
        $second = oecBook($service, $employee, $first->blocked_until);
        $third = oecBook($service, $employee, $second->blocked_until);

        expect($third->starts_at->equalTo(oecAt('10:30')))->toBeTrue()
            // The last start ends at closing time; its buffer runs past it.
            ->and(oecStarts($service, $employee))->toBe(['11:15', '11:30']);
    });

    it('offers and books a service that fills the whole block exactly', function () {
        $employee = oecEmployee(['09:00-10:00']);
        $exact = oecService($employee, 60, 0);
        $tooLong = oecService($employee, 61, 0);

        expect(oecStarts($exact, $employee))->toBe(['09:00'])
            ->and(oecBook($exact, $employee, oecAt('09:00'))->ends_at->equalTo(oecAt('10:00')))->toBeTrue()
            ->and(oecStarts($tooLong, $employee))->toBe([]);
    });
});

describe('around midnight', function () {
    it('offers the last appointment that ends exactly at midnight, not one that would cross it', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        $service = oecService($employee, 30, 0);

        $slots = oecSlots($service, $employee);
        $last = $slots[array_key_last($slots)];

        expect($slots)->toHaveCount(95)
            ->and($slots[0]->label())->toBe('00:00')
            ->and($last->label())->toBe('23:30')
            ->and($last->endsAt->equalTo(oecAt('00:00', '2026-12-02')))->toBeTrue()
            ->and(oecRefusal($service, $employee, oecAt('23:45')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });

    it('does not join the end of one day to the start of the next for one appointment', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        oecWholeDay($employee, '2026-12-02');
        $service = oecService($employee, 60, 0);

        expect(oecLastStart($service, $employee))->toBe('23:00')
            ->and(oecRefusal($service, $employee, oecAt('23:30')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });

    it('lets the buffer of the last appointment run past midnight', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        $service = oecService($employee, 30, 15);

        $booking = oecBook($service, $employee, oecAt('23:30'));

        expect($booking->ends_at->equalTo(oecAt('00:00', '2026-12-02')))->toBeTrue()
            ->and($booking->blocked_until->equalTo(oecAt('00:15', '2026-12-02')))->toBeTrue();
    });

    it('keeps the start of the next day free for a buffer that runs past midnight', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        oecWholeDay($employee, '2026-12-02');
        $service = oecService($employee, 30, 15);
        oecBook($service, $employee, oecAt('23:30'));

        expect(oecStarts($service, $employee, '2026-12-02')[0])->toBe('00:15')
            ->and(oecRefusal($service, $employee, oecAt('00:00', '2026-12-02')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('does not offer the last slot of the day when its buffer runs into a booking just after midnight', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        oecWholeDay($employee, '2026-12-02');
        $service = oecService($employee, 30, 15);
        oecBooked($employee, '00:00', 30, date: '2026-12-02');

        // 23:15 + 45 min = 00:00 still fits; 23:30 would block until 00:15.
        expect(oecLastStart($service, $employee))->toBe('23:15')
            ->and(oecRefusal($service, $employee, oecAt('23:30')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('sees a booking that runs over midnight on both days', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        oecWholeDay($employee, '2026-12-02');
        $service = oecService($employee, 30, 0);
        oecBooked($employee, '23:30', 60);

        expect(oecLastStart($service, $employee))->toBe('23:00')
            ->and(oecStarts($service, $employee, '2026-12-02')[0])->toBe('00:30');
    });

    it('books a start just after local midnight on its own local date, while UTC is still the day before', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-02');
        $service = oecService($employee, 30, 0);

        $booking = oecBook($service, $employee, oecAt('00:00', '2026-12-02'));

        expect($booking->starts_at->toDateTimeString())->toBe('2026-12-01 23:00:00')
            // The employee does not work on Tuesday, the UTC date of that moment.
            ->and(oecStarts($service, $employee))->toBe([])
            ->and(oecRefusal($service, $employee, oecAt('23:30')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });
});

describe('a service longer than the remaining block', function () {
    it('offers the long service only in the block it fits in', function () {
        $employee = oecEmployee(['09:00-10:00', '13:00-15:00']);
        $service = oecService($employee, 90, 0);

        expect(oecStarts($service, $employee))->toBe(['13:00', '13:15', '13:30'])
            ->and(oecRefusal($service, $employee, oecAt('09:00')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });

    it('offers nothing when an earlier booking leaves less than the duration', function () {
        $employee = oecEmployee();
        oecBooked($employee, '09:00', 105);
        $long = oecService($employee, 90, 0);
        $fits = oecService($employee, 75, 0);

        // 10:45 is free, but 10:45 + 90 min = 12:15 is past closing time.
        expect(oecStarts($long, $employee))->toBe([])
            ->and(oecRefusal($long, $employee, oecAt('10:45')))->toBe(BookingRefused::REASON_NOT_OFFERED)
            ->and(oecStarts($fits, $employee))->toBe(['10:45']);
    });

    it('lets only the buffer run into the break, not the appointment itself', function () {
        $employee = oecEmployee(['09:00-12:00', '13:00-17:00']);
        $service = oecService($employee, 60, 30);

        $starts = oecStarts($service, $employee);

        expect($starts)->toContain('11:00', '13:00', '16:00')
            ->and($starts)->not->toContain('11:15', '11:30', '12:00', '12:30', '16:15')
            ->and(oecRefusal($service, $employee, oecAt('11:15')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });

    it('does not let a buffer in the break run into a booking at the start of the next block', function () {
        $employee = oecEmployee(['09:00-12:00', '12:30-17:00']);
        $service = oecService($employee, 60, 45);
        oecBooked($employee, '12:30', 30);

        $morning = array_values(array_filter(oecStarts($service, $employee), fn (string $start): bool => $start < '12:00'));

        // 10:45 + 60 + 45 = 12:30 just fits; 11:00 would block until 12:45.
        expect($morning[array_key_last($morning)])->toBe('10:45')
            ->and(oecRefusal($service, $employee, oecAt('11:00')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('takes time off into account when the block gets shorter than the service', function () {
        $employee = oecEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '10:30', '12:00')->create();
        $service = oecService($employee, 90, 0);

        expect(oecStarts($service, $employee))->toBe(['09:00'])
            ->and(oecRefusal($service, $employee, oecAt('09:15')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });

    it('offers nothing more today once the rest of the block is shorter than the service', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 75, 0);

        travelTo(oecAt('10:40'));
        expect(oecStarts($service, $employee))->toBe(['10:45']);

        travelTo(oecAt('10:46'));
        expect(oecStarts($service, $employee))->toBe([])
            ->and(oecRefusal($service, $employee, oecAt('10:45')))->toBe(BookingRefused::REASON_NOT_OFFERED);
    });
});

describe('buffer time', function () {
    it('uses the buffer of the new service before an existing booking, and the stored one after it', function () {
        $employee = oecEmployee();
        oecBooked($employee, '10:00', 30, buffer: 0);
        $service = oecService($employee, 30, 30);

        // Before 10:00 a new start needs 30 + 30 min; after 10:30 the old booking has no buffer.
        expect(oecStarts($service, $employee))->toBe(['09:00', '10:30', '10:45', '11:00', '11:15', '11:30'])
            ->and(oecRefusal($service, $employee, oecAt('09:15')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('keeps the buffer a booking was made with after the service buffer changes', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        oecBook($service, $employee, oecAt('10:00'));

        $service->update(['buffer_minutes' => 0]);

        // The old booking is still blocked until 10:45; new starts get no buffer.
        expect(oecStarts($service, $employee))->toBe(['09:00', '09:15', '09:30', '10:45', '11:00', '11:15', '11:30'])
            ->and(oecRefusal($service, $employee, oecAt('10:30')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('does not offer a start whose buffer alone would overlap a booking', function () {
        config(['booking.slot_interval' => 5]);
        $employee = oecEmployee(['09:00-10:00']);
        $service = oecService($employee, 20, 25);
        oecBooked($employee, '10:00', 30);

        // The appointment of 09:20 ends at 09:40, well before 10:00, but its buffer runs until 10:05.
        expect(oecStarts($service, $employee))->toBe(['09:00', '09:05', '09:10', '09:15'])
            ->and(oecRefusal($service, $employee, oecAt('09:20')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });
});

describe('cancelled bookings free the slot', function () {
    it('offers the whole time of a cancelled booking again, buffer and starts inside it included', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        $booking = oecBooked($employee, '10:00', 90, buffer: 15);

        expect(oecStarts($service, $employee))->toBe(['09:00', '09:15']);

        app(CancelBooking::class)->handle($booking->customer, $booking);

        expect(oecStarts($service, $employee))
            ->toBe(['09:00', '09:15', '09:30', '09:45', '10:00', '10:15', '10:30', '10:45', '11:00', '11:15', '11:30']);
    });

    it('lets a new booking start inside or on cancelled ones, and blocks again once rebooked', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        oecBooked($employee, '10:00', 30, status: BookingStatus::Cancelled);
        oecBooked($employee, '10:00', 30, status: BookingStatus::Cancelled);

        $booking = oecBook($service, $employee, oecAt('10:15'));

        expect($booking->status)->toBe(BookingStatus::Requested)
            ->and(oecStarts($service, $employee))->not->toContain('10:00', '10:15', '10:30')
            ->and(oecRefusal($service, $employee, oecAt('10:15')))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY);
    });

    it('frees only the cancelled booking, not its neighbours', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        oecBook($service, $employee, oecAt('09:00'));
        $middle = oecBook($service, $employee, oecAt('09:45'));
        oecBook($service, $employee, oecAt('10:30'));

        expect(oecStarts($service, $employee))->toBe(['11:15', '11:30']);

        app(CancelBooking::class)->handle($middle->customer, $middle);

        // Exactly the 45 minutes between the neighbours come back.
        expect(oecStarts($service, $employee))->toBe(['09:45', '11:15', '11:30']);
    });

    it('frees both days when a booking over midnight is cancelled', function () {
        $employee = oecEmployee([]);
        oecWholeDay($employee, '2026-12-01');
        oecWholeDay($employee, '2026-12-02');
        $service = oecService($employee, 30, 0);
        $booking = oecBooked($employee, '23:30', 60);

        app(CancelBooking::class)->handle($booking->customer, $booking);

        expect(oecLastStart($service, $employee))->toBe('23:30')
            ->and(oecStarts($service, $employee, '2026-12-02')[0])->toBe('00:00');
    });

    it('shows the freed slot in the slots endpoint after cancelling over HTTP', function () {
        $employee = oecEmployee();
        $service = oecService($employee, 30, 15);
        $booking = oecBook($service, $employee, oecAt('10:00'));
        $query = ['service_id' => (string) $service->id, 'employee_id' => (string) $employee->id, 'date' => '2026-12-01'];
        $other = User::factory()->customer()->create();

        $offered = fn (): array => array_column(actingAs($other)->getJson(route('bookings.slots', $query))->assertOk()->json('slots'), 'starts_at');

        expect($offered())->not->toContain('2026-12-01T10:00:00+01:00');

        actingAs($booking->customer)->post(route('bookings.cancel', $booking))->assertSessionHas('status');

        expect($offered())->toContain('2026-12-01T10:00:00+01:00')
            ->and(oecRefusal($service, $employee, oecAt('10:00')))->toBeNull();
    });
});
