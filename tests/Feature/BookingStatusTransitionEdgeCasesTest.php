<?php

use App\Bookings\InvalidStatusTransition;
use App\Enums\BookingStatus;
use App\Models\Booking;

/*
 * Edge cases of the booking state machine (ISSUE-18): how the status is
 * set, what happens after a refused save, stale copies, and which writes
 * skip the check. The per-transition tests are in
 * BookingStatusTransitionTest.
 */

function bseStored(BookingStatus $status): Booking
{
    return Booking::factory()->create(['status' => $status])->fresh() ?? throw new LogicException('Booking not stored');
}

describe('setting the status', function () {
    it('checks a status given as its string value too', function () {
        $booking = bseStored(BookingStatus::Requested);
        $booking->setAttribute('status', 'completed');

        expect(fn () => $booking->save())->toThrow(InvalidStatusTransition::class);
        expect($booking->fresh()?->status)->toBe(BookingStatus::Requested);
    });

    it('saves an allowed status given as its string value', function () {
        $booking = bseStored(BookingStatus::Requested);
        $booking->setAttribute('status', 'confirmed');
        $booking->save();

        expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });

    it('does not check a status changed and changed back before saving', function () {
        $booking = bseStored(BookingStatus::Completed);
        $booking->status = BookingStatus::Requested;
        $booking->status = BookingStatus::Completed;
        $booking->notes = 'teruggezet';
        $booking->save();

        expect($booking->fresh()?->status)->toBe(BookingStatus::Completed)
            ->and($booking->fresh()?->notes)->toBe('teruggezet');
    });

    it('checks saving against the stored status, skipping in-memory steps', function () {
        // Confirmed -> Cancelled -> Requested in memory: saving compares the
        // stored Confirmed with Requested, which is refused.
        $booking = bseStored(BookingStatus::Confirmed);
        $booking->transitionTo(BookingStatus::Cancelled);
        $booking->status = BookingStatus::Requested;

        try {
            $booking->save();
        } catch (InvalidStatusTransition $e) {
            expect($e->from)->toBe(BookingStatus::Confirmed)
                ->and($e->to)->toBe(BookingStatus::Requested)
                ->and($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);

            return;
        }

        $this->fail('Expected InvalidStatusTransition');
    });
});

describe('after a refused save', function () {
    it('keeps the stored status as the starting point, so a valid transition still saves', function () {
        $booking = bseStored(BookingStatus::Confirmed);
        $booking->status = BookingStatus::Requested;
        expect(fn () => $booking->save())->toThrow(InvalidStatusTransition::class);

        $booking->status = BookingStatus::NoShow;
        $booking->save();

        expect($booking->fresh()?->status)->toBe(BookingStatus::NoShow);
    });

    it('checks the next transition from the newly saved status', function () {
        $booking = bseStored(BookingStatus::Confirmed);
        $booking->transitionTo(BookingStatus::NoShow);
        $booking->save();

        // Confirmed -> Completed would be allowed, but the booking is now a no-show.
        $booking->status = BookingStatus::Completed;

        expect(fn () => $booking->save())->toThrow(InvalidStatusTransition::class);
        expect($booking->fresh()?->status)->toBe(BookingStatus::NoShow);
    });
});

describe('a stale copy', function () {
    it('is checked against the status it was loaded with, not the stored one', function () {
        // Documented in Booking::booted(): actions must lock and reload, as
        // CancelBooking does. This pins that the model alone does not, so a
        // stale copy can bring a cancelled booking back.
        $booking = bseStored(BookingStatus::Requested);
        $stale = Booking::query()->findOrFail($booking->id);

        $booking->transitionTo(BookingStatus::Cancelled);
        $booking->save();

        $stale->transitionTo(BookingStatus::Confirmed);
        $stale->save();

        expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });

    it('is refused once reloaded', function () {
        $booking = bseStored(BookingStatus::Requested);
        $stale = Booking::query()->findOrFail($booking->id);

        $booking->transitionTo(BookingStatus::Cancelled);
        $booking->save();

        expect(fn () => $stale->refresh()->transitionTo(BookingStatus::Confirmed))
            ->toThrow(InvalidStatusTransition::class);
    });
});

describe('writes that skip model events', function () {
    // Not checked. Pinned so that a change in this behaviour is noticed.
    it('does not check a mass update through the query builder', function () {
        $booking = bseStored(BookingStatus::Completed);

        Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Requested->value]);

        expect($booking->fresh()?->status)->toBe(BookingStatus::Requested);
    });

    it('does not check saveQuietly()', function () {
        $booking = bseStored(BookingStatus::Cancelled);
        $booking->status = BookingStatus::Confirmed;
        $booking->saveQuietly();

        expect($booking->fresh()?->status)->toBe(BookingStatus::Confirmed);
    });
});
