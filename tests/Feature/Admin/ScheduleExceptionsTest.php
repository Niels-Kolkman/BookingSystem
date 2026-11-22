<?php

use App\Enums\ScheduleExceptionType;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\post;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-10: date-specific exceptions (time off, extra hours) override the
 * weekly schedule, and Dutch public holidays close the business unless an
 * employee gets extra hours on that day.
 *
 * "Today" is frozen at Monday 2026-11-23 in Amsterdam.
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
});

function exceptionAdmin(): User
{
    return User::factory()->admin()->create();
}

/**
 * An employee working Monday to Friday 09:00-12:30 and 13:30-17:00.
 */
function weekdayEmployee(string $name = 'Anna'): Employee
{
    $employee = Employee::factory()->create(['display_name' => $name]);
    foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
        WorkingHour::factory()->for($employee)->on($day, '09:00', '12:30')->create();
        WorkingHour::factory()->for($employee)->on($day, '13:30', '17:00')->create();
    }

    return $employee;
}

function hoursOn(Employee $employee, string $date): string
{
    return $employee->fresh()?->scheduleOn(CarbonImmutable::parse($date, 'Europe/Amsterdam'))->label() ?? '';
}

/**
 * Exceptions of an employee as "date type times" strings.
 *
 * @return list<string>
 */
