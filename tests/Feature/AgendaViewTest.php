<?php

use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\User;
use App\Models\WorkingHour;
use App\Scheduling\Agenda;
use App\Scheduling\AgendaBooking;
use App\Scheduling\AgendaDay;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-21: the agenda of an employee as a day or week grid with bookings,
 * breaks, time off and public holidays. "Now" is Thursday 2026-12-03 10:00
 * in Amsterdam (week 49: Monday 30-11 to Sunday 06-12). The employee works
 * Monday to Friday 09:00-12:30 and 13:30-17:00.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Europe/Amsterdam'));
});

function agendaEmployee(): Employee
{
    $employee = Employee::factory()->create(['display_name' => 'Xavier']);
    foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $weekday) {
        WorkingHour::factory()->for($employee)->on($weekday, '09:00', '12:30')->create();
        WorkingHour::factory()->for($employee)->on($weekday, '13:30', '17:00')->create();
    }

    return $employee;
}

/**
 * A booking at a local wall-clock time (or an ISO moment with offset).
 *
 * @param  array<string, mixed>  $attributes
 */
function agendaBookingAt(Employee $employee, string $start, int $minutes = 30, array $attributes = []): Booking
{
    $startsAt = CarbonImmutable::parse($start, 'Europe/Amsterdam')->utc();

    return Booking::factory()->for($employee)->create([
        'starts_at' => $startsAt,
        'ends_at' => $startsAt->addMinutes($minutes),
        'blocked_until' => $startsAt->addMinutes($minutes),
        'duration_minutes' => $minutes,
        ...$attributes,
    ]);
}

/**
 * @param  array<string, string>  $query
 * @return TestResponse<Response>
 */
function agendaPage(Employee $employee, array $query = [], ?User $as = null): TestResponse
{
    return actingAs($as ?? $employee->user)->get(route('employees.agenda', [$employee, ...$query]));
}

/**
 * @return list<AgendaDay>
 */
function agendaDays(Employee $employee, string $from, int $days): array
{
    return Agenda::for($employee, CarbonImmutable::parse($from, 'Europe/Amsterdam'), $days);
}

