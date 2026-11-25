<?php

namespace App\Mail;

use App\Bookings\BookingCalendarEvent;
use App\Models\Booking;
use App\Scheduling\LocalTime;
use Illuminate\Mail\Attachment;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Confirmation mail to the customer after booking (ISSUE-19), with the
 * appointment as an .ics file (BookingCalendarEvent).
 *
 * Not queued itself: App\Jobs\SendBookingConfirmation queues the sending
 * and decides whether the mail is still due when the worker runs.
 */
class BookingConfirmation extends Mailable
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
            subject: 'Bevestiging van je afspraak op '.$start->format('d-m-Y').' om '.LocalTime::time($start),
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.booking-confirmation',
            with: [
                'booking' => $this->booking,
                'url' => route('bookings.show', $this->booking),
            ],
        );
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromData(fn (): string => BookingCalendarEvent::for($this->booking), BookingCalendarEvent::FILENAME)
                ->withMime(BookingCalendarEvent::MIME_TYPE),
        ];
    }
}
