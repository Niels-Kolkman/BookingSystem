<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Minimal agenda: the bookings of one employee in time order. The visual
 * day/week view is ISSUE-21.
 */
class AgendaController extends Controller
{
    /**
     * Sends an employee to their own agenda. Only reachable with the
     * employee role (route middleware). An employee login without a profile
     * has no agenda yet.
     */
    public function mine(Request $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $employee = $user->employee;

        abort_if($employee === null, 404);

        return redirect()->route('employees.agenda', $employee);
    }

    /**
     * Access is checked by the `can:viewAgenda,employee` route middleware.
     */
    public function show(Employee $employee): View
    {
        $bookings = $employee->bookings()
            ->with(['customer', 'employee', 'service'])
            ->orderBy('starts_at')
            ->paginate(25);

        return view('agenda.show', [
            'employee' => $employee,
            'bookings' => $bookings,
        ]);
    }
}
