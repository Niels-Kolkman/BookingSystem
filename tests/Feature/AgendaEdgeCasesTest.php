<?php

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
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-21, edge cases on top of AgendaViewTest: the navigation as rendered,
 * navigation over the daylight saving nights, "today" just after local
 * midnight, bookings that span days or end on midnight, the order and kind
 * of time off, rounding of the visible hours and escaping. "Now" is Thursday
 * 2026-12-03 10:00 in Amsterdam unless a test travels elsewhere; the
 * employee works Monday to Friday 09:00-17:00 without a break.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-12-03 10:00', 'Europe/Amsterdam'));
});

function agendaEdgeEmployee(): Employee
{
    $employee = Employee::factory()->create(['display_name' => 'Xavier']);
    foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $weekday) {
        WorkingHour::factory()->for($employee)->on($weekday, '09:00', '17:00')->create();
    }

    return $employee;
}

/**
 * @param  array<string, mixed>  $attributes
 */
function agendaEdgeBooking(Employee $employee, string $start, int $minutes = 30, array $attributes = []): Booking
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
function agendaEdgePage(Employee $employee, array $query = []): TestResponse
{
    return actingAs($employee->user)->get(route('employees.agenda', [$employee, ...$query]));
}

/**
 * @return list<AgendaDay>
 */
function agendaEdgeDays(Employee $employee, string $from, int $days): array
{
    return Agenda::for($employee, CarbonImmutable::parse($from, 'Europe/Amsterdam'), $days);
}

/**
 * @return list<array{int, int}>
 */
function agendaEdgePlaces(AgendaDay $day): array
{
    return array_map(fn (AgendaBooking $b): array => [$b->start, $b->end], $day->bookings);
}

function agendaEdgeLink(Employee $employee, string $view, string $date): string
{
    return 'href="'.e(route('employees.agenda', [$employee, 'view' => $view, 'date' => $date])).'"';
}

describe('navigation as rendered', function () {
    it('links to the previous, current and next week in the week view', function () {
        $employee = agendaEdgeEmployee();

        agendaEdgePage($employee, ['date' => '2026-12-16'])->assertOk()
            ->assertSeeInOrder([
                agendaEdgeLink($employee, 'week', '2026-12-07'),
                'Vorige week',
                agendaEdgeLink($employee, 'week', '2026-12-03'),
                'Vandaag',
                agendaEdgeLink($employee, 'week', '2026-12-21'),
                'Volgende week',
            ], false);
    });

    it('links to the previous, current and next day in the day view', function () {
        $employee = agendaEdgeEmployee();

        agendaEdgePage($employee, ['view' => 'day', 'date' => '2026-12-16'])->assertOk()
            ->assertSeeInOrder([
                agendaEdgeLink($employee, 'day', '2026-12-15'),
                'Vorige dag',
                agendaEdgeLink($employee, 'day', '2026-12-03'),
                'Vandaag',
                agendaEdgeLink($employee, 'day', '2026-12-17'),
                'Volgende dag',
            ], false);
    });

    it('keeps the view and the chosen date in the go-to-date form', function () {
        $employee = agendaEdgeEmployee();

        agendaEdgePage($employee, ['view' => 'day', 'date' => '2026-12-16'])->assertOk()
            ->assertSee('action="'.route('employees.agenda', $employee).'"', false)
            ->assertSee('name="view" value="day"', false)
            ->assertSee('type="date" value="2026-12-16"', false);
    });

    it('steps over the turn of the year', function () {
        $employee = agendaEdgeEmployee();

        $day = agendaEdgePage($employee, ['view' => 'day', 'date' => '2027-01-01'])->assertOk();
        $week = agendaEdgePage($employee, ['date' => '2026-12-31'])->assertOk()
            ->assertSee('Week 53: 28-12-2026 t/m 03-01-2027');

        expect([$day->viewData('previous'), $day->viewData('next')])->toBe(['2026-12-31', '2027-01-02'])
            ->and([$week->viewData('previous'), $week->viewData('next')])->toBe(['2026-12-21', '2027-01-04']);
    });

    it('does not mark a day as today in another week', function () {
        agendaEdgePage(agendaEdgeEmployee(), ['date' => '2026-12-10'])->assertOk()
            ->assertDontSee('>vandaag</span>', false);
    });
});

