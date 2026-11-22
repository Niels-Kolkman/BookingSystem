<?php

use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\Employee;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\put;

/*
 * ISSUE-9: every employee has a recurring weekly schedule with one or more
 * blocks per weekday, e.g. 09:00-12:30 and 13:30-17:00 around a lunch break.
 */

function scheduleAdmin(): User
{
    return User::factory()->admin()->create();
}

/**
 * Blocks of an employee as "weekday start-end" strings, in time order.
 *
 * @return list<string>
 */
function scheduleOf(Employee $employee): array
{
    return $employee->workingHours()->get()
        ->map(fn (WorkingHour $block): string => "{$block->weekday->value} {$block->label()}")
        ->values()
        ->all();
}

/**
 * Form input with only the given weekdays filled.
 *
 * @param  array<int, list<array{0: string|null, 1: string|null}>>  $days
 * @return array{blocks: array<int, list<array{starts_at: string|null, ends_at: string|null}>>}
 */
function scheduleInput(array $days): array
{
    $blocks = [];

    foreach ($days as $weekday => $rows) {
        foreach ($rows as [$start, $end]) {
            $blocks[$weekday][] = ['starts_at' => $start, 'ends_at' => $end];
        }
    }

    return ['blocks' => $blocks];
}

describe('model', function () {
    it('maps dates to ISO weekdays with Dutch labels', function () {
        // 2026-11-23 is a Monday.
        expect(Weekday::fromDate(CarbonImmutable::parse('2026-11-23')))->toBe(Weekday::Monday)
            ->and(Weekday::fromDate(CarbonImmutable::parse('2026-11-29')))->toBe(Weekday::Sunday)
            ->and(Weekday::Monday->label())->toBe('maandag')
            ->and(Weekday::Sunday->value)->toBe(7);
    });

    it('stores times as HH:MM:00 and reads them as HH:MM', function () {
        $block = WorkingHour::factory()->on(Weekday::Tuesday, '9:00', '12:30')->create();

        expect($block->fresh()?->starts_at)->toBe('09:00')
            ->and($block->fresh()?->ends_at)->toBe('12:30')
            ->and(DB::table('working_hours')->value('starts_at'))->toBe('09:00:00')
            ->and($block->startMinutes())->toBe(540)
            ->and($block->endMinutes())->toBe(750)
            ->and($block->label())->toBe('09:00 - 12:30');
    });

    it('rejects a malformed time', function () {
        WorkingHour::factory()->on(Weekday::Monday, '25:00', '26:00')->make();
    })->throws(InvalidArgumentException::class);

    it('returns the blocks of one weekday in time order, including split shifts', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '13:30', '17:00')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '09:00', '12:30')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Wednesday, '10:00', '14:00')->create();
        WorkingHour::factory()->on(Weekday::Monday, '06:00', '08:00')->create(); // someone else

        expect($employee->workingHoursOn(Weekday::Monday)->map->label()->all())->toBe(['09:00 - 12:30', '13:30 - 17:00'])
            ->and($employee->workingHoursOn(Weekday::Wednesday)->map->label()->all())->toBe(['10:00 - 14:00'])
            ->and($employee->workingHoursOn(Weekday::Sunday))->toBeEmpty();
    });

    it('removes the schedule when the employee is deleted', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->create();

        $employee->delete();

        expect(WorkingHour::query()->count())->toBe(0);
    });
});

describe('admin overview', function () {
    it('lists employees with their working hours per day', function () {
        $employee = Employee::factory()->create(['display_name' => 'Anna']);
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '09:00', '12:30')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '13:30', '17:00')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Friday, '10:00', '15:00')->create();

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertSeeInOrder(['Anna', 'Maandag', '09:00 - 12:30, 13:30 - 17:00', 'Vrijdag', '10:00 - 15:00'])
            ->assertDontSee('Dinsdag')
            ->assertSee(route('admin.employees.schedule.edit', $employee));
    });

    it('warns about employees without a schedule', function () {
        Employee::factory()->create(['display_name' => 'Bram']);

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.index'))
            ->assertSee('Nog geen werkrooster');
    });

    it('is linked from the admin dashboard', function () {
        actingAs(scheduleAdmin())
            ->get(route('admin.dashboard'))
            ->assertSee(route('admin.employees.index'));
    });
});

