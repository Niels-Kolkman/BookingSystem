<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use App\Scheduling\Occupancy;
use Carbon\CarbonImmutable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-22: the admin overview of bookings with filters, occupancy and
 * no-shows. "Now" is Thursday 2026-12-03 10:00 in Amsterdam. The week used
 * is Monday 07-12 to Friday 11-12, no holidays. Employees work Monday to
 * Friday 09:00-17:00: 5 x 480 = 2400 minutes a week.
 */

const OVERVIEW_WEEK = ['from' => '2026-12-07', 'until' => '2026-12-11'];

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Europe/Amsterdam'));
});

function overviewEmployee(string $name, bool $active = true): Employee
{
    $employee = Employee::factory()->create(['display_name' => $name, 'is_active' => $active]);
    foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $weekday) {
        WorkingHour::factory()->for($employee)->on($weekday, '09:00', '17:00')->create();
    }

    return $employee;
}

/**
 * A booking at a local wall-clock time.
 *
 * @param  array<string, mixed>  $attributes
 */
function overviewBooking(Employee $employee, string $start, int $minutes, BookingStatus $status, array $attributes = []): Booking
{
    $startsAt = CarbonImmutable::parse($start, 'Europe/Amsterdam')->utc();

    return Booking::factory()->for($employee)->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes($minutes),
        'blocked_until' => $startsAt->addMinutes($minutes),
        'duration_minutes' => $minutes,
        'status' => $status,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, string|int>  $query
 * @return TestResponse<Response>
 */
function overviewPage(array $query = []): TestResponse
{
    return actingAs(User::factory()->admin()->create())->get(route('admin.bookings.index', $query));
}

/**
 * Xavier: 120 confirmed + 60 completed + 60 no-show + 60 cancelled minutes
 * on Monday and Tuesday: 240 of 2400 minutes booked, 1 no-show of 2
 * finished appointments. Yara: one completed booking of a whole day.
 *
 * @return array{xavier: Employee, yara: Employee}
 */
function overviewSetup(): array
{
    $xavier = overviewEmployee('Xavier');
    $yara = overviewEmployee('Yara');

    overviewBooking($xavier, '2026-12-07 09:00', 120, BookingStatus::Confirmed, ['service_id' => Service::factory()->create(['name' => 'Pony bijwerken'])]);
    overviewBooking($xavier, '2026-12-07 13:00', 60, BookingStatus::Completed);
    overviewBooking($xavier, '2026-12-08 10:00', 60, BookingStatus::NoShow, ['service_id' => Service::factory()->create(['name' => 'Verven'])]);
    overviewBooking($xavier, '2026-12-08 14:00', 60, BookingStatus::Cancelled, ['service_id' => Service::factory()->create(['name' => 'Wassen'])]);
    overviewBooking($yara, '2026-12-09 09:00', 480, BookingStatus::Completed, ['service_id' => Service::factory()->create(['name' => 'Permanent'])]);

    return ['xavier' => $xavier, 'yara' => $yara];
}

describe('access', function () {
    it('is only for admins', function () {
        $employee = overviewEmployee('Xavier');

        get(route('admin.bookings.index'))->assertRedirect(route('login'));
        actingAs(User::factory()->customer()->create())->get(route('admin.bookings.index'))->assertForbidden();
        actingAs($employee->user)->get(route('admin.bookings.index'))->assertForbidden();
        overviewPage()->assertOk()->assertSee('Boekingenoverzicht');
    });

    it('is linked from the admin dashboard', function () {
        actingAs(User::factory()->admin()->create())->get(route('admin.dashboard'))
            ->assertSee(route('admin.bookings.index'));
    });
});

describe('statistics', function () {
    it('shows occupancy, no-shows and counts per status for the period', function () {
        ['xavier' => $xavier] = overviewSetup();

        overviewPage([...OVERVIEW_WEEK, 'employee' => $xavier->id])
            ->assertOk()
            ->assertViewHas('totalOccupancy', new Occupancy(2400, 240))
            ->assertViewHas('noShowRate', 0.5)
            ->assertViewHas('bookingCount', 4)
            ->assertViewHas('perStatus', [
                'requested' => 0, 'confirmed' => 1, 'cancelled' => 1, 'completed' => 1, 'no_show' => 1,
            ])
            ->assertSeeInOrder(['Bezettingsgraad', '10%', '4,0 u geboekt van 40,0 u werktijd'])
            ->assertSeeInOrder(['No-shows', '50%', '1 van 2 afgeronde afspraken']);
    });

    it('adds up the active employees without an employee filter and lists them', function () {
        overviewSetup();
        overviewEmployee('Zeno', active: false);

        $response = overviewPage(OVERVIEW_WEEK)
            ->assertViewHas('totalOccupancy', new Occupancy(4800, 720))
            ->assertViewHas('noShowRate', 1 / 3)
            ->assertSee('15%')
            ->assertSeeInOrder(['Bezettingsgraad per medewerker', 'Xavier', '10%', 'Yara', '20%']);

        /** @var list<array{employee: Employee, occupancy: Occupancy}> $rows */
        $rows = $response->viewData('occupancy');
        expect(array_map(fn (array $row): string => $row['employee']->display_name, $rows))
            ->toBe(['Xavier', 'Yara']);
    });

    it('measures an inactive employee when filtered on', function () {
        $zeno = overviewEmployee('Zeno', active: false);
        overviewBooking($zeno, '2026-12-07 09:00', 240, BookingStatus::Completed);

        overviewPage([...OVERVIEW_WEEK, 'employee' => $zeno->id])
            ->assertViewHas('totalOccupancy', new Occupancy(2400, 240));
    });

    it('ignores the status filter', function () {
        ['xavier' => $xavier] = overviewSetup();

        overviewPage([...OVERVIEW_WEEK, 'employee' => $xavier->id, 'status' => 'completed'])
            ->assertViewHas('totalOccupancy', new Occupancy(2400, 240))
            ->assertViewHas('noShowRate', 0.5)
            ->assertViewHas('bookingCount', 4)
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->total() === 1);
    });

    it('takes time off and holidays off the working time', function () {
        $xavier = overviewEmployee('Xavier');
        ScheduleException::factory()->for($xavier)->unavailable('2026-12-07')->create();
        ScheduleException::factory()->for($xavier)->unavailable('2026-12-08', '09:00', '13:00')->create();

        overviewPage(['from' => '2026-12-07', 'until' => '2026-12-08', 'employee' => $xavier->id])
            ->assertViewHas('totalOccupancy', new Occupancy(240, 0));
        // Christmas, Friday 25-12, is a closed holiday.
        overviewPage(['from' => '2026-12-21', 'until' => '2026-12-25', 'employee' => $xavier->id])
            ->assertViewHas('totalOccupancy', new Occupancy(4 * 480, 0));
    });

    it('counts booked time outside working hours not and overlaps once', function () {
        $xavier = overviewEmployee('Xavier');
        overviewBooking($xavier, '2026-12-07 08:00', 120, BookingStatus::Confirmed); // only 09:00-10:00 counts
        overviewBooking($xavier, '2026-12-07 09:30', 60, BookingStatus::Requested);  // overlaps, +30
        overviewBooking($xavier, '2026-12-12 10:00', 60, BookingStatus::Confirmed);  // Saturday, no working time

        overviewPage(['from' => '2026-12-07', 'until' => '2026-12-12', 'employee' => $xavier->id])
            ->assertViewHas('totalOccupancy', new Occupancy(2400, 90));
    });

    it('shows a dash without working time or finished appointments', function () {
        overviewPage(['from' => '2026-12-12', 'until' => '2026-12-13'])
            ->assertViewHas('totalOccupancy', Occupancy::none())
            ->assertViewHas('noShowRate', null)
            ->assertSeeInOrder(['Bezettingsgraad', '–', 'No-shows', '–']);
    });
});