function exceptionsOf(Employee $employee): array
{
    return $employee->scheduleExceptions()->get()
        ->map(fn (ScheduleException $e): string => "{$e->date->toDateString()} {$e->type->value} {$e->timeLabel()}")
        ->all();
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function exceptionInput(array $overrides = []): array
{
    return [
        'type' => 'unavailable',
        'date' => '2026-12-01',
        'until' => null,
        'starts_at' => null,
        'ends_at' => null,
        'reason' => null,
        ...$overrides,
    ];
}

describe('model', function () {
    it('stores the date as Y-m-d and times as HH:MM:00', function () {
        $exception = ScheduleException::factory()->unavailable('2026-12-01', '9:00', '12:30')->create(['reason' => 'Tandarts']);

        $row = DB::table('schedule_exceptions')->first();
        $fresh = $exception->fresh();

        expect($row?->date)->toBe('2026-12-01')
            ->and($row?->starts_at)->toBe('09:00:00')
            ->and($fresh?->date->toDateString())->toBe('2026-12-01')
            ->and($fresh?->date->getTimezone()->getName())->toBe('Europe/Amsterdam')
            ->and($fresh?->type)->toBe(ScheduleExceptionType::Unavailable)
            ->and($fresh?->timeLabel())->toBe('09:00 - 12:30')
            ->and($fresh?->isWholeDay())->toBeFalse();
    });

    it('treats an exception without times as the whole day', function () {
        $exception = ScheduleException::factory()->unavailable('2026-12-01')->create();

        expect($exception->fresh()?->isWholeDay())->toBeTrue()
            ->and($exception->fresh()?->timeLabel())->toBe('Hele dag')
            ->and(DB::table('schedule_exceptions')->value('starts_at'))->toBeNull();
    });

    it('does not allow employee_id to be mass assigned', function () {
        $exception = new ScheduleException(['employee_id' => 1, 'date' => '2026-12-01', 'type' => 'unavailable']);

        expect($exception->employee_id)->toBeNull();
    });

    it('removes the exceptions when the employee is deleted', function () {
        $employee = Employee::factory()->create();
        ScheduleException::factory()->for($employee)->create();

        $employee->delete();

        expect(ScheduleException::query()->count())->toBe(0);
    });
});

describe('schedule for a date', function () {
    it('follows the weekly schedule on a normal day', function () {
        $employee = weekdayEmployee();

        expect(hoursOn($employee, '2026-12-01'))->toBe('09:00 - 12:30, 13:30 - 17:00')
            ->and(hoursOn($employee, '2026-12-05'))->toBe('Niet beschikbaar'); // Saturday
    });

    it('removes a whole day off', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01')->create();

        expect(hoursOn($employee, '2026-12-01'))->toBe('Niet beschikbaar')
            ->and(hoursOn($employee, '2026-12-02'))->toBe('09:00 - 12:30, 13:30 - 17:00');
    });

    it('removes only the hours of a partial day off', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '11:00', '15:00')->create();

        expect(hoursOn($employee, '2026-12-01'))->toBe('09:00 - 11:00, 15:00 - 17:00');
    });

    it('adds extra hours, also on a regular day off', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-01', '17:00', '20:00')->create();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-05', '10:00', '14:00')->create();

        expect(hoursOn($employee, '2026-12-01'))->toBe('09:00 - 12:30, 13:30 - 20:00')
            ->and(hoursOn($employee, '2026-12-05'))->toBe('10:00 - 14:00');
    });

    it('closes on Dutch public holidays', function () {
        $employee = weekdayEmployee();

        // Christmas 2026 is a Friday; in 2027 Tweede Paasdag is Monday 29
        // March, Koningsdag Tuesday 27 April, Hemelvaartsdag Thursday 6 May.
        $christmas = $employee->scheduleOn(CarbonImmutable::parse('2026-12-25'));
        expect($christmas->isAvailable())->toBeFalse()
            ->and($christmas->holiday?->name)->toBe('Eerste Kerstdag')
            ->and(hoursOn($employee, '2027-03-29'))->toBe('Niet beschikbaar')
            ->and(hoursOn($employee, '2027-04-27'))->toBe('Niet beschikbaar')
            ->and(hoursOn($employee, '2027-05-06'))->toBe('Niet beschikbaar');
    });

    it('stays open on holidays that are not configured as closed', function () {
        $employee = weekdayEmployee();

        // Goede Vrijdag 2027 is 26 March.
        $goodFriday = $employee->scheduleOn(CarbonImmutable::parse('2027-03-26'));
        expect($goodFriday->label())->toBe('09:00 - 12:30, 13:30 - 17:00')
            ->and($goodFriday->holiday?->name)->toBe('Goede Vrijdag');

        config(['holidays.closed' => ['goede_vrijdag']]);

        expect(hoursOn($employee, '2027-03-26'))->toBe('Niet beschikbaar')
            ->and(hoursOn($employee, '2026-12-25'))->toBe('09:00 - 12:30, 13:30 - 17:00');
    });

    it('lets an employee work on a closed holiday with extra hours', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-25', '10:00', '14:00')->create();

        expect(hoursOn($employee, '2026-12-25'))->toBe('10:00 - 14:00')
            ->and(hoursOn($employee, '2027-03-29'))->toBe('Niet beschikbaar');
    });

    it('lets time off win over extra hours on the same day', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-05', '10:00', '14:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-05')->create();

        expect(hoursOn($employee, '2026-12-05'))->toBe('Niet beschikbaar');
    });

    it('ignores the exceptions of other employees', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for(weekdayEmployee('Bram'))->unavailable('2026-12-01')->create();

        expect(hoursOn($employee, '2026-12-01'))->toBe('09:00 - 12:30, 13:30 - 17:00');
    });

    it('gives the same result with eager loaded exceptions', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '09:00', '10:00')->create();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-02')->create();

        $loaded = Employee::query()->with(['workingHours', 'scheduleExceptions'])->findOrFail($employee->id);

        expect($loaded->scheduleOn(CarbonImmutable::parse('2026-12-01'))->label())->toBe('10:00 - 12:30, 13:30 - 17:00')
            ->and($loaded->scheduleOn(CarbonImmutable::parse('2026-12-02'))->label())->toBe('Niet beschikbaar');
    });

    it('uses the calendar date of the given value, so callers pass a local date', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01')->create();

        // 2026-11-30 23:30 UTC is already 1 December in Amsterdam.
        $utc = CarbonImmutable::parse('2026-11-30 23:30', 'UTC');

        expect($employee->scheduleOn($utc)->isAvailable())->toBeTrue()
            ->and($employee->scheduleOn($utc->setTimezone('Europe/Amsterdam'))->isAvailable())->toBeFalse();
    });
});