describe('admin form', function () {
    it('shows every weekday with the saved blocks and spare rows', function () {
        $employee = Employee::factory()->create(['display_name' => 'Anna']);
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '09:00', '12:30')->create();

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.schedule.edit', $employee))
            ->assertOk()
            ->assertSee('Werkrooster van Anna')
            ->assertSeeInOrder(['Maandag', 'Dinsdag', 'Woensdag', 'Donderdag', 'Vrijdag', 'Zaterdag', 'Zondag'])
            ->assertSeeInOrder([
                'name="blocks[1][0][starts_at]"', 'value="09:00"',
                'name="blocks[1][0][ends_at]"', 'value="12:30"',
                'name="blocks[1][1][starts_at]"', 'value=""',
            ], false)
            // Empty days get two rows, enough for a lunch break.
            ->assertSee('name="blocks[7][1][ends_at]"', false)
            ->assertDontSee('name="blocks[7][2][ends_at]"', false);
    });

    it('saves several blocks per day and treats empty days as days off', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['13:30', '17:00'], ['09:00', '12:30']],
                3 => [['10:00', '14:00'], [null, null]],
                7 => [[null, null], [null, null]],
            ]))
            ->assertRedirect(route('admin.employees.index'))
            ->assertSessionHas('status');

        expect(scheduleOf($employee))->toBe([
            '1 09:00 - 12:30',
            '1 13:30 - 17:00',
            '3 10:00 - 14:00',
        ]);
    });

    it('accepts blocks that touch', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                2 => [['09:00', '12:00'], ['12:00', '17:00']],
            ]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['2 09:00 - 12:00', '2 12:00 - 17:00']);
    });

    it('accepts times without a leading zero', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([4 => [['8:15', '9:45']]]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['4 08:15 - 09:45']);
    });

    it('replaces the whole schedule and leaves other employees alone', function () {
        $employee = Employee::factory()->create();
        $other = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '09:00', '17:00')->create();
        WorkingHour::factory()->for($other)->on(Weekday::Monday, '09:00', '17:00')->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([5 => [['08:00', '12:00']]]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['5 08:00 - 12:00'])
            ->and(scheduleOf($other))->toBe(['1 09:00 - 17:00']);
    });

    it('clears the schedule when every row is empty or nothing is sent', function (array $input) {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), $input)
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe([]);
    })->with([
        'empty rows' => [scheduleInput([1 => [[null, null], ['', '']]])],
        'nothing' => [[]],
    ]);

    it('sets the schedule of an inactive employee', function () {
        $employee = Employee::factory()->inactive()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [['09:00', '17:00']]]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['1 09:00 - 17:00']);
    });

    it('returns 404 for an unknown employee', function () {
        actingAs(scheduleAdmin())
            ->get(route('admin.employees.schedule.edit', 999999))
            ->assertNotFound();
    });
});

