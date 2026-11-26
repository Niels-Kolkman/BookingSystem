<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use App\Scheduling\Agenda;
use App\Scheduling\LocalTime;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The agenda of one employee as a day or week grid (ISSUE-21): bookings,
 * breaks, time off and public holidays. See App\Scheduling\Agenda.
 */
class AgendaController extends Controller
{
    public const VIEW_DAY = 'day';

    public const VIEW_WEEK = 'week';

    /**
     * Sends an employee to their own agenda, keeping `view` and `date`.
     * Only reachable with the employee role (route middleware). An employee
     * login without a profile has no agenda yet.
     */
    public function mine(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $employee = $user->employee;

        abort_if($employee === null, 404);

        $query = array_filter(
            ['view' => $request->query('view'), 'date' => $request->query('date')],
            fn (mixed $value): bool => is_string($value),
        );

        return redirect()->route('employees.agenda', [$employee, ...$query]);
    }

    /**
     * `?view=day|week` (default week) and `?date=Y-m-d` (default today,
     * local). A week runs Monday to Sunday around the date. Anything else in
     * the query string falls back to the defaults, like the booking page.
     *
     * Access is checked by the `can:viewAgenda,employee` route middleware.
     */
    public function show(Request $request, Employee $employee): View
    {
        $view = $request->query('view') === self::VIEW_DAY ? self::VIEW_DAY : self::VIEW_WEEK;
        $today = LocalTime::midnight(LocalTime::toLocal(CarbonImmutable::now()));
        $date = LocalTime::parseDate($request->query('date')) ?? $today;

        if ($view === self::VIEW_WEEK) {
            $from = $date->subDays((int) $date->format('N') - 1);
            [$previous, $next] = [$from->subWeek(), $from->addWeek()];
        } else {
            $from = $date;
            [$previous, $next] = [$from->subDay(), $from->addDay()];
        }

        $days = Agenda::for($employee, $from, $view === self::VIEW_WEEK ? 7 : 1);

        return view('agenda.show', [
            'employee' => $employee,
            'view' => $view,
            'date' => $date,
            'days' => $days,
            'hours' => Agenda::hourWindow($days),
            'previous' => $previous->format('Y-m-d'),
            'next' => $next->format('Y-m-d'),
            'today' => $today->format('Y-m-d'),
        ]);
    }
}