describe('admin page', function () {
    it('is linked from the employee overview', function () {
        $employee = Employee::factory()->create();

        actingAs(exceptionAdmin())
            ->get(route('admin.employees.index'))
            ->assertSee(route('admin.employees.exceptions.index', $employee));
    });

    it('lists upcoming exceptions with the resulting hours and hides past ones', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->unavailable('2026-11-20')->create(['reason' => 'Oud verlof']);
        ScheduleException::factory()->for($employee)->unavailable('2026-12-01', '09:00', '12:30')->create(['reason' => 'Tandarts']);
        ScheduleException::factory()->for($employee)->extraHours('2026-12-05', '10:00', '14:00')->create();

        actingAs(exceptionAdmin())
            ->get(route('admin.employees.exceptions.index', $employee))
            ->assertOk()
            ->assertSeeInOrder([
                'Uitzonderingen voor Anna',
                'Dinsdag 01-12-2026', 'Afwezig: 09:00 - 12:30', 'Tandarts', 'Werktijden op deze dag: 13:30 - 17:00',
                'Zaterdag 05-12-2026', 'Extra uren: 10:00 - 14:00', 'Werktijden op deze dag: 10:00 - 14:00',
            ])
            ->assertDontSee('Oud verlof');
    });

    it('lists upcoming public holidays and whether the employee works', function () {
        $employee = weekdayEmployee();
        ScheduleException::factory()->for($employee)->extraHours('2026-12-26', '10:00', '16:00')->create();

        actingAs(exceptionAdmin())
            ->get(route('admin.employees.exceptions.index', $employee))
            ->assertSeeInOrder([
                'Feestdagen',
                'Eerste Kerstdag', 'Vrijdag 25-12-2026', 'Niet beschikbaar',
                'Tweede Kerstdag', 'Zaterdag 26-12-2026', '10:00 - 16:00',
                'Nieuwjaarsdag', '01-01-2027',
                'Goede Vrijdag', '26-03-2027', 'open', '09:00 - 12:30, 13:30 - 17:00',
            ])
            ->assertDontSee('25-12-2027'); // more than a year ahead
    });

    it('shows a message when nothing is planned', function () {
        actingAs(exceptionAdmin())
            ->get(route('admin.employees.exceptions.index', Employee::factory()->create()))
            ->assertOk()
            ->assertSee('Er zijn geen uitzonderingen gepland.');
    });

    it('returns 404 for an unknown employee', function () {
        actingAs(exceptionAdmin())
            ->get(route('admin.employees.exceptions.index', 999999))
            ->assertNotFound();
    });
});