describe('validation', function () {
    it('requires the end to be after the start', function (string $start, string $end) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [[$start, $end]]]))
            ->assertSessionHasErrors(['blocks.1.0.ends_at' => 'De eindtijd moet na de begintijd liggen.']);

        expect(scheduleOf($employee))->toBe([]);
    })->with([
        'before' => ['17:00', '09:00'],
        'equal' => ['09:00', '09:00'],
        // Night shifts past midnight are not supported.
        'past midnight' => ['22:00', '02:00'],
    ]);

    it('rejects overlapping blocks on the same day', function (string $start, string $end) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '12:30'], [$start, $end]],
            ]))
            ->assertSessionHasErrors(['blocks.1.1.starts_at' => 'Dit blok overlapt met een ander blok op dezelfde dag.']);

        expect(scheduleOf($employee))->toBe([]);
    })->with([
        'partly' => ['12:00', '17:00'],
        'inside' => ['10:00', '11:00'],
        'around' => ['08:00', '13:00'],
        'identical' => ['09:00', '12:30'],
    ]);

    it('allows the same hours on different days', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '17:00']],
                2 => [['09:00', '17:00']],
            ]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toHaveCount(2);
    });

    it('requires both times of a row', function (?string $start, ?string $end, string $field, string $message) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [[$start, $end]]]))
            ->assertSessionHasErrors(["blocks.1.0.{$field}" => $message]);
    })->with([
        'missing end' => ['09:00', null, 'ends_at', 'Vul ook een eindtijd in.'],
        'missing start' => [null, '17:00', 'starts_at', 'Vul ook een begintijd in.'],
    ]);

    it('rejects malformed times', function (string $time) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [[$time, '17:00']]]))
            ->assertSessionHasErrors(['blocks.1.0.starts_at' => 'Vul de begintijd in als UU:MM, bijvoorbeeld 09:00.']);
    })->with(['24:00', '9', '09:60', 'negen uur', '09:00:00', '-1:00']);

    it('rejects malformed structures', function (mixed $blocks, string $field) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), ['blocks' => $blocks])
            ->assertSessionHasErrors($field);

        expect(scheduleOf($employee))->toBe([]);
    })->with([
        'not an array' => ['maandag', 'blocks'],
        'unknown weekday' => [[8 => [['starts_at' => '09:00', 'ends_at' => '17:00']]], 'blocks'],
        'weekday zero' => [[0 => [['starts_at' => '09:00', 'ends_at' => '17:00']]], 'blocks'],
        'day not a list' => [[1 => '09:00'], 'blocks.1'],
        'row not an array' => [[1 => ['09:00']], 'blocks.1.0'],
        'unknown field in row' => [[1 => [['starts_at' => '09:00', 'ends_at' => '17:00', 'employee_id' => 5]]], 'blocks.1.0'],
        'time as array' => [[1 => [['starts_at' => ['09:00'], 'ends_at' => '17:00']]], 'blocks.1.0.starts_at'],
    ]);

    it('allows at most six blocks per day', function () {
        $employee = Employee::factory()->create();
        $rows = array_map(fn (int $hour): array => [sprintf('%02d:00', $hour), sprintf('%02d:30', $hour)], range(8, 14));

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => $rows]))
            ->assertSessionHasErrors(['blocks.1' => 'Je kunt maximaal 6 blokken per dag invullen.']);
    });

    it('keeps the saved schedule when the input is invalid', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '09:00', '17:00')->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                2 => [['09:00', '12:00']],
                3 => [['17:00', '09:00']],
            ]))
            ->assertSessionHasErrors('blocks.3.0.ends_at');

        expect(scheduleOf($employee))->toBe(['1 09:00 - 17:00']);
    });

    it('shows the error next to the submitted row and keeps the input', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->from(route('admin.employees.schedule.edit', $employee))
            ->followingRedirects()
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '12:30'], ['12:00', '17:00']],
            ]))
            ->assertOk()
            ->assertSee('id="blocks-1-1-error"', false)
            ->assertSee('Dit blok overlapt met een ander blok op dezelfde dag.')
            ->assertSeeInOrder([
                'name="blocks[1][0][ends_at]"', 'value="12:30"',
                'name="blocks[1][1][starts_at]"', 'value="12:00"',
                'id="blocks-1-1-error"',
            ], false);
    });
});

describe('authorization', function () {
    it('does not let non-admins see or change schedules', function (UserRole $role) {
        $employee = Employee::factory()->create();
        $user = User::factory()->withRole($role)->create();

        actingAs($user)->get(route('admin.employees.index'))->assertForbidden();
        actingAs($user)->get(route('admin.employees.schedule.edit', $employee))->assertForbidden();
        actingAs($user)
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [['09:00', '17:00']]]))
            ->assertForbidden();

        expect(scheduleOf($employee))->toBe([]);
    })->with([
        'customer' => [UserRole::Customer],
        'employee' => [UserRole::Employee],
    ]);

    it('sends guests to the login page', function () {
        $employee = Employee::factory()->create();

        put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [['09:00', '17:00']]]))
            ->assertRedirect(route('login'));

        expect(scheduleOf($employee))->toBe([]);
    });

    it('does not let non-admins load the edit form of a missing employee', function () {
        // The role check should run before route model binding reveals anything.
        actingAs(User::factory()->withRole(UserRole::Customer)->create())
            ->get(route('admin.employees.schedule.edit', 999999))
            ->assertForbidden();
    })->skip('Known bug: non-admins get 404 for missing and 403 for existing employees, so they can probe which ids exist. The `role` middleware is not in the middleware priority list, so SubstituteBindings runs first (affects every admin route with a bound model).');
});