describe('week view', function () {
    it('is the default and shows Monday to Sunday around today', function () {
        $employee = agendaEmployee();

        $response = agendaPage($employee)->assertOk()
            ->assertSee('Agenda van Xavier')
            ->assertSee('Week 49: 30-11-2026 t/m 06-12-2026')
            ->assertSeeInOrder(['Maandag 30-11', 'Dinsdag 01-12', 'Woensdag 02-12', 'Donderdag 03-12', 'Vrijdag 04-12', 'Zaterdag 05-12', 'Zondag 06-12'])
            ->assertSee('Er staan geen afspraken in deze week.');

        expect($response->viewData('view'))->toBe('week')
            ->and($response->viewData('previous'))->toBe('2026-11-23')
            ->and($response->viewData('next'))->toBe('2026-12-07')
            ->and(substr_count((string) $response->getContent(), 'data-agenda-day='))->toBe(7);
    });

    it('starts on the Monday of the chosen date, also on a Sunday', function () {
        $employee = agendaEmployee();

        agendaPage($employee, ['date' => '2026-12-13'])->assertOk()
            ->assertSee('Week 50: 07-12-2026 t/m 13-12-2026');
    });

    it('marks today', function () {
        agendaPage(agendaEmployee())->assertOk()
            ->assertSeeInOrder(['data-agenda-day="2026-12-03"', '>vandaag</span>', 'data-agenda-day="2026-12-04"'], false);
    });

    it('links each day to its day view', function () {
        $employee = agendaEmployee();

        agendaPage($employee)->assertOk()
            ->assertSee('href="'.e(route('employees.agenda', [$employee, 'view' => 'day', 'date' => '2026-12-02'])).'"', false);
    });

    it('shows the bookings of the week with time, service, customer and a link', function () {
        $employee = agendaEmployee();
        $anna = User::factory()->customer()->create(['name' => 'Anna de Vries']);
        $booking = agendaBookingAt($employee, '2026-12-01 10:00', 45, ['customer_id' => $anna->id]);

        agendaPage($employee)->assertOk()
            ->assertSee('10:00 - 10:45')
            ->assertSee($booking->service->name)
            ->assertSee('Anna de Vries')
            ->assertSee('href="'.route('bookings.show', $booking).'"', false)
            ->assertDontSee('Er staan geen afspraken in deze week.');
    });

    it('leaves out bookings outside the week, cancelled ones and those of other employees', function () {
        $employee = agendaEmployee();
        $inWeek = agendaBookingAt($employee, '2026-12-06 23:00');
        $before = agendaBookingAt($employee, '2026-11-29 23:30');
        $after = agendaBookingAt($employee, '2026-12-07 00:00');
        $cancelled = agendaBookingAt($employee, '2026-12-02 11:00', attributes: ['status' => BookingStatus::Cancelled]);
        $other = agendaBookingAt(Employee::factory()->create(), '2026-12-02 11:00');

        $response = agendaPage($employee)->assertOk()
            ->assertSee('href="'.route('bookings.show', $inWeek).'"', false);

        foreach ([$before, $after, $cancelled, $other] as $hidden) {
            $response->assertDontSee('href="'.route('bookings.show', $hidden).'"', false);
        }
    });

    it('shows the status of a requested, completed or no-show booking', function () {
        $employee = agendaEmployee();
        agendaBookingAt($employee, '2026-12-01 09:00', attributes: ['status' => BookingStatus::Requested]);
        agendaBookingAt($employee, '2026-12-01 10:00', attributes: ['status' => BookingStatus::Completed]);
        agendaBookingAt($employee, '2026-12-01 11:00', attributes: ['status' => BookingStatus::NoShow]);

        agendaPage($employee)->assertOk()
            ->assertSeeInOrder([BookingStatus::Requested->label(), BookingStatus::Completed->label(), BookingStatus::NoShow->label()]);
    });

    it('shows the break of each working day', function () {
        $response = agendaPage(agendaEmployee())->assertOk();

        expect(substr_count((string) $response->getContent(), 'data-agenda-item="break"'))->toBe(5);
        $response->assertSee('Pauze 12:30 - 13:30');
    });

    it('shows time off for part of a day with its reason, and shortens the break', function () {
        $employee = agendaEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02', '13:00', '15:00')->create(['reason' => 'Tandarts']);

        $days = agendaPage($employee)->assertOk()
            ->assertSee('Afwezig 13:00 - 15:00')
            ->assertSee('Tandarts')
            ->assertSee('Pauze 12:30 - 13:00')
            ->viewData('days');

        expect(array_map(fn ($r) => $r->label(), $days[2]->schedule->ranges))->toBe(['09:00 - 12:30', '15:00 - 17:00']);
    });

    it('shows a whole day of leave in the day header, without working hours or a break', function () {
        $employee = agendaEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-04')->create(['reason' => 'Vakantie']);

        $response = agendaPage($employee)->assertOk()->assertSee('Afwezig, hele dag: Vakantie');
        $friday = $response->viewData('days')[4];

        expect($friday->wholeDayOff()?->reason)->toBe('Vakantie')
            ->and($friday->schedule->ranges)->toBe([])
            ->and($friday->schedule->breaks)->toBe([])
            ->and(substr_count((string) $response->getContent(), 'data-agenda-item="break"'))->toBe(4);
    });

    it('still shows a booking on a day of leave', function () {
        $employee = agendaEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-04')->create();
        $booking = agendaBookingAt($employee, '2026-12-04 10:00');

        agendaPage($employee)->assertOk()->assertSee('href="'.route('bookings.show', $booking).'"', false);
    });

    it('shows a closed public holiday, and extra hours worked on it', function () {
        $employee = agendaEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-26', '10:00', '14:00')->create();

        $response = agendaPage($employee, ['date' => '2026-12-25'])->assertOk()
            ->assertSee('Eerste Kerstdag (gesloten)')
            ->assertSee('Tweede Kerstdag (gesloten)');

        [, , , , $christmas, $boxingDay] = $response->viewData('days');
        expect($christmas->schedule->ranges)->toBe([])
            ->and(array_map(fn ($r) => $r->label(), $boxingDay->schedule->ranges))->toBe(['10:00 - 14:00']);
    });

    it('says a weekend day without hours is free', function () {
        // Saturday and Sunday; "Vrijdag" is not matched because of the tags.
        expect(substr_count((string) agendaPage(agendaEmployee())->assertOk()->getContent(), '>Vrij</p>'))->toBe(2);
    });
});

