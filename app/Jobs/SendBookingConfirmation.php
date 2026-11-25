<?php

namespace App\Jobs;

use App\Mail\BookingConfirmation;
use App\Models\Booking;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Mail;

/**
 * Sends the confirmation mail of a new booking on the queue (ISSUE-19), so
 * booking does not wait for the mail server and a mail failure does not
 * undo the booking. CreateBooking dispatches it.
 *
 * - After commit: the job is only queued once the booking is committed, so
 *   a rolled-back booking never gets a mail, even when CreateBooking runs
 *   inside an outer transaction.
 * - The worker loads the booking fresh, so the mail shows its time at that
 *   moment (it may have been moved already). A booking that no longer
 *   takes up time (cancelled in the meantime) gets no confirmation.
 * - A mail server that is down is retried a few times with a growing
 *   pause; after the last try the job lands in `failed_jobs`.
 */
class SendBookingConfirmation implements ShouldQueueAfterCommit
{
    use Queueable;

    public int $tries = 5;

    /**
     * Bookings are never deleted (they are cancelled), but if one is, there
     * is nothing to confirm.
     */
    public bool $deleteWhenMissingModels = true;

    public function __construct(
        public readonly Booking $booking,
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
        if (! $this->booking->status->blocksTime()) {
            return;
        }

        Mail::to($this->booking->customer)->send(new BookingConfirmation($this->booking));
    }
}
