<?php

namespace App\Policies;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;

/**
 * Who may see a booking:
 * - a customer only their own bookings,
 * - an employee only the bookings in their own agenda,
 * - an admin every booking.
 *
 * Lists must use Booking::visibleTo(), which applies the same rules in SQL.
 *
 * Who may cancel a booking (ISSUE-16), see cancel().
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

    /**
     * Only a requested or confirmed booking can be cancelled; cancelling
     * frees its time (BookingStatus::blocking()).
     * - a customer: their own booking, until Booking::cancellationDeadline();
     * - an admin: any booking, also after the deadline;
     * - an employee: never, they ask an admin.
     *
     * Someone else's booking answers 404, as in view(). The deny messages
     * are Dutch and meant for the user; CancelBooking passes them on.
     */
    public function cancel(User $user, Booking $booking): Response
    {
        if (! $this->canSee($user, $booking)) {
            return Response::denyAsNotFound();
        }

        if (! $booking->status->blocksTime()) {
            return Response::deny($booking->status === BookingStatus::Cancelled
                ? 'Deze afspraak is al geannuleerd.'
                : 'Deze afspraak kan niet meer worden geannuleerd.');
        }

        if ($user->isAdmin()) {
            return Response::allow();
        }

        if (! $user->isCustomer()) {
            return Response::deny('Alleen de klant of de beheerder kan deze afspraak annuleren.');
        }

        return CarbonImmutable::now()->lessThanOrEqualTo($booking->cancellationDeadline())
            ? Response::allow()
            : Response::deny(self::pastDeadlineMessage());
    }

    /**
     * Shown to a customer whose booking is past the cancellation deadline.
     */
    public static function pastDeadlineMessage(): string
    {
        $hours = (int) config('booking.cancellation_window_hours');
        $until = $hours === 0 ? 'tot de afspraak begint' : "tot {$hours} uur van tevoren";

        return "Online annuleren kan {$until}. Neem contact met ons op om deze afspraak te annuleren.";
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
