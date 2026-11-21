<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\ServiceRequest;
use App\Models\Service;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Admin CRUD for services. Only reachable with the admin role (route group
 * middleware).
 */
class ServiceController extends Controller
{
    public function index(): View
    {
        $services = Service::query()
            ->withCount('bookings')
            ->orderByDesc('is_active')
            ->orderBy('name')
            ->get();

        return view('admin.services.index', ['services' => $services]);
    }

    public function create(): View
    {
        return view('admin.services.create', [
            'service' => new Service(['buffer_minutes' => 0, 'is_active' => true]),
        ]);
    }

    public function store(ServiceRequest $request): RedirectResponse
    {
        $service = Service::create($request->serviceAttributes());

        return redirect()->route('admin.services.index')
            ->with('status', "Dienst \"{$service->name}\" is aangemaakt.");
    }

    public function edit(Service $service): View
    {
        return view('admin.services.edit', ['service' => $service]);
    }

    /**
     * Existing bookings keep their own snapshot of duration and price, so
     * changing a service only affects new bookings.
     */
    public function update(ServiceRequest $request, Service $service): RedirectResponse
    {
        $service->update($request->serviceAttributes());

        return redirect()->route('admin.services.index')
            ->with('status', "Dienst \"{$service->name}\" is opgeslagen.");
    }

    /**
     * A service with bookings cannot be deleted (bookings reference it and
     * the foreign key restricts deletes); deactivate it instead.
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
}