describe('adding', function () {
    it('adds a whole day off', function () {
        $employee = weekdayEmployee();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput(['reason' => ' Ziek ']))
            ->assertRedirect(route('admin.employees.exceptions.index', $employee))
            ->assertSessionHas('status', 'Uitzondering is toegevoegd aan het rooster van Anna.');

        expect(exceptionsOf($employee))->toBe(['2026-12-01 unavailable Hele dag'])
            ->and($employee->scheduleExceptions()->value('reason'))->toBe('Ziek')
            ->and(hoursOn($employee, '2026-12-01'))->toBe('Niet beschikbaar');
    });

    it('adds a partial day off', function () {
        $employee = weekdayEmployee();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput(['starts_at' => '9:00', 'ends_at' => '12:30']))
            ->assertSessionHasNoErrors();

        expect(exceptionsOf($employee))->toBe(['2026-12-01 unavailable 09:00 - 12:30']);
    });

    it('adds extra hours', function () {
        $employee = weekdayEmployee();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput([
                'type' => 'extra_hours', 'date' => '2026-12-26', 'starts_at' => '10:00', 'ends_at' => '16:00',
            ]))
            ->assertSessionHasNoErrors();

        expect(exceptionsOf($employee))->toBe(['2026-12-26 extra_hours 10:00 - 16:00'])
            ->and(hoursOn($employee, '2026-12-26'))->toBe('10:00 - 16:00');
    });

    it('adds one exception per day for a range of days', function () {
        $employee = weekdayEmployee();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput([
                'date' => '2026-12-28', 'until' => '2027-01-01', 'reason' => 'Vakantie',
            ]))
            ->assertSessionHas('status', '5 uitzonderingen zijn toegevoegd aan het rooster van Anna.');

        expect(exceptionsOf($employee))->toBe([
            '2026-12-28 unavailable Hele dag',
            '2026-12-29 unavailable Hele dag',
            '2026-12-30 unavailable Hele dag',
            '2026-12-31 unavailable Hele dag',
            '2027-01-01 unavailable Hele dag',
        ]);
    });

    it('accepts today and a range of the maximum length', function () {
        $employee = Employee::factory()->create();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput(['date' => '2026-11-23', 'until' => '2027-01-23']))
            ->assertSessionHasNoErrors();

        expect($employee->scheduleExceptions()->count())->toBe(62);
    });

    it('creates nothing when saving one of the days fails', function () {
        $employee = Employee::factory()->create();
        $created = 0;
        ScheduleException::creating(function () use (&$created): void {
            if (++$created === 3) {
                throw new RuntimeException('Database down');
            }
        });

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput(['until' => '2026-12-05']))
            ->assertServerError();

        expect(ScheduleException::query()->count())->toBe(0);
    });

    it('does not touch existing bookings', function () {
        $employee = weekdayEmployee();
        $bookings = DB::table('bookings')->count();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput())
            ->assertSessionHasNoErrors();

        expect(DB::table('bookings')->count())->toBe($bookings);
    });
});

describe('validation', function () {
    it('rejects invalid input and saves nothing', function (array $input, string $field, string $message) {
        $employee = Employee::factory()->create();

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput($input))
            ->assertSessionHasErrors([$field => $message]);

        expect(ScheduleException::query()->count())->toBe(0);
    })->with([
        'missing type' => [['type' => null], 'type', 'Kies het soort uitzondering.'],
        'unknown type' => [['type' => 'holiday'], 'type', 'Kies het soort uitzondering.'],
        'missing date' => [['date' => null], 'date', 'Vul een datum in.'],
        'malformed date' => [['date' => '01-12-2026'], 'date', 'Vul de datum in als JJJJ-MM-DD.'],
        'impossible date' => [['date' => '2026-02-30'], 'date', 'Vul de datum in als JJJJ-MM-DD.'],
        'yesterday' => [['date' => '2026-11-22'], 'date', 'De datum mag niet in het verleden liggen.'],
        'until before date' => [['until' => '2026-11-30'], 'until', 'De einddatum moet op of na de begindatum liggen.'],
        'range too long' => [['date' => '2026-12-01', 'until' => '2027-02-01'], 'until', 'Je kunt maximaal 62 dagen tegelijk invoeren.'],
        'extra hours without times' => [['type' => 'extra_hours'], 'starts_at', 'Vul de begintijd van de extra uren in.'],
        'only a start time' => [['starts_at' => '09:00'], 'ends_at', 'Vul ook een eindtijd in.'],
        'only an end time' => [['ends_at' => '12:00'], 'starts_at', 'Vul ook een begintijd in.'],
        'malformed time' => [['starts_at' => '24:00', 'ends_at' => '25:00'], 'starts_at', 'Vul de begintijd in als UU:MM, bijvoorbeeld 09:00.'],
        'end before start' => [['starts_at' => '12:00', 'ends_at' => '09:00'], 'ends_at', 'De eindtijd moet na de begintijd liggen.'],
        'end equals start' => [['starts_at' => '12:00', 'ends_at' => '12:00'], 'ends_at', 'De eindtijd moet na de begintijd liggen.'],
        'reason too long' => [['reason' => str_repeat('a', 256)], 'reason', 'De omschrijving mag maximaal 255 tekens zijn.'],
    ]);

    it('uses the date in Amsterdam to decide what is in the past', function () {
        // 23:30 UTC on 23 November is already 24 November in Amsterdam, so
        // the 23rd is in the past even though it is still "today" in UTC.
        travelTo(CarbonImmutable::parse('2026-11-23 23:30', 'UTC'));

        actingAs(exceptionAdmin())
            ->post(route('admin.employees.exceptions.store', Employee::factory()->create()), exceptionInput(['date' => '2026-11-23']))
            ->assertSessionHasErrors(['date' => 'De datum mag niet in het verleden liggen.']);
    });

    it('shows errors next to the fields and keeps the input', function () {
        $employee = Employee::factory()->create();

        actingAs(exceptionAdmin())
            ->from(route('admin.employees.exceptions.index', $employee))
            ->followingRedirects()
            ->post(route('admin.employees.exceptions.store', $employee), exceptionInput([
                'type' => 'extra_hours', 'starts_at' => '17:00', 'ends_at' => '09:00', 'reason' => 'Koopavond',
            ]))
            ->assertOk()
            ->assertSee('aria-describedby="ends_at-error"', false)
            ->assertSee('De eindtijd moet na de begintijd liggen.')
            ->assertSee('value="extra_hours" checked', false)
            ->assertSee('value="Koopavond"', false);
    });
});

