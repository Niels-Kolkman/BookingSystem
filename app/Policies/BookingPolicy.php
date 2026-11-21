<?php

namespace App\Policies;

use App\Models\Booking;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Who may see a booking:
 * - a customer only their own bookings,
 * - an employee only the bookings in their own agenda,
 * - an admin every booking.
 *
 * Lists must use Booking::visibleTo(), which applies the same rules in SQL.
 */
class BookingPolicy
{
    /**
     * Every logged-in user has a bookings list; its contents are limited by
     * Booking::visibleTo().
     */
    public function viewAny(User $user): bool
    {
        return true;
    }

    /**
     * Someone else's booking answers 404 instead of 403, so booking ids
     * cannot be probed to find out which ones exist.
     */
    public function view(User $user, Booking $booking): Response
    {
        return $this->canSee($user, $booking)
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    private function canSee(User $user, Booking $booking): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($user->isEmployee()) {
            return $booking->employee()->where('user_id', $user->id)->exists();
        }

        // Cast: some PDO drivers return integer columns as strings.
        return (int) $booking->customer_id === (int) $user->id;
    }
}