describe('edge cases: model', function () {
    it('labels every weekday in Dutch and in ISO order', function () {
        expect(array_map(fn (Weekday $day): int => $day->value, Weekday::cases()))->toBe([1, 2, 3, 4, 5, 6, 7])
            ->and(array_map(fn (Weekday $day): string => $day->label(), Weekday::cases()))->toBe([
                'maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag',
            ]);
    });

    it('maps every date of a week to its weekday', function () {
        $monday = CarbonImmutable::parse('2026-11-23');

        foreach (Weekday::cases() as $offset => $weekday) {
            expect(Weekday::fromDate($monday->addDays($offset)))->toBe($weekday);
        }
    });

    it('uses the time zone of the given date', function () {
        // Sunday 23:30 in UTC is already Monday 00:30 in Amsterdam.
        $utc = CarbonImmutable::parse('2026-11-29 23:30', 'UTC');

        expect(Weekday::fromDate($utc))->toBe(Weekday::Sunday)
            ->and(Weekday::fromDate($utc->setTimezone('Europe/Amsterdam')))->toBe(Weekday::Monday);
    });

    it('normalizes times', function (string $input, string $expected, int $minutes) {
        expect(WorkingHour::normalizeTime($input))->toBe($expected)
            ->and(WorkingHour::toMinutes($input))->toBe($minutes);
    })->with([
        'midnight' => ['00:00', '00:00', 0],
        'last minute' => ['23:59', '23:59', 1439],
        'no leading zero' => ['9:05', '09:05', 545],
        'with seconds' => ['17:30:00', '17:30', 1050],
        'seconds dropped' => ['17:30:59', '17:30', 1050],
        'surrounding spaces' => [' 08:00 ', '08:00', 480],
    ]);

    it('rejects invalid times', function (string $input) {
        WorkingHour::normalizeTime($input);
    })->throws(InvalidArgumentException::class)->with(['', '24:00', '12:60', '12', '12:5', 'abc', '12:00:60', '-1:00']);

    it('does not allow employee_id to be mass assigned', function () {
        $employee = Employee::factory()->create();

        $block = new WorkingHour(['employee_id' => $employee->id, 'weekday' => Weekday::Monday, 'starts_at' => '09:00', 'ends_at' => '17:00']);

        expect($block->employee_id)->toBeNull()
            ->and($block->weekday)->toBe(Weekday::Monday);
    });

    it('belongs to its employee', function () {
        $employee = Employee::factory()->create();
        $block = WorkingHour::factory()->for($employee)->create();

        expect($block->employee->is($employee))->toBeTrue();
    });

    it('orders blocks by weekday first, then by start time', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Sunday, '08:00', '09:00')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '14:00', '15:00')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '9:00', '10:00')->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '07:00', '08:00')->create();

        expect(scheduleOf($employee))->toBe([
            '1 09:00 - 10:00',
            '1 14:00 - 15:00',
            '2 07:00 - 08:00',
            '7 08:00 - 09:00',
        ]);
    });
});

