<?php

use App\Bookings\BookingRefused;
use App\Bookings\CreateBooking;
use App\Enums\Weekday;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

use function Pest\Laravel\travelTo;

/*
 * ISSUE-13: the shape of the locking in CreateBooking, checked on every
 * driver. tests/Concurrency proves the race is solved, but only on MySQL;
 * these tests keep the preconditions for that proof under watch in the
 * default (SQLite) run as well:
 * - the employee row and then the customer row are the first queries of
 *   the booking transaction (InnoDB takes its snapshot at the first plain
 *   read, and a fixed order avoids deadlocks);
 * - on MySQL/MariaDB those queries are SELECT ... FOR UPDATE;
 * - a refused request does not leave its transaction (and locks) open.
 *
 * "Now" is Monday 2026-11-23 10:00 in Amsterdam; the booking date is
 * Tuesday 2026-12-01. The employee works Tuesdays 09:00-12:00.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15]);
});

/**
 * @return array{Employee, Service, User}
 */
function lockScenario(): array
{
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    $employee->services()->attach($service);

    return [$employee, $service, User::factory()->customer()->create()];
}

/**
 * Runs $attempt and returns the SQL of every query run inside the
 * transaction CreateBooking opens (a savepoint under RefreshDatabase).
 *
 * @return list<string>
 */
function queriesInsideBookingTransaction(Closure $attempt): array
{
    $inTransaction = false;
    $queries = [];

    Event::listen(TransactionBeginning::class, function () use (&$inTransaction): void {
        $inTransaction = true;
    });
    DB::listen(function (QueryExecuted $query) use (&$inTransaction, &$queries): void {
        if ($inTransaction) {
            $queries[] = $query->sql;
        }
    });

    try {
        $attempt();
    } catch (BookingRefused) {
        // The queries up to the refusal are what matters.
    }

    return $queries;
}

function lockStart(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2026-12-01 {$time}", 'Europe/Amsterdam');
}

describe('row locks taken by CreateBooking', function () {
    it('selects the employee row and then the customer row before anything else in the transaction', function (string $time) {
        [$employee, $service, $customer] = lockScenario();

        $queries = queriesInsideBookingTransaction(
            fn () => app(CreateBooking::class)->handle($customer, $service, $employee, lockStart($time)),
        );

        expect(count($queries))->toBeGreaterThan(2)
            ->and($queries[0])->toMatch('/^select .* from [`"]employees[`"] where /')
            ->and($queries[1])->toMatch('/^select .* from [`"]users[`"] where /');

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            expect($queries[0])->toEndWith(' for update')
                ->and($queries[1])->toEndWith(' for update');
        }
    })->with([
        // Locks come first whether the request then succeeds or is refused.
        'a booked slot' => '09:15',
        'a refused slot' => '08:00',
    ]);

    it('closes its transaction when a request is refused inside it', function (string $time, string $reason) {
        [$employee, $service, $customer] = lockScenario();
        app(CreateBooking::class)->handle(User::factory()->customer()->create(), $service, $employee, lockStart('09:15'));
        $level = DB::transactionLevel();

        expect(fn () => app(CreateBooking::class)->handle($customer, $service, $employee, lockStart($time)))
            ->toThrow(fn (BookingRefused $refused) => expect($refused->reason)->toBe($reason));

        expect(DB::transactionLevel())->toBe($level);
    })->with([
        'employee busy' => ['09:30', BookingRefused::REASON_EMPLOYEE_BUSY],
        'slot not offered' => ['08:00', BookingRefused::REASON_NOT_OFFERED],
    ]);

    it('takes no locks for a service the employee cannot perform', function () {
        [$employee, , $customer] = lockScenario();
        $other = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
        $level = DB::transactionLevel();

        $queries = queriesInsideBookingTransaction(function () use ($customer, $other, $employee) {
            expect(fn () => app(CreateBooking::class)->handle($customer, $other, $employee, lockStart('09:15')))
                ->toThrow(fn (BookingRefused $refused) => expect($refused->reason)->toBe(BookingRefused::REASON_NOT_BOOKABLE));
        });

        expect($queries)->toBe([])
            ->and(DB::transactionLevel())->toBe($level);
    });
});