describe('deleting', function () {
    it('deletes an exception', function () {
        $employee = weekdayEmployee();
        $exception = ScheduleException::factory()->for($employee)->unavailable('2026-12-01')->create();

        actingAs(exceptionAdmin())
            ->delete(route('admin.employees.exceptions.destroy', [$employee, $exception]))
            ->assertRedirect(route('admin.employees.exceptions.index', $employee))
            ->assertSessionHas('status', 'Uitzondering op 01-12-2026 is verwijderd.');

        expect(exceptionsOf($employee))->toBe([])
            ->and(hoursOn($employee, '2026-12-01'))->toBe('09:00 - 12:30, 13:30 - 17:00');
    });

    it('returns 404 for an exception of another employee', function () {
        $employee = Employee::factory()->create();
        $other = ScheduleException::factory()->for(Employee::factory())->create();

        actingAs(exceptionAdmin())
            ->delete(route('admin.employees.exceptions.destroy', [$employee, $other]))
            ->assertNotFound();

        expect(ScheduleException::query()->whereKey($other->id)->exists())->toBeTrue();
    });
});

describe('authorization', function () {
    it('does not let non-admins see, add or delete exceptions', function (UserRole $role) {
        $employee = Employee::factory()->create();
        $exception = ScheduleException::factory()->for($employee)->create();
        $user = User::factory()->withRole($role)->create();

        actingAs($user)->get(route('admin.employees.exceptions.index', $employee))->assertForbidden();
        actingAs($user)->post(route('admin.employees.exceptions.store', $employee), exceptionInput(['date' => '2026-12-02']))->assertForbidden();
        actingAs($user)->delete(route('admin.employees.exceptions.destroy', [$employee, $exception]))->assertForbidden();

        expect(exceptionsOf($employee))->toBe(['2026-12-01 unavailable Hele dag']);
    })->with([
        'customer' => [UserRole::Customer],
        'employee' => [UserRole::Employee],
    ]);

    it('sends guests to the login page', function () {
        $employee = Employee::factory()->create();

        post(route('admin.employees.exceptions.store', $employee), exceptionInput())
            ->assertRedirect(route('login'));

        expect(ScheduleException::query()->count())->toBe(0);
    });
});
