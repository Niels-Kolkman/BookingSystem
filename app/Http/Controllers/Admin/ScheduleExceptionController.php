<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScheduleExceptionRequest;
use App\Models\Employee;
use App\Models\ScheduleException;
use App\Scheduling\DutchHolidays;
use App\Scheduling\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Schedule exceptions of one employee (ISSUE-10): upcoming time off and
 * extra hours with the resulting working hours per date, upcoming public
 * holidays, and a form to add exceptions. Only reachable with the admin
 * role (route group middleware).
 */
class ScheduleExceptionController extends Controller
{
    /** How far ahead upcoming public holidays are listed. */
    private const HOLIDAY_DAYS_AHEAD = 365;

    public function index(Employee $employee): View
    {
        $today = CarbonImmutable::parse(ScheduleExceptionRequest::today(), DutchHolidays::TIMEZONE);

        $employee->load('workingHours');
        $employee->setRelation('scheduleExceptions', $employee->scheduleExceptions()->fromDate($today)->get());

        // Per date: its exceptions plus the hours that result from them.
        $days = $employee->scheduleExceptions
            ->groupBy(fn (ScheduleException $exception): string => $exception->date->toDateString())
            ->map(fn (Collection $exceptions, string $date): array => [
                'schedule' => $employee->scheduleOn(CarbonImmutable::parse($date, DutchHolidays::TIMEZONE)),
                'exceptions' => $exceptions,
            ]);

        $holidays = array_map(
            fn (Holiday $holiday): array => ['holiday' => $holiday, 'schedule' => $employee->scheduleOn($holiday->date)],
            DutchHolidays::between($today, $today->addDays(self::HOLIDAY_DAYS_AHEAD)),
        );

        return view('admin.employees.exceptions', [
            'employee' => $employee,
            'days' => $days,
            'holidays' => $holidays,
            'today' => $today->toDateString(),
        ]);
    }

    /**
     * A range of days creates one exception per day, in one transaction.
     * Existing bookings are not touched: a booking on a new day off stays
     * and has to be moved or cancelled by hand.
     */
    public function store(ScheduleExceptionRequest $request, Employee $employee): RedirectResponse
    {
        $attributes = $request->exceptionAttributes();
        $dates = $request->dates();

        DB::transaction(function () use ($employee, $attributes, $dates): void {
            foreach ($dates as $date) {
                $employee->scheduleExceptions()->create([...$attributes, 'date' => $date]);
            }
        });

        $count = count($dates);
        $what = $count === 1 ? 'Uitzondering is' : "{$count} uitzonderingen zijn";

        return redirect()->route('admin.employees.exceptions.index', $employee)
            ->with('status', "{$what} toegevoegd aan het rooster van {$employee->display_name}.");
    }

    /**
     * Route model binding is scoped to the employee, so an exception of
     * someone else answers 404.
     */
    public function destroy(Employee $employee, ScheduleException $scheduleException): RedirectResponse
    {
        $scheduleException->delete();

        return redirect()->route('admin.employees.exceptions.index', $employee)
            ->with('status', 'Uitzondering op '.$scheduleException->date->format('d-m-Y').' is verwijderd.');
    }
}
