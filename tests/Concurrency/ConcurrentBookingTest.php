<?php

use App\Bookings\BookingRefused;
use App\Bookings\CreateBooking;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travelTo;

/*
 * ISSUE-13: two requests for the same slot at the same moment; exactly one
 * may succeed.
 *
 * The race is replayed deterministically on two database connections. The
 * first request runs on the default connection. Right after it has passed
 * every check and inserted its booking, but before it commits, the second
 * request runs to the end on its own connection: the worst possible
 * interleaving. Without the row locks in CreateBooking the second request
 * cannot see the uncommitted booking, passes the same checks and both
 * bookings are stored. With the locks it has to wait for the first request,
 * which cannot commit until the second gives up (one PHP process), so the
 * second request hits the lock wait timeout: proof that it could not get
 * past the checks. Once the first has committed, the second is refused.
 *
 * Only on MySQL/MariaDB: SQLite has no row locks and serialises all writes,
 * which would hide the race instead of proving it is solved
 * (docs/decisions.md). CI runs this in the "Tests (MySQL 8)" job.
 *
 * "Now" is Monday 2026-11-23 10:00 in Amsterdam; the booking date is
 * Tuesday 2026-12-01. Employees work Tuesdays 09:00-12:00; the service
 * takes 30 min + 15 min buffer.
 */

const RACE_CONNECTION = 'second_request';

/** Outcome of the second request when it had to wait for the first one. */
const RACE_WAITED = 'waited_for_lock';

const RACE_BOOKED = 'booked';

function raceSupported(): bool
{
    return in_array(DB::getDriverName(), ['mysql', 'mariadb'], true);
}

beforeEach(function () {
    if (! raceSupported()) {
        $this->markTestSkipped('Needs MySQL/MariaDB row locks; run with DB_CONNECTION=mysql (see docs/decisions.md).');
    }

    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);

    config(['database.connections.'.RACE_CONNECTION => config('database.connections.'.DB::getDefaultConnection())]);
    DB::connection(RACE_CONNECTION)->statement('SET SESSION innodb_lock_wait_timeout = 1');
});

afterEach(function () {
    DB::purge(RACE_CONNECTION);
});

function raceService(): Service
{
    return Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
}

function raceEmployee(Service $service): Employee
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    $employee->services()->attach($service);

    return $employee;
}

function raceStart(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-12-01 {$time}", 'Europe/Amsterdam');
}

/**
 * Books on the default connection: the first request.
 */
function firstRequest(User $customer, Service $service, Employee $employee, string $time): Booking
{
    return app(CreateBooking::class)->handle($customer, $service, $employee, raceStart($time));
}

/**
 * Books on the second connection, with the models loaded there, and
 * reports the outcome: RACE_BOOKED, RACE_WAITED or the BookingRefused
 * reason.
 */
function secondRequest(User $customer, Service $service, Employee $employee, string $time): string
{
    $default = DB::getDefaultConnection();
    DB::setDefaultConnection(RACE_CONNECTION);

    try {
        app(CreateBooking::class)->handle(
            User::query()->findOrFail($customer->id),
            Service::query()->findOrFail($service->id),
            Employee::query()->findOrFail($employee->id),
            raceStart($time),
        );

        return RACE_BOOKED;
    } catch (BookingRefused $refused) {
        return $refused->reason;
    } catch (QueryException $e) {
        // 1205: Lock wait timeout exceeded.
        if (($e->errorInfo[1] ?? null) === 1205) {
            return RACE_WAITED;
        }

        throw $e;
    } finally {
        DB::setDefaultConnection($default);
    }
}

/**
 * Runs $second once, when the first request (default connection) has
 * inserted its booking but not yet committed. Returns a reader for the
 * outcome of $second; null means it never ran.
 *
 * @param  Closure(): string  $second
 * @return Closure(): ?string
 */
function whileFirstRequestCommits(Closure $second): Closure
{
    return afterFirstRequestQuery(
        fn (string $sql): bool => str_starts_with($sql, 'insert into `bookings`'),
        $second,
    );
}

/**
 * Runs $second once, right after the first request (default connection)
 * has locked the customer row: both locks are held, none of the overlap
 * checks has run yet. Same reader as whileFirstRequestCommits().
 *
 * @param  Closure(): string  $second
 * @return Closure(): ?string
 */
function whileFirstRequestHoldsLocks(Closure $second): Closure
{
    return afterFirstRequestQuery(
        fn (string $sql): bool => str_starts_with($sql, 'select') && str_contains($sql, 'from `users`') && str_ends_with($sql, ' for update'),
        $second,
    );
}

/**
 * @param  Closure(string): bool  $matches
 * @param  Closure(): string  $second
 * @return Closure(): ?string
 */
function afterFirstRequestQuery(Closure $matches, Closure $second): Closure
{
    $outcome = null;
    $first = DB::getDefaultConnection();

    DB::listen(function (QueryExecuted $query) use (&$outcome, $first, $matches, $second): void {
        if ($outcome !== null || $query->connectionName !== $first || ! $matches($query->sql)) {
            return;
        }

        $outcome = $second();
    });

    return function () use (&$outcome): ?string {
        return $outcome;
    };
}

