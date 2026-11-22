<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Employee;
use App\Models\Service;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Admin CRUD for services, including which employees can perform them
 * (ISSUE-8). Only reachable with the admin role (route group middleware).
 */
class ServiceController extends Controller
{
    public function index(): View
    {
        $services = Service::query()
            ->withCount('bookings')
            ->withCount(['employees as active_employees_count' => fn (Builder $query) => $query->where('is_active', true)])
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('admin.services.index', ['services' => $services]);
    }

    public function create(): View
    {
        return view('admin.services.create', [
            'service' => new Service(['buffer_minutes' => 0, 'is_active' => true]),
            'employees' => $this->employees(),
            'linkedEmployeeIds' => [],
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $service = DB::transaction(function () use ($request): Service {
            $service = Service::create($request->serviceAttributes());
            $service->employees()->sync($request->employeeIds());

            return $service;
        });

        return redirect()->route('admin.services.index')
            ->with('status', "Dienst \"{$service->name}\" is aangemaakt.");
    }

    public function edit(Service $service): View
    {
        return view('admin.services.edit', [
            'service' => $service,
            'employees' => $this->employees(),
            'linkedEmployeeIds' => $service->employees()->pluck('employees.id')->all(),
        ]);
    }

    /**
     * Existing bookings keep their own snapshot of duration and price, so
     * changing a service only affects new bookings. Unlinking an employee
     * likewise leaves their existing bookings for this service in place.
     */
    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        DB::transaction(function () use ($request, $service): void {
            $service->update($request->serviceAttributes());
            $service->employees()->sync($request->employeeIds());
        });

        return redirect()->route('admin.services.index')
            ->with('status', "Dienst \"{$service->name}\" is opgeslagen.");
    }

    /**
     * A service with bookings cannot be deleted (bookings reference it and
     * the foreign key restricts deletes); deactivate it instead. Employee
     * links are removed by the cascading foreign key.
     */
    public function destroy(Service $service): RedirectResponse
    {
        if ($service->bookings()->exists()) {
            return redirect()->route('admin.services.index')
                ->with('error', "Dienst \"{$service->name}\" heeft boekingen en kan niet worden verwijderd. Zet de dienst op inactief, dan kan er niet meer op geboekt worden.");
        }

        $service->delete();

        return redirect()->route('admin.services.index')
            ->with('status', "Dienst \"{$service->name}\" is verwijderd.");
    }

    /**
     * Employees to choose from on the form, active ones first.
     *
     * @return Collection<int, Employee>
     */
    private function employees(): Collection
    {
        return Employee::query()
            ->orderByDesc('is_active')
            ->orderBy('display_name')
            ->get();
    }
}
