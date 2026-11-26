<?php

namespace App\Mail;

use App\Models\Booking;
use App\Scheduling\LocalTime;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Reminder mail to the customer before the appointment (ISSUE-20).
 *
 * Not queued itself: App\Jobs\SendBookingReminder queues the sending and
 * decides whether the reminder is still due when the worker runs.
 */
class BookingReminder extends Mailable
{
    public function __construct(
        public readonly Booking $booking,
    ) {
        $this->booking->loadMissing(['customer', 'employee', 'service']);
    }

    public function envelope(): Envelope
    {
        $start = LocalTime::toLocal($this->booking->starts_at);

        return new Envelope(
            subject: 'Herinnering: je afspraak op '.$start->format('d-m-Y').' om '.LocalTime::time($start),
        );
    }

    public function content(): Content
    {
        $deadline = $this->booking->cancellationDeadline();

        return new Content(
            markdown: 'mail.booking-reminder',
            with: [
                'booking' => $this->booking,
                'url' => route('bookings.show', $this->booking),
                'cancellationDeadline' => $deadline->isFuture() ? $deadline : null,
            ],
        );
    }
}