describe('day view', function () {
    it('shows only the chosen day, with the previous and next day', function () {
        $employee = agendaEmployee();
        $booking = agendaBookingAt($employee, '2026-12-02 14:00');
        $nextDay = agendaBookingAt($employee, '2026-12-03 14:00');

        $response = agendaPage($employee, ['view' => 'day', 'date' => '2026-12-02'])->assertOk()
            ->assertSee('Woensdag 02-12-2026')
            ->assertSee('href="'.route('bookings.show', $booking).'"', false)
            ->assertDontSee('href="'.route('bookings.show', $nextDay).'"', false)
            ->assertSee('Pauze 12:30 - 13:30');

        expect($response->viewData('view'))->toBe('day')
            ->and($response->viewData('days'))->toHaveCount(1)
            ->and($response->viewData('previous'))->toBe('2026-12-01')
            ->and($response->viewData('next'))->toBe('2026-12-03');
    });

    it('defaults to today', function () {
        agendaPage(agendaEmployee(), ['view' => 'day'])->assertOk()->assertSee('Donderdag 03-12-2026');
    });

    it('says when a day has no bookings', function () {
        agendaPage(agendaEmployee(), ['view' => 'day'])->assertOk()->assertSee('Er staan geen afspraken op deze dag.');
    });

    it('marks the current view in the switch and keeps the date when switching', function () {
        $employee = agendaEmployee();

        agendaPage($employee, ['view' => 'day', 'date' => '2026-12-02'])->assertOk()
            ->assertSee('aria-current="page"', false)
            ->assertSee('href="'.e(route('employees.agenda', [$employee, 'view' => 'week', 'date' => '2026-12-02'])).'"', false);
    });
});

describe('query string', function () {
    it('falls back to this week for anything it does not understand', function (array $query) {
        $response = agendaPage(agendaEmployee(), $query)->assertOk()
            ->assertSee('Week 49: 30-11-2026 t/m 06-12-2026');

        expect($response->viewData('view'))->toBe('week');
    })->with([
        'unknown view' => [['view' => 'month']],
        'not a real date' => [['date' => '2026-02-30']],
        'not a date' => [['date' => 'gisteren']],
        'date with time' => [['date' => '2026-12-10 10:00']],
        'date as array' => [['date[]' => '2026-12-10']],
        'before the first date' => [['date' => '1999-12-31']],
        'after the last date' => [['date' => '2100-01-01']],
        // Its week would run into the year 10000, which Carbon cannot read back.
        'last representable date' => [['date' => '9999-12-31']],
        'first representable date' => [['date' => '0001-01-01']],
    ]);

    it('opens the first and last dates it accepts', function (string $date, string $period) {
        agendaPage(agendaEmployee(), ['date' => $date])->assertOk()->assertSee($period);
    })->with([
        ['2000-01-01', 'Week 52: 27-12-1999 t/m 02-01-2000'],
        ['2099-12-31', 'Week 53: 28-12-2099 t/m 03-01-2100'],
    ]);
});

describe('visible hours', function () {
    it('cover the working hours, rounded to whole hours', function () {
        expect(agendaPage(agendaEmployee())->viewData('hours'))->toBe([9 * 60, 17 * 60]);
    });

    it('grow to show a booking outside the working hours', function () {
        $employee = agendaEmployee();
        agendaBookingAt($employee, '2026-12-05 19:15', 30);
        agendaBookingAt($employee, '2026-12-05 07:45', 30);

        expect(agendaPage($employee)->viewData('hours'))->toBe([7 * 60, 20 * 60]);
    });

    it('default to 08:00-18:00 when there is nothing to draw', function () {
        $employee = Employee::factory()->create();

        expect(agendaPage($employee)->viewData('hours'))->toBe(Agenda::DEFAULT_HOURS);
    });

    it('do not grow for a whole day of leave', function () {
        $employee = agendaEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02')->create();

        expect(agendaPage($employee)->viewData('hours'))->toBe([9 * 60, 17 * 60]);
    });
});