describe('edge cases: saving', function () {
    it('accepts a block spanning the whole day', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([6 => [['00:00', '23:59']]]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['6 00:00 - 23:59']);
    });

    it('accepts exactly six blocks on one day', function () {
        $employee = Employee::factory()->create();
        $rows = array_map(fn (int $hour): array => [sprintf('%02d:00', $hour), sprintf('%02d:30', $hour)], range(8, 13));

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => $rows]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toHaveCount(6);
    });

    it('fills every day of the week', function () {
        $employee = Employee::factory()->create();
        $days = [];
        foreach (Weekday::cases() as $weekday) {
            $days[$weekday->value] = [['09:00', '12:00'], ['13:00', '17:00']];
        }

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput($days))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toHaveCount(14);
        foreach (Weekday::cases() as $weekday) {
            expect($employee->fresh()?->workingHoursOn($weekday)->map->label()->all())
                ->toBe(['09:00 - 12:00', '13:00 - 17:00']);
        }
    });

    it('ignores spare empty rows between filled rows and sparse row keys', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), ['blocks' => [
                1 => [
                    0 => ['starts_at' => null, 'ends_at' => null],
                    3 => ['starts_at' => '13:00', 'ends_at' => '17:00'],
                    5 => ['starts_at' => '08:00', 'ends_at' => '12:00'],
                ],
            ]])
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['1 08:00 - 12:00', '1 13:00 - 17:00']);
    });

    it('trims spaces around submitted times', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [[' 09:00 ', '17:00 ']]]))
            ->assertSessionHasNoErrors();

        expect(scheduleOf($employee))->toBe(['1 09:00 - 17:00']);
    });

    it('confirms the save with the employee name', function () {
        $employee = Employee::factory()->create(['display_name' => 'Anna']);

        actingAs(scheduleAdmin())
            ->followingRedirects()
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => [['09:00', '17:00']]]))
            ->assertOk()
            ->assertSee('Werkrooster van Anna is opgeslagen.');
    });

    it('keeps the old schedule when saving the new blocks fails', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '09:00', '17:00')->create();

        // Fail on the second new block, after the old ones are deleted.
        $created = 0;
        WorkingHour::creating(function () use (&$created): void {
            if (++$created === 2) {
                throw new RuntimeException('Database down');
            }
        });

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                2 => [['09:00', '12:00'], ['13:00', '17:00']],
            ]))
            ->assertServerError();

        expect(scheduleOf($employee))->toBe(['1 09:00 - 17:00']);
    });

    it('does not touch existing bookings', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->create();
        $bookings = DB::table('bookings')->count();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), [])
            ->assertSessionHasNoErrors();

        expect(DB::table('bookings')->count())->toBe($bookings);
    });
});

describe('edge cases: validation', function () {
    it('marks both overlapping blocks and leaves the others alone', function () {
        $employee = Employee::factory()->create();

        $response = actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '12:30'], ['12:00', '13:00'], ['14:00', '17:00']],
            ]));

        $response->assertSessionHasErrors(['blocks.1.0.starts_at', 'blocks.1.1.starts_at'])
            ->assertSessionDoesntHaveErrors(['blocks.1.2.starts_at', 'blocks.1.2.ends_at']);
    });

    it('reports overlaps per day only', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '17:00']],
                2 => [['09:00', '12:00'], ['11:00', '13:00']],
            ]))
            ->assertSessionHasErrors('blocks.2.1.starts_at')
            ->assertSessionDoesntHaveErrors('blocks.1.0.starts_at');
    });

    it('does not report an overlap for a block that already ends before it starts', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '17:00'], ['16:00', '10:00']],
            ]))
            ->assertSessionHasErrors(['blocks.1.1.ends_at' => 'De eindtijd moet na de begintijd liggen.'])
            ->assertSessionDoesntHaveErrors(['blocks.1.0.starts_at', 'blocks.1.1.starts_at']);
    });

    it('puts errors on the submitted row key', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), ['blocks' => [
                4 => [3 => ['starts_at' => '17:00', 'ends_at' => '09:00']],
            ]])
            ->assertSessionHasErrors('blocks.4.3.ends_at');
    });

    it('rejects a malformed end time', function (mixed $time) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), ['blocks' => [1 => [['starts_at' => '09:00', 'ends_at' => $time]]]])
            ->assertSessionHasErrors(['blocks.1.0.ends_at' => 'Vul de eindtijd in als UU:MM, bijvoorbeeld 17:00.']);

        expect(scheduleOf($employee))->toBe([]);
    })->with([
        'hour 24' => ['24:00'],
        'text' => ['vijf uur'],
        'array' => [['17:00']],
    ]);

    it('skips the overlap check until every time is well-formed', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['09:00', '12:30'], ['12:00', '17:00']],
                2 => [['9 uur', '17:00']],
            ]))
            ->assertSessionHasErrors('blocks.2.0.starts_at')
            ->assertSessionDoesntHaveErrors('blocks.1.1.starts_at');
    });

    it('rejects too many rows even when some are empty', function () {
        $employee = Employee::factory()->create();
        $rows = array_fill(0, 7, [null, null]);
        $rows[0] = ['09:00', '17:00'];

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([1 => $rows]))
            ->assertSessionHasErrors('blocks.1');

        expect(scheduleOf($employee))->toBe([]);
    });

    it('rejects weekday keys that are not plain numbers', function (string $key) {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->put(route('admin.employees.schedule.update', $employee), ['blocks' => [
                $key => [['starts_at' => '09:00', 'ends_at' => '17:00']],
            ]])
            ->assertSessionHasErrors(['blocks' => 'Het rooster bevat een ongeldige weekdag.']);

        expect(scheduleOf($employee))->toBe([]);
    })->with(['monday', '01', '1.5']);

    it('shows a weekday error at the top of the form', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->from(route('admin.employees.schedule.edit', $employee))
            ->followingRedirects()
            ->put(route('admin.employees.schedule.update', $employee), ['blocks' => [9 => [['starts_at' => '09:00', 'ends_at' => '17:00']]]])
            ->assertOk()
            ->assertSee('Het rooster bevat een ongeldige weekdag.');
    });
});

