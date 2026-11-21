<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\User;
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
     * Access is checked by the `can:view,booking` route middleware.
     */
    public function show(Booking $booking): View
    {
        $booking->load(['customer', 'employee', 'service']);

        return view('bookings.show', ['booking' => $booking]);
    }
}