describe('list', function () {
    it('lists the bookings of the period in time order with their details', function () {
        overviewSetup();
        overviewBooking(overviewEmployee('Wim'), '2026-12-14 10:00', 30, BookingStatus::Confirmed, ['service_id' => Service::factory()->create(['name' => 'Volgende-week-dienst'])]);

        overviewPage(OVERVIEW_WEEK)
            ->assertSeeInOrder(['07-12-2026 09:00', 'Pony bijwerken', 'Xavier', 'bevestigd', '08-12-2026 10:00', 'Verven', 'niet verschenen', '09-12-2026 09:00', 'Permanent', 'Yara'])
            ->assertSee('Wassen')
            ->assertDontSee('Volgende-week-dienst');
    });

    it('filters on employee and status', function () {
        ['yara' => $yara] = overviewSetup();

        overviewPage([...OVERVIEW_WEEK, 'status' => 'no_show'])
            ->assertSee('Verven')->assertDontSee('Pony bijwerken')->assertDontSee('Permanent');
        overviewPage([...OVERVIEW_WEEK, 'employee' => $yara->id])
            ->assertSee('Permanent')->assertDontSee('Pony bijwerken');
    });

    it('uses local dates for the period', function () {
        $xavier = overviewEmployee('Xavier');
        // 00:30 local on 07-12 is 23:30 UTC on 06-12.
        overviewBooking($xavier, '2026-12-07 00:30', 30, BookingStatus::Confirmed, ['service_id' => Service::factory()->create(['name' => 'Nachtknip'])]);
        overviewBooking($xavier, '2026-12-11 23:30', 30, BookingStatus::Confirmed, ['service_id' => Service::factory()->create(['name' => 'Laatknip'])]);
        overviewBooking($xavier, '2026-12-12 00:00', 30, BookingStatus::Confirmed, ['service_id' => Service::factory()->create(['name' => 'Zaterdagknip'])]);

        overviewPage(OVERVIEW_WEEK)
            ->assertSee('Nachtknip')->assertSee('Laatknip')->assertDontSee('Zaterdagknip');
    });

    it('pages and keeps the filters in the links', function () {
        $xavier = overviewEmployee('Xavier');
        for ($i = 0; $i < 26; $i++) {
            overviewBooking($xavier, '2026-12-07 09:00', 10, BookingStatus::Confirmed);
        }

        overviewPage([...OVERVIEW_WEEK, 'status' => 'confirmed'])
            ->assertViewHas('bookings', fn ($bookings): bool => $bookings->count() === 25 && $bookings->total() === 26)
            ->assertSee('status=confirmed&amp;page=2', false);
    });

    it('says so when nothing matches', function () {
        overviewPage(OVERVIEW_WEEK)->assertSee('Er zijn geen boekingen die aan de filters voldoen.');
    });
});