describe('edge cases: pages', function () {
    it('lists active employees before inactive ones, then by name', function () {
        Employee::factory()->inactive()->create(['display_name' => 'Aad']);
        Employee::factory()->create(['display_name' => 'Zoë']);
        Employee::factory()->create(['display_name' => 'Bram']);

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.index'))
            ->assertSeeInOrder(['Bram', 'Zoë', 'Aad', 'inactief']);
    });

    it('shows a message when there are no employees', function () {
        actingAs(scheduleAdmin())
            ->get(route('admin.employees.index'))
            ->assertOk()
            ->assertSee('Er zijn nog geen medewerkers.');
    });

    it('does not warn about employees that have a schedule', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->create();

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.index'))
            ->assertDontSee('Nog geen werkrooster');
    });

    it('shows one spare row after the saved blocks and never more than six rows', function () {
        $employee = Employee::factory()->create();
        foreach (range(8, 10) as $hour) {
            WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, "{$hour}:00", "{$hour}:30")->create();
        }
        foreach (range(8, 13) as $hour) {
            WorkingHour::factory()->for($employee)->on(Weekday::Friday, "{$hour}:00", "{$hour}:30")->create();
        }

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.schedule.edit', $employee))
            ->assertOk()
            ->assertSeeInOrder([
                'name="blocks[2][2][starts_at]"', 'value="10:00"',
                'name="blocks[2][3][starts_at]"', 'value=""',
            ], false)
            ->assertDontSee('name="blocks[2][4][starts_at]"', false)
            ->assertSee('name="blocks[5][5][starts_at]"', false)
            ->assertDontSee('name="blocks[5][6][starts_at]"', false);
    });

    it('labels every input for screen readers', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->get(route('admin.employees.schedule.edit', $employee))
            ->assertSee('for="blocks-1-0-starts_at"', false)
            ->assertSee('id="blocks-1-0-starts_at"', false)
            ->assertSee('(maandag, blok 2)', false);
    });

    it('marks invalid inputs after a validation error', function () {
        $employee = Employee::factory()->create();

        actingAs(scheduleAdmin())
            ->from(route('admin.employees.schedule.edit', $employee))
            ->followingRedirects()
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([3 => [['17:00', '09:00']]]))
            ->assertSee('aria-invalid="true"', false)
            ->assertSee('aria-describedby="blocks-3-0-error"', false)
            ->assertSee('De eindtijd moet na de begintijd liggen.');
    });

    it('shows the submitted input instead of the saved schedule after an error', function () {
        $employee = Employee::factory()->create();
        WorkingHour::factory()->for($employee)->on(Weekday::Monday, '07:15', '07:45')->create();

        actingAs(scheduleAdmin())
            ->from(route('admin.employees.schedule.edit', $employee))
            ->followingRedirects()
            ->put(route('admin.employees.schedule.update', $employee), scheduleInput([
                1 => [['10:00', '11:00']],
                2 => [['17:00', '09:00']],
            ]))
            ->assertSee('value="10:00"', false)
            ->assertDontSee('value="07:15"', false);
    });
});
