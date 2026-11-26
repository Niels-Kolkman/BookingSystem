<?php

namespace App\Bookings;

use App\Enums\BookingStatus;
use App\Jobs\SendBookingReminder;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Finds the appointments that are due for a reminder and queues one mail
 * per booking (ISSUE-20). Run every hour by `bookings:send-reminders`
 * (routes/console.php).
 *
 * Due: the booking still takes up time (requested or confirmed), starts
 * after now and at most `booking.reminder_hours_before` hours from now,
 * and has no `reminder_sent_at`. A run that was missed is caught up by the
 * next one, as long as the appointment has not started.
 *
 * Never twice: each booking is claimed with one conditional UPDATE that
 * sets `reminder_sent_at` only while it is still empty (and the booking
 * still has the time and status it was found with). Only the run whose
 * update changed the row queues the mail, so two runs at the same time, or
 * a run that is repeated, cannot send a second reminder. Moving a booking
 * (RescheduleBooking) empties `reminder_sent_at`, so the new time gets its
 * own reminder.
 *
 * Not reminded: a booking made when the reminder moment had already passed
 * (booked less than `reminder_hours_before` hours ahead). The customer just
 * had the confirmation mail (ISSUE-19), and a "reminder" right after it is
 * a second mail for the same thing. A booking moved into the window does
 * get one, for its new time.
 */
final class QueueBookingReminders
{
    /**
     * @return int the number of reminders queued
     */
    public function handle(): int
    {
        $now = CarbonImmutable::now()->utc();
        $hours = (int) config('booking.reminder_hours_before');
        $statuses = array_map(fn (BookingStatus $s): string => $s->value, BookingStatus::blocking());

        $queued = 0;

        Booking::query()
            ->whereIn('status', $statuses)
            ->whereNull('reminder_sent_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours($hours))
            ->lazyById(100)
            ->each(function (Booking $booking) use ($now, $hours, $statuses, &$queued): void {
                if ($booking->created_at !== null && $booking->created_at->greaterThan($booking->starts_at->subHours($hours))) {
                    return;
                }

                $claimed = Booking::query()
                    ->whereKey($booking->getKey())
                    ->whereNull('reminder_sent_at')
                    ->whereIn('status', $statuses)
                    ->where('starts_at', $booking->starts_at->utc())
                    ->update(['reminder_sent_at' => $now]);

                if ($claimed !== 1) {
                    return;
                }

                try {
                    SendBookingReminder::dispatch($booking, $booking->starts_at);
                } catch (Throwable $e) {
                    // The queue refused the job: release the claim so the
                    // next run tries again instead of never reminding.
                    Booking::query()
                        ->whereKey($booking->getKey())
                        ->where('reminder_sent_at', $now)
                        ->update(['reminder_sent_at' => null]);

                    throw $e;
                }

                $queued++;
            });

        return $queued;
    }
}
