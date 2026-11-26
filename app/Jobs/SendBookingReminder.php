<?php

namespace App\Jobs;

use App\Mail\BookingReminder;
use App\Models\Booking;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the reminder mail of one booking on the queue (ISSUE-20).
 * App\Bookings\QueueBookingReminders dispatches it once it has claimed the
 * booking by setting `reminder_sent_at`.
 *
 * The worker loads the booking fresh and sends nothing when the reminder
 * is no longer right:
 * - the booking no longer takes up time (cancelled in the meantime);
 * - it was moved after it was claimed: the move emptied
 *   `reminder_sent_at`, or its start differs from the one claimed. A later
 *   run reminds it for the new time, so sending here would be a second
 *   mail;
 * - the appointment has already started (the job waited too long, e.g.
 *   after retries).
 *
 * Retries as SendBookingConfirmation does; after the last try the job
 * lands in `failed_jobs`. The claim stays, so no later run sends it again.
 */
class SendBookingReminder implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    public bool $deleteWhenMissingModels = true;

    /**
     * @param  CarbonImmutable  $startsAt  the start the reminder was claimed for
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly CarbonImmutable $startsAt,
    ) {}

    /**
     * Seconds to wait before each retry.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function handle(): void
    {
        $booking = $this->booking;

        if (! $booking->status->blocksTime()
            || $booking->getAttribute('reminder_sent_at') === null
            || ! $booking->starts_at->equalTo($this->startsAt)
            || ! $booking->starts_at->isFuture()) {
            return;
        }

        Mail::to($booking->customer)->send(new BookingReminder($booking));
    }
}