describe('navigation over daylight saving time', function () {
    it('lists seven calendar days in the weeks with a 23-hour or 25-hour Sunday', function (string $date, array $expected, string $previous, string $next) {
        $response = agendaEdgePage(agendaEdgeEmployee(), ['date' => $date])->assertOk();

        expect(array_map(fn (AgendaDay $d): string => $d->date()->format('Y-m-d'), $response->viewData('days')))->toBe($expected)
            ->and($response->viewData('previous'))->toBe($previous)
            ->and($response->viewData('next'))->toBe($next);
    })->with([
        'March' => ['2026-03-29', ['2026-03-23', '2026-03-24', '2026-03-25', '2026-03-26', '2026-03-27', '2026-03-28', '2026-03-29'], '2026-03-16', '2026-03-30'],
        'October' => ['2026-10-25', ['2026-10-19', '2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24', '2026-10-25'], '2026-10-12', '2026-10-26'],
    ]);

    it('steps one calendar day at a time around the nights the clock changes', function (string $date, string $previous, string $next) {
        $response = agendaEdgePage(agendaEdgeEmployee(), ['view' => 'day', 'date' => $date])->assertOk();

        expect([$response->viewData('previous'), $response->viewData('next')])->toBe([$previous, $next]);
    })->with([
        'March, the short day' => ['2026-03-29', '2026-03-28', '2026-03-30'],
        'March, the day after' => ['2026-03-30', '2026-03-29', '2026-03-31'],
        'October, the long day' => ['2026-10-25', '2026-10-24', '2026-10-26'],
        'October, the day after' => ['2026-10-26', '2026-10-25', '2026-10-27'],
    ]);

    it('opens the right week on a Monday whose midnight lies right after a change', function () {
        // Monday 30-03 00:00 is the first midnight in summer time.
        agendaEdgePage(agendaEdgeEmployee(), ['date' => '2026-03-30'])->assertOk()
            ->assertSee('Week 14: 30-03-2026 t/m 05-04-2026');
    });
});

describe('today in Amsterdam', function () {
    it('is the local date just after midnight, while it is still yesterday in UTC', function () {
        // 23:30 UTC on Thursday is 00:30 on Friday in Amsterdam.
        travelTo(CarbonImmutable::parse('2026-12-03 23:30', 'UTC'));

        $response = agendaEdgePage(agendaEdgeEmployee(), ['view' => 'day'])->assertOk()
            ->assertSee('Vrijdag 04-12-2026');

        expect($response->viewData('today'))->toBe('2026-12-04')
            ->and($response->viewData('days')[0]->isToday())->toBeTrue();
    });

    it('starts the new week on Monday at 00:30 local time, still Sunday in UTC', function () {
        travelTo(CarbonImmutable::parse('2026-11-29 23:30', 'UTC'));

        agendaEdgePage(agendaEdgeEmployee())->assertOk()
            ->assertSee('Week 49: 30-11-2026 t/m 06-12-2026')
            ->assertSeeInOrder(['data-agenda-day="2026-11-30"', '>vandaag</span>', 'data-agenda-day="2026-12-01"'], false);
    });
});

describe('bookings on the edges of a day', function () {
    it('fills a whole day for a booking that runs over it', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-01 22:00', 28 * 60);

        [$tuesday, $wednesday, $thursday, $friday] = agendaEdgeDays($employee, '2026-12-01', 4);

        expect(agendaEdgePlaces($tuesday))->toBe([[22 * 60, 1440]])
            ->and(agendaEdgePlaces($wednesday))->toBe([[0, 1440]])
            ->and(agendaEdgePlaces($thursday))->toBe([[0, 2 * 60]])
            ->and($friday->bookings)->toBe([]);
    });

    it('keeps a booking that ends on midnight on its own day only', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-01 23:30', 30);

        [$tuesday, $wednesday] = agendaEdgeDays($employee, '2026-12-01', 2);

        expect(agendaEdgePlaces($tuesday))->toBe([[23 * 60 + 30, 1440]])
            ->and($wednesday->bookings)->toBe([]);
    });

    it('keeps a booking that starts on midnight on its own day only', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-02 00:00', 30);

        [$tuesday, $wednesday] = agendaEdgeDays($employee, '2026-12-01', 2);

        expect($tuesday->bookings)->toBe([])
            ->and(agendaEdgePlaces($wednesday))->toBe([[0, 30]]);
    });

    it('stretches the visible hours to midnight for a booking until midnight', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-01 23:00', 60);

        $response = agendaEdgePage($employee)->assertOk();

        expect($response->viewData('hours'))->toBe([9 * 60, 24 * 60]);
        $response->assertSee('23:00')->assertDontSee('24:00');
    });

    it('draws a booking over the October change by wall clock when its end still lies after its start', function () {
        // 01:45 summer time + 90 minutes = 02:15 winter time: drawn 01:45-02:15,
        // shorter than its real 90 minutes; the label shows the real times.
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-10-25T01:45:00+02:00', 90);

        [$sunday] = agendaEdgeDays($employee, '2026-10-25', 1);

        expect(agendaEdgePlaces($sunday))->toBe([[105, 135]])
            ->and($sunday->bookings[0]->timeLabel())->toBe('01:45 - 02:15 (wintertijd)');
    });
});

