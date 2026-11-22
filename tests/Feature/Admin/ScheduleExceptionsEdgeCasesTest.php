<?php

use App\Enums\ScheduleExceptionType;
use App\Enums\Weekday;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\User;
use App\Models\WorkingHour;
use App\Scheduling\DutchHolidays;
use App\Scheduling\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-10: edge cases around schedule exceptions and Dutch public holidays,
 * on top of ScheduleExceptionsTest. "Today" is frozen at Monday 2026-11-23 in
 * Amsterdam unless a test says otherwise.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
});

/**
 * An employee working every day of the week, 09:00-17:00.
 */
function fullWeekEmployee(string $name = 'Chris'): Employee
{
    $employee = Employee::factory()->create(['display_name' => $name]);
    foreach (Weekday::cases() as $day) {
        WorkingHour::factory()->for($employee)->on($day, '09:00', '17:00')->create();
    }

    return $employee;
}

function workedOn(Employee $employee, string $date): string
{
    return $employee->fresh()?->scheduleOn(CarbonImmutable::parse($date, 'Europe/Amsterdam'))->label() ?? '';
}

/**
 * @param  array<string, mixed>  $overrides
 */
function postException(Employee $employee, array $overrides): TestResponse
{
    return actingAs(User::factory()->admin()->create())
        ->post(route('admin.employees.exceptions.store', $employee), [
            'type' => 'unavailable',
            'date' => '2026-12-01',
            'until' => null,
            'starts_at' => null,
            'ends_at' => null,
            'reason' => null,
            ...$overrides,
        ]);
}

describe('holiday configuration', function () {
    it('only lists existing holiday keys as closed', function () {
        $keys = array_map(fn (Holiday $h): string => $h->key, DutchHolidays::forYear(2026));

        expect(config('holidays.closed'))->each->toBeIn($keys);
    });

    it('leaves Goede Vrijdag and Bevrijdingsdag open by default and closes the rest', function () {
        $open = array_values(array_map(
            fn (Holiday $h): string => $h->key,
            array_filter(DutchHolidays::forYear(2026), fn (Holiday $h): bool => ! $h->isClosed()),
        ));

        expect($open)->toBe(['goede_vrijdag', 'bevrijdingsdag']);
    });

    it('keeps every holiday open when nothing is configured as closed', function (mixed $closed) {
        config(['holidays.closed' => $closed]);

        $employee = fullWeekEmployee();

        expect(collect(DutchHolidays::forYear(2026))->contains(fn (Holiday $h): bool => $h->isClosed()))->toBeFalse()
            ->and(workedOn($employee, '2026-12-25'))->toBe('09:00 - 17:00');
    })->with([
        'empty list' => [[]],
        'null' => [null],
    ]);

    it('closes a holiday on a weekend for someone who works weekends', function () {
        // Eerste Pinksterdag is always a Sunday.
        expect(workedOn(fullWeekEmployee(), '2027-05-16'))->toBe('Niet beschikbaar');
    });

    it('closes Hemelvaartsdag when it falls on Bevrijdingsdag', function () {
        // 5 May 2016 was both; Hemelvaartsdag is closed, so the day should be.
        expect(workedOn(fullWeekEmployee(), '2016-05-05'))->toBe('Niet beschikbaar');
    })->skip('Known bug: DutchHolidays::on() returns only the first holiday of a date (Bevrijdingsdag, open by default), so DaySchedule ignores that Hemelvaartsdag on the same date is closed. Happened in 2005 and 2016; no such year until at least 2100.');
});

