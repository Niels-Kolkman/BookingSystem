<?php

namespace App\Bookings;

use App\Enums\BookingStatus;
use App\Models\Booking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A customer or an admin cancels a booking (ISSUE-16). No HTTP here;
 * BookingController calls it with resolved models.
 *
 * Who may cancel and until when is decided by BookingPolicy::cancel() (a
 * customer until Booking::cancellationDeadline(), an admin always). It is
 * checked here, on the booking row locked with SELECT ... FOR UPDATE, so
 * two cancellations at once cannot both pass and a booking that was just
 * cancelled elsewhere is not overwritten.
 *
 * A cancelled booking no longer blocks its time (BookingStatus::blocking()),
 * so AvailableSlots offers the slot again and CreateBooking accepts it.
 */
final readonly class CancelBooking
{
    public const REASON_MAX_LENGTH = 255;

    /**
     * @throws CancellationRefused when the policy denies it; a booking the
     *                             user may not see is refused as not found
     */
    public function handle(User $by, Booking $booking, ?string $reason = null): Booking
    {
        return DB::transaction(function () use ($by, $booking, $reason): Booking {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->firstOrFail();

            $decision = Gate::forUser($by)->inspect('cancel', $locked);
            if ($decision->denied()) {
                throw CancellationRefused::because(
                    $decision->message() ?? 'Deze afspraak kan niet worden geannuleerd.',
                    notFound: $decision->status() === 404,
                );
            }

            $locked->status = BookingStatus::Cancelled;
            $locked->cancelled_at = CarbonImmutable::now();
            $locked->cancelled_by = $by->id;
            $locked->cancellation_reason = filled($reason) ? trim($reason) : null;
            $locked->save();

            return $locked;
        });
    }
}