describe('Agenda::for', function () {
    it('places a booking across midnight on both days, clipped to each', function () {
        $employee = agendaEmployee();
        agendaBookingAt($employee, '2026-12-01 23:30', 60);

        [$tuesday, $wednesday] = agendaDays($employee, '2026-12-01', 2);

        expect([$tuesday->bookings[0]->start, $tuesday->bookings[0]->end])->toBe([23 * 60 + 30, 1440])
            ->and([$wednesday->bookings[0]->start, $wednesday->bookings[0]->end])->toBe([0, 30])
            ->and($tuesday->bookings[0]->timeLabel())->toBe('23:30 - 00:30');
    });

    it('lists bookings of a day in real time order', function () {
        $employee = agendaEmployee();
        $late = agendaBookingAt($employee, '2026-12-01 15:00');
        $early = agendaBookingAt($employee, '2026-12-01 09:00');

        [$tuesday] = agendaDays($employee, '2026-12-01', 1);

        expect(array_map(fn (AgendaBooking $b): int => $b->booking->id, $tuesday->bookings))->toBe([$early->id, $late->id]);
    });

    it('reads the same number of queries for a day and a week', function () {
        $employee = agendaEmployee();
        foreach (range(0, 6) as $i) {
            agendaBookingAt($employee, CarbonImmutable::parse('2026-11-30 10:00')->addDays($i)->format('Y-m-d H:i'));
            ScheduleException::factory()->for($employee)->unavailable(CarbonImmutable::parse('2026-11-30')->addDays($i)->format('Y-m-d'), '16:00', '17:00')->create();
        }

        $count = function (int $days) use ($employee): int {
            $fresh = Employee::findOrFail($employee->id);
            DB::flushQueryLog();
            DB::enableQueryLog();
            agendaDays($fresh, '2026-11-30', $days);
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        expect($count(7))->toBe($count(1));
    });

    it('does not leave the partial list of exceptions on the employee', function () {
        $employee = agendaEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-20')->create();

        agendaDays($employee, '2026-11-30', 7);

        expect($employee->relationLoaded('scheduleExceptions'))->toBeFalse();
    });

    it('refuses fewer than one day', function () {
        agendaDays(agendaEmployee(), '2026-11-30', 0);
    })->throws(InvalidArgumentException::class);
});

describe('daylight saving time', function () {
    it('places both 02:30s of the October night by wall-clock time, in real order', function () {
        $employee = agendaEmployee();
        agendaBookingAt($employee, '2026-10-25T02:30:00+02:00');
        agendaBookingAt($employee, '2026-10-25T02:30:00+01:00');

        [$sunday] = agendaDays($employee, '2026-10-25', 1);

        expect(array_map(fn (AgendaBooking $b): array => [$b->start, $b->end, $b->timeLabel()], $sunday->bookings))->toBe([
            // Ends at 03:00 summer time = 02:00 winter time: drawn with its real 30 minutes.
            [150, 180, '02:30 (zomertijd) - 02:00 (wintertijd)'],
            [150, 180, '02:30 (wintertijd) - 03:00'],
        ]);
    });

    it('draws a booking across the March jump up to the wall-clock end', function () {
        $employee = agendaEmployee();
        agendaBookingAt($employee, '2026-03-29T01:30:00+01:00', 60);

        [$sunday] = agendaDays($employee, '2026-03-29', 1);

        expect([$sunday->bookings[0]->start, $sunday->bookings[0]->end, $sunday->bookings[0]->timeLabel()])
            ->toBe([90, 210, '01:30 - 03:30']);
    });

    it('shows the 23-hour and 25-hour days in their weeks', function (string $date) {
        $employee = agendaEmployee();
        $booking = agendaBookingAt($employee, $date.' 23:30');

        agendaPage($employee, ['date' => $date])->assertOk()
            ->assertSee('href="'.route('bookings.show', $booking).'"', false);
    })->with(['2026-03-29', '2026-10-25']);
});

describe('access', function () {
    it('sends an employee to their own agenda, keeping the view and date', function () {
        $employee = agendaEmployee();

        actingAs($employee->user)->get(route('agenda', ['view' => 'day', 'date' => '2026-12-02']))
            ->assertRedirect(route('employees.agenda', [$employee, 'view' => 'day', 'date' => '2026-12-02']));
    });

    it('hides another employee\'s agenda as not found, in every view', function (array $query) {
        $xavier = agendaEmployee();
        $yara = agendaEmployee();

        agendaPage($yara, $query, as: $xavier->user)->assertNotFound();
    })->with([
        'week' => [[]],
        'day' => [['view' => 'day', 'date' => '2026-12-02']],
    ]);

    it('shows a booking with another employee nowhere in an agenda', function () {
        $xavier = agendaEmployee();
        $yara = agendaEmployee();
        $yaras = agendaBookingAt($yara, '2026-12-02 10:00');

        agendaPage($xavier)->assertOk()->assertDontSee('href="'.route('bookings.show', $yaras).'"', false);
    });

    it('lets an admin open any agenda from the employee list', function () {
        $employee = agendaEmployee();
        $admin = User::factory()->admin()->create();
        $booking = agendaBookingAt($employee, '2026-12-02 10:00');

        actingAs($admin)->get(route('admin.employees.index'))->assertOk()
            ->assertSee('href="'.route('employees.agenda', $employee).'"', false);
        agendaPage($employee, as: $admin)->assertOk()
            ->assertSee('href="'.route('bookings.show', $booking).'"', false);
    });
});