describe('model', function () {
    it('only accepts the known exception types in the database', function () {
        $employee = Employee::factory()->create();

        DB::table('schedule_exceptions')->insert([
            'employee_id' => $employee->id, 'date' => '2026-12-01', 'type' => 'holiday',
        ]);
    })->throws(QueryException::class);

    it('labels the exception types in Dutch', function () {
        expect(ScheduleExceptionType::Unavailable->label())->toBe('Afwezig')
            ->and(ScheduleExceptionType::ExtraHours->label())->toBe('Extra uren')
            ->and(array_map(fn (ScheduleExceptionType $t): string => $t->value, ScheduleExceptionType::cases()))
            ->toBe(['unavailable', 'extra_hours']);
    });

    it('stores only the calendar date of a date time value', function () {
        $fromString = ScheduleException::factory()->create(['date' => '2026-12-01 15:30']);
        $fromObject = ScheduleException::factory()->create(['date' => CarbonImmutable::parse('2026-12-02 23:30', 'Europe/Amsterdam')]);

        expect(DB::table('schedule_exceptions')->where('id', $fromString->id)->value('date'))->toBe('2026-12-01')
            ->and(DB::table('schedule_exceptions')->where('id', $fromObject->id)->value('date'))->toBe('2026-12-02')
            ->and($fromObject->fresh()?->date->format('Y-m-d H:i'))->toBe('2026-12-02 00:00');
    });

    it('stores empty times as null, so the exception covers the whole day', function () {
        $exception = ScheduleException::factory()->create(['starts_at' => '', 'ends_at' => '']);

        expect(DB::table('schedule_exceptions')->value('starts_at'))->toBeNull()
            ->and($exception->fresh()?->isWholeDay())->toBeTrue()
            ->and($exception->fresh()?->range()->label())->toBe('00:00 - 24:00');
    });

    it('finds exceptions on one date and from a date onwards in date and time order', function () {
        $employee = Employee::factory()->create();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-02', '18:00', '20:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02', '09:00', '10:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-11-30')->create();

        $label = fn (ScheduleException $e): string => "{$e->date->toDateString()} {$e->timeLabel()}";

        expect(ScheduleException::query()->onDate(CarbonImmutable::parse('2026-12-02'))->count())->toBe(2)
            ->and(ScheduleException::query()->fromDate(CarbonImmutable::parse('2026-12-01'))->get()->map($label)->all())
            ->toBe(['2026-12-01 Hele dag', '2026-12-02 09:00 - 10:00', '2026-12-02 18:00 - 20:00']);
    });
});

describe('schedule for a date', function () {
    it('applies exceptions on the days daylight saving time starts and ends', function () {
        $employee = fullWeekEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2027-03-28', '09:00', '12:00')->create();
        ScheduleException::factory()->for($employee)->extraHours('2027-10-31', '17:00', '19:00')->create();

        // 28 March 2027 is also Eerste Paasdag: closed, time off changes nothing.
        $spring = $employee->scheduleOn(CarbonImmutable::parse('2027-03-28'));

        expect($spring->label())->toBe('Niet beschikbaar')
            ->and($spring->date->format('Y-m-d H:i T'))->toBe('2027-03-28 00:00 CET')
            ->and(workedOn($employee, '2027-10-31'))->toBe('09:00 - 19:00');
    });

    it('stays unavailable on a closed holiday with extra hours and a whole day off', function () {
        $employee = fullWeekEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-25', '10:00', '14:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-25')->create();

        expect(workedOn($employee, '2026-12-25'))->toBe('Niet beschikbaar');
    });

    it('combines several exceptions of the same type on one date', function () {
        $employee = fullWeekEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '09:00', '10:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '16:00', '17:00')->create();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-01', '18:00', '19:00')->create();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-01', '19:00', '21:00')->create();

        expect(workedOn($employee, '2026-12-01'))->toBe('10:00 - 16:00, 18:00 - 21:00');
    });

    it('does not run extra queries for exceptions when they are eager loaded', function () {
        $employee = fullWeekEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01')->create();
        $loaded = Employee::query()->with(['workingHours', 'scheduleExceptions'])->findOrFail($employee->id);

        DB::enableQueryLog();
        $label = $loaded->scheduleOn(CarbonImmutable::parse('2026-12-01'))->label();

        expect($label)->toBe('Niet beschikbaar')
            ->and(DB::getQueryLog())->toBe([]);
    });
});