describe('time off', function () {
    it('lists the time off of a day in time order, whatever the order it was entered in', function () {
        $employee = agendaEdgeEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02', '15:00', '16:00')->create(['reason' => 'Later']);
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02', '10:00', '11:00')->create(['reason' => 'Eerder']);

        [$wednesday] = agendaEdgeDays($employee, '2026-12-02', 1);

        expect(array_map(fn (ScheduleException $e): ?string => $e->reason, $wednesday->partialTimeOff()))->toBe(['Eerder', 'Later']);
        agendaEdgePage($employee)->assertOk()->assertSeeInOrder(['Afwezig 10:00 - 11:00', 'Eerder', 'Afwezig 15:00 - 16:00', 'Later']);
    });

    it('does not count extra hours as time off', function () {
        $employee = agendaEdgeEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-05', '10:00', '12:00')->create();

        [$saturday] = agendaEdgeDays($employee, '2026-12-05', 1);

        expect($saturday->timeOff)->toBe([])
            ->and($saturday->wholeDayOff())->toBeNull()
            ->and(array_map(fn ($r) => $r->label(), $saturday->schedule->ranges))->toBe(['10:00 - 12:00']);
    });

    it('tells a whole day of leave apart from time off for part of the same day', function () {
        $employee = agendaEdgeEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02', '10:00', '11:00')->create(['reason' => 'Tandarts']);
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02')->create(['reason' => 'Vakantie']);

        [$wednesday] = agendaEdgeDays($employee, '2026-12-02', 1);

        expect($wednesday->wholeDayOff()?->reason)->toBe('Vakantie')
            ->and(array_map(fn (ScheduleException $e): ?string => $e->reason, $wednesday->partialTimeOff()))->toBe(['Tandarts']);
    });

    it('only puts time off on its own date', function () {
        $employee = agendaEdgeEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02')->create();

        $days = agendaEdgeDays($employee, '2026-11-30', 7);

        expect(array_map(fn (AgendaDay $d): int => count($d->timeOff), $days))->toBe([0, 0, 1, 0, 0, 0, 0]);
    });

    it('leaves out the time off of another employee', function () {
        $employee = agendaEdgeEmployee();
        ScheduleException::factory()->for(agendaEdgeEmployee())->unavailable('2026-12-02')->create(['reason' => 'Vakantie van een ander']);

        agendaEdgePage($employee)->assertOk()->assertDontSee('Vakantie van een ander');
    });

    it('shows a day of leave without a reason', function () {
        $employee = agendaEdgeEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02')->create(['reason' => null]);

        agendaEdgePage($employee)->assertOk()
            ->assertSee('Afwezig, hele dag</p>', false)
            ->assertDontSee('Afwezig, hele dag:');
    });
});

describe('visible hours', function () {
    it('round a start and an end inside an hour outwards', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-05 08:59', 2);
        agendaEdgeBooking($employee, '2026-12-05 17:00', 1);

        expect(agendaEdgePage($employee)->viewData('hours'))->toBe([8 * 60, 18 * 60]);
    });

    it('do not add an hour for an end on a whole hour', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-05 17:00', 60);

        expect(agendaEdgePage($employee)->viewData('hours'))->toBe([9 * 60, 18 * 60]);
    });

    it('fit the day shown, not the rest of the week, in the day view', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-05 20:00', 60);

        expect(agendaEdgePage($employee, ['view' => 'day', 'date' => '2026-12-02'])->viewData('hours'))->toBe([9 * 60, 17 * 60])
            ->and(agendaEdgePage($employee, ['date' => '2026-12-02'])->viewData('hours'))->toBe([9 * 60, 21 * 60]);
    });

    it('can be worked out for no days at all', function () {
        expect(Agenda::hourWindow([]))->toBe(Agenda::DEFAULT_HOURS);
    });
});

describe('output', function () {
    it('escapes the reason of time off, the customer and the employee name', function () {
        $employee = agendaEdgeEmployee();
        $employee->update(['display_name' => '<b>Xavier</b>']);
        $customer = User::factory()->customer()->create(['name' => '<script>alert(1)</script>']);
        agendaEdgeBooking($employee, '2026-12-01 10:00', attributes: ['customer_id' => $customer->id]);
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02', '10:00', '11:00')->create(['reason' => '<img src=x onerror=alert(2)>']);

        agendaEdgePage($employee)->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertDontSee('<img src=x onerror=alert(2)>', false)
            ->assertDontSee('<b>Xavier</b>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    });

    it('draws one booking block per day a booking falls on', function () {
        $employee = agendaEdgeEmployee();
        agendaEdgeBooking($employee, '2026-12-01 23:30', 60);

        $content = (string) agendaEdgePage($employee)->assertOk()->getContent();

        expect(substr_count($content, 'data-agenda-item="booking"'))->toBe(2);
    });
});

describe('own agenda redirect', function () {
    it('drops a view or date sent as an array', function () {
        $employee = agendaEdgeEmployee();

        actingAs($employee->user)->get(route('agenda').'?view[]=day&date[]=2026-12-02')
            ->assertRedirect(route('employees.agenda', $employee));
    });

    it('keeps only a date when no view is given', function () {
        $employee = agendaEdgeEmployee();

        actingAs($employee->user)->get(route('agenda', ['date' => '2026-12-02']))
            ->assertRedirect(route('employees.agenda', [$employee, 'date' => '2026-12-02']));
    });
});
