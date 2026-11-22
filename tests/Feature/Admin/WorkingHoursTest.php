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
});