describe('adding', function () {
    it('creates one exception when the end date equals the start date', function () {
        $employee = fullWeekEmployee();

        postException($employee, ['until' => '2026-12-01'])
            ->assertSessionHas('status', 'Uitzondering is toegevoegd aan het rooster van Chris.');

        expect($employee->scheduleExceptions()->count())->toBe(1);
    });

    it('creates one exception per calendar day across a daylight saving change', function () {
        $employee = fullWeekEmployee();

        postException($employee, ['date' => '2027-03-27', 'until' => '2027-03-29'])->assertSessionHasNoErrors();

        expect($employee->scheduleExceptions()->get()->map(fn (ScheduleException $e): string => $e->date->toDateString())->all())
            ->toBe(['2027-03-27', '2027-03-28', '2027-03-29']);
    });

    it('applies the maximum range length across a daylight saving change', function () {
        $employee = Employee::factory()->create();

        // 62 days including both ends, spanning the switch on 28 March.
        postException($employee, ['date' => '2027-03-01', 'until' => '2027-05-01'])->assertSessionHasNoErrors();
        postException($employee, ['date' => '2027-03-01', 'until' => '2027-05-02'])
            ->assertSessionHasErrors(['until' => 'Je kunt maximaal 62 dagen tegelijk invoeren.']);

        expect($employee->scheduleExceptions()->count())->toBe(62);
    });

    it('adds the same extra hours to every day of a range, also on holidays', function () {
        $employee = fullWeekEmployee();

        postException($employee, [
            'type' => 'extra_hours', 'date' => '2026-12-24', 'until' => '2026-12-26', 'starts_at' => '18:00', 'ends_at' => '20:00',
        ])->assertSessionHas('status', '3 uitzonderingen zijn toegevoegd aan het rooster van Chris.');

        expect(workedOn($employee, '2026-12-24'))->toBe('09:00 - 17:00, 18:00 - 20:00')
            ->and(workedOn($employee, '2026-12-25'))->toBe('18:00 - 20:00')
            ->and(workedOn($employee, '2026-12-26'))->toBe('18:00 - 20:00');
    });

    it('stores a reason of only spaces as null', function () {
        $employee = Employee::factory()->create();

        postException($employee, ['reason' => '   '])->assertSessionHasNoErrors();

        expect($employee->scheduleExceptions()->value('reason'))->toBeNull();
    });

    it('accepts times from midnight up to 23:59', function () {
        $employee = fullWeekEmployee();

        postException($employee, ['type' => 'extra_hours', 'starts_at' => '00:00', 'ends_at' => '23:59'])->assertSessionHasNoErrors();

        expect(workedOn($employee, '2026-12-01'))->toBe('00:00 - 23:59');
    });

    it('rejects malformed times and saves nothing', function (array $input, string $field) {
        $employee = Employee::factory()->create();

        postException($employee, $input)->assertSessionHasErrors($field);

        expect(ScheduleException::query()->count())->toBe(0);
    })->with([
        'with seconds' => [['starts_at' => '09:00:00', 'ends_at' => '10:00'], 'starts_at'],
        'single minute digit' => [['starts_at' => '09:0', 'ends_at' => '10:00'], 'starts_at'],
        'dot separator' => [['starts_at' => '09:00', 'ends_at' => '10.00'], 'ends_at'],
        'array instead of string' => [['starts_at' => ['09:00'], 'ends_at' => '10:00'], 'starts_at'],
        'array type' => [['type' => ['unavailable']], 'type'],
    ]);
});

describe('admin page', function () {
    it('lists today\'s holiday and exceptions when today is a holiday', function () {
        travelTo(CarbonImmutable::parse('2026-12-25 09:00', 'Europe/Amsterdam'));
        $employee = fullWeekEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-25', '10:00', '12:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-24')->create(['reason' => 'Gisteren vrij']);

        actingAs(User::factory()->admin()->create())
            ->get(route('admin.employees.exceptions.index', $employee))
            ->assertOk()
            ->assertSee('min="2026-12-25"', false)
            ->assertSeeInOrder(['Vrijdag 25-12-2026', 'Extra uren: 10:00 - 12:00', 'Werktijden op deze dag: 10:00 - 12:00'])
            ->assertSeeInOrder(['Feestdagen', 'Eerste Kerstdag', 'Vrijdag 25-12-2026'])
            ->assertDontSee('Gisteren vrij');
    });
});

describe('deleting', function () {
    it('deletes only the chosen day of a range', function () {
        $employee = fullWeekEmployee();
        postException($employee, ['until' => '2026-12-03']);
        $middle = $employee->scheduleExceptions()->onDate(CarbonImmutable::parse('2026-12-02'))->firstOrFail();

        actingAs(User::factory()->admin()->create())
            ->delete(route('admin.employees.exceptions.destroy', [$employee, $middle]))
            ->assertSessionHas('status', 'Uitzondering op 02-12-2026 is verwijderd.');

        expect(workedOn($employee, '2026-12-01'))->toBe('Niet beschikbaar')
            ->and(workedOn($employee, '2026-12-02'))->toBe('09:00 - 17:00')
            ->and(workedOn($employee, '2026-12-03'))->toBe('Niet beschikbaar');
    });

    it('returns 404 for an exception that no longer exists', function () {
        $employee = Employee::factory()->create();
        $exception = ScheduleException::factory()->for($employee)->create();
        $exception->delete();

        actingAs(User::factory()->admin()->create())
            ->delete(route('admin.employees.exceptions.destroy', [$employee, $exception]))
            ->assertNotFound();
    });
});
