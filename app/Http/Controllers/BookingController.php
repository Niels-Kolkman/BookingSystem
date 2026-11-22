<?php

namespace App\Http\Controllers;

use App\Bookings\BookingRefused;
use App\Bookings\CreateBooking;
use App\Http\Requests\StoreBookingRequest;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Scheduling\DutchHolidays;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BookingController extends Controller
{
    /**
     * Bookings the current user may see: own bookings for a customer, own
     * agenda for an employee, everything for an admin.
     */
    public function index(Request $request): View
    {
        /** @var User $user */
        $user = $request->user();

        $bookings = Booking::query()
            ->visibleTo($user)
            ->with(['customer', 'employee', 'service'])
            ->orderBy('starts_at')
            ->paginate(25);

        return view('bookings.index', [
            'title' => $user->isCustomer() ? 'Mijn boekingen' : 'Boekingen',
            'bookings' => $bookings,
        ]);
    }

    /**
     * A customer books a free slot (ISSUE-12). Only customers reach this
     * (`role:customer` on the route). A refused booking goes back to the
     * form with the reason on the field it belongs to.
     */
    public function store(StoreBookingRequest $request, CreateBooking $createBooking): RedirectResponse
    {
        /** @var User $customer */
        $customer = $request->user();

        try {
            $booking = $createBooking->handle(
                $customer,
                Service::query()->findOrFail($request->integer('service_id')),
                Employee::query()->findOrFail($request->integer('employee_id')),
                $request->startsAt(),
                $request->validated('notes'),
            );
        } catch (BookingRefused $refused) {
            return back()->withInput()->withErrors([$refused->field => $refused->getMessage()]);
        }

        $start = $booking->starts_at->setTimezone(DutchHolidays::TIMEZONE);

        return redirect()->route('bookings.show', $booking)
            ->with('status', 'Je afspraak op '.$start->format('d-m-Y').' om '.$start->format('H:i').' is geboekt.');
    }

    /**
     * Access is checked by the `can:view,booking` route middleware.
     */
    public function show(Booking $booking): View
    {
        $booking->load(['customer', 'employee', 'service']);

        return view('bookings.show', ['booking' => $booking]);
    }
}
