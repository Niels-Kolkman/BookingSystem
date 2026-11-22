<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ScheduleRequest;
use App\Models\Employee;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Weekly schedules of employees (ISSUE-9): an overview of all employees with
 * their working hours, and a form per employee. Only reachable with the
 * admin role (route group middleware).
 */
class EmployeeScheduleController extends Controller
{
    public function index(): View
    {
        $employees = Employee::query()
            ->with('workingHours')
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->get();

        return view('admin.employees.index', ['employees' => $employees]);
    }

    public function edit(Employee $employee): View
    {
        return view('admin.employees.schedule', [
            'employee' => $employee->load('workingHours'),
        ]);
    }

    /**
     * The submitted form is the complete schedule: all existing blocks are
     * replaced in one transaction, so a failure never leaves half a week.
     * Existing bookings are not touched; the new hours only affect which
     * slots are offered from now on.
     */
    public function update(ScheduleRequest $request, Employee $employee): RedirectResponse
    {
        DB::transaction(function () use ($request, $employee): void {
            $employee->workingHours()->reorder()->delete();
            $employee->workingHours()->createMany($request->blocks());
        });

        return redirect()->route('admin.employees.index')
            ->with('status', "Werkrooster van {$employee->display_name} is opgeslagen.");
    }
}