describe('filter input', function () {
    it('defaults to this month, all employees and statuses', function () {
        overviewPage()
            ->assertViewHas('from', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2026-12-01')
            ->assertViewHas('until', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2026-12-31')
            ->assertViewHas('employee', null)
            ->assertViewHas('status', null)
            ->assertSee('Statistiek 01-12-2026 t/m 31-12-2026');
    });

    it('keeps the chosen filters selected in the form', function () {
        ['xavier' => $xavier] = overviewSetup();

        overviewPage([...OVERVIEW_WEEK, 'employee' => $xavier->id, 'status' => 'no_show'])
            ->assertSee('value="2026-12-07"', false)
            ->assertSee('value="2026-12-11"', false)
            ->assertSee('<option value="'.$xavier->id.'" selected>Xavier</option>', false)
            ->assertSee('<option value="no_show" selected>', false);
    });

    it('ignores unreadable input', function (array $query) {
        overviewPage($query)
            ->assertOk()
            ->assertViewHas('from', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2026-12-01')
            ->assertViewHas('employee', null)
            ->assertViewHas('status', null);
    })->with([
        'nonsense' => [['from' => 'gisteren', 'employee' => 'abc', 'status' => 'lost']],
        'no real date' => [['from' => '2026-02-30', 'employee' => '999']],
        'out of range' => [['from' => '1999-12-31']],
        'arrays' => [['from' => ['2026-12-07'], 'employee' => ['1'], 'status' => ['confirmed']]],
    ]);

    it('swaps a reversed period', function () {
        overviewPage(['from' => '2026-12-11', 'until' => '2026-12-07'])
            ->assertViewHas('from', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2026-12-07')
            ->assertViewHas('until', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2026-12-11');
    });

    it('cuts a long period off after MAX_DAYS and says so', function () {
        overviewPage(['from' => '2026-01-01', 'until' => '2028-12-31'])
            ->assertViewHas('until', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2027-01-01')
            ->assertViewHas('clipped', true)
            ->assertSee('Een periode is maximaal 366 dagen');
    });

    it('counts calendar days over a DST change', function () {
        // 25-10-2026 lasts 25 hours; the period is still 3 days.
        $xavier = overviewEmployee('Xavier');

        overviewPage(['from' => '2026-10-24', 'until' => '2026-10-26', 'employee' => $xavier->id])
            ->assertViewHas('until', fn (CarbonImmutable $d): bool => $d->format('Y-m-d') === '2026-10-26')
            ->assertViewHas('clipped', false)
            ->assertViewHas('totalOccupancy', new Occupancy(480, 0));
    });

    it('escapes names', function () {
        overviewBooking(overviewEmployee('<b>Xavier</b>'), '2026-12-07 10:00', 30, BookingStatus::Confirmed);

        overviewPage(OVERVIEW_WEEK)->assertSee('&lt;b&gt;Xavier&lt;/b&gt;', false)->assertDontSee('<b>Xavier</b>', false);
    });
});