describe('simultaneous booking requests', function () {
    it('lets exactly one of two customers book the same employee at the same time', function (string $secondTime) {
        $service = raceService();
        $employee = raceEmployee($service);
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();

        $outcomeForBob = whileFirstRequestCommits(fn () => secondRequest($bob, $service, $employee, $secondTime));
        firstRequest($alice, $service, $employee, '09:15');

        // Bob could not get past the checks while Alice's booking was in flight.
        expect($outcomeForBob())->toBe(RACE_WAITED)
            ->and(Booking::count())->toBe(1)
            ->and(Booking::sole()->customer_id)->toBe($alice->id);

        // Once Alice's booking is committed, Bob's retry is refused.
        expect(secondRequest($bob, $service, $employee, $secondTime))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(Booking::count())->toBe(1);
    })->with([
        'the same start' => '09:15',
        'an overlapping start' => '09:30',
    ]);

    it('lets one customer book only one of two employees at the same time', function () {
        $service = raceService();
        $first = raceEmployee($service);
        $second = raceEmployee($service);
        $customer = User::factory()->customer()->create();

        $outcomeOnSecond = whileFirstRequestCommits(fn () => secondRequest($customer, $service, $second, '09:15'));
        firstRequest($customer, $service, $first, '09:15');

        expect($outcomeOnSecond())->toBe(RACE_WAITED)
            ->and(Booking::count())->toBe(1)
            ->and(Booking::sole()->employee_id)->toBe($first->id);

        expect(secondRequest($customer, $service, $second, '09:15'))->toBe(BookingRefused::REASON_CUSTOMER_BUSY)
            ->and(Booking::count())->toBe(1);
    });

    it('does not hold up a request for another employee and customer', function () {
        $service = raceService();
        $first = raceEmployee($service);
        $second = raceEmployee($service);
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();

        $outcomeForBob = whileFirstRequestCommits(fn () => secondRequest($bob, $service, $second, '09:15'));
        firstRequest($alice, $service, $first, '09:15');

        expect($outcomeForBob())->toBe(RACE_BOOKED)
            ->and(Booking::count())->toBe(2);
    });

    it('holds the second request back from the moment the first has its locks, before any check', function () {
        $service = raceService();
        $employee = raceEmployee($service);
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();

        $outcomeForBob = whileFirstRequestHoldsLocks(fn () => secondRequest($bob, $service, $employee, '09:15'));
        firstRequest($alice, $service, $employee, '09:15');

        expect($outcomeForBob())->toBe(RACE_WAITED)
            ->and(Booking::sole()->customer_id)->toBe($alice->id);
    });

    it('releases the locks when the first request is refused, so the waiting request can book', function () {
        $service = raceService();
        $employee = raceEmployee($service);
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();

        // Alice asks for 08:00, before the working hours: refused after the locks.
        $outcomeForBob = whileFirstRequestHoldsLocks(fn () => secondRequest($bob, $service, $employee, '09:15'));

        expect(fn () => firstRequest($alice, $service, $employee, '08:00'))
            ->toThrow(fn (BookingRefused $refused) => expect($refused->reason)->toBe(BookingRefused::REASON_NOT_OFFERED));
        expect($outcomeForBob())->toBe(RACE_WAITED)
            ->and(Booking::count())->toBe(0);

        // Alice's transaction is rolled back; Bob's retry gets through.
        expect(secondRequest($bob, $service, $employee, '09:15'))->toBe(RACE_BOOKED)
            ->and(Booking::sole()->customer_id)->toBe($bob->id);
    });

    it('serialises but does not refuse a non-overlapping slot with the same employee', function () {
        $service = raceService();
        $employee = raceEmployee($service);
        $alice = User::factory()->customer()->create();
        $bob = User::factory()->customer()->create();

        // 09:15-09:45 + 15 min buffer ends at 10:00; Bob wants 10:30.
        $outcomeForBob = whileFirstRequestCommits(fn () => secondRequest($bob, $service, $employee, '10:30'));
        firstRequest($alice, $service, $employee, '09:15');

        // The lock is per employee, so Bob still waits for Alice to commit...
        expect($outcomeForBob())->toBe(RACE_WAITED)
            ->and(Booking::count())->toBe(1);

        // ...and is then booked, not refused.
        expect(secondRequest($bob, $service, $employee, '10:30'))->toBe(RACE_BOOKED)
            ->and(Booking::count())->toBe(2);
    });

    it('refuses a double-click: the same customer, employee and slot twice', function () {
        $service = raceService();
        $employee = raceEmployee($service);
        $customer = User::factory()->customer()->create();

        $outcomeOfRetry = whileFirstRequestCommits(fn () => secondRequest($customer, $service, $employee, '09:15'));
        firstRequest($customer, $service, $employee, '09:15');

        expect($outcomeOfRetry())->toBe(RACE_WAITED)
            ->and(Booking::count())->toBe(1);

        // Employee is checked before customer, so that is the reason given.
        expect(secondRequest($customer, $service, $employee, '09:15'))->toBe(BookingRefused::REASON_EMPLOYEE_BUSY)
            ->and(Booking::count())->toBe(1);
    });
});
