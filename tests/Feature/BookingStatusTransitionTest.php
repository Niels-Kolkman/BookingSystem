<?php

use App\Bookings\InvalidStatusTransition;
use App\Enums\BookingStatus;
use App\Models\Booking;

/*
 * Booking enforces the state machine of BookingStatus on every save
 * (ISSUE-18). The 'status transitions' dataset (every pair of statuses) is
 * in tests/Datasets.
 */

function bstStored(BookingStatus $status): Booking
{
    return Booking::factory()->create(['status' => $status])->fresh() ?? throw new LogicException('Booking not stored');
}

describe('transitionTo', function () {
    it('changes the status only along an allowed transition', function (BookingStatus $from, BookingStatus $to, bool $allowed) {
        $booking = bstStored($from);

        if ($allowed) {
            $booking->transitionTo($to);
            $booking->save();

            expect($booking->fresh()?->status)->toBe($to);

            return;
        }

        expect(fn () => $booking->transitionTo($to))->toThrow(InvalidStatusTransition::class);
        expect($booking->status)->toBe($from)
            ->and($booking->fresh()?->status)->toBe($from);
    })->with('status transitions');

    it('does not save by itself', function () {
        $booking = bstStored(BookingStatus::Requested);

        $booking->transitionTo(BookingStatus::Confirmed);

        expect($booking->fresh()?->status)->toBe(BookingStatus::Requested);
    });

    it('says in Dutch which transition was refused', function () {
        try {
            bstStored(BookingStatus::Cancelled)->transitionTo(BookingStatus::Confirmed);
        } catch (InvalidStatusTransition $e) {
            expect($e->getMessage())->toBe("De status van een afspraak kan niet van 'geannuleerd' naar 'bevestigd' gaan.")
                ->and($e->from)->toBe(BookingStatus::Cancelled)
                ->and($e->to)->toBe(BookingStatus::Confirmed);

            return;
        }

        $this->fail('Expected InvalidStatusTransition');
    });
});

describe('saving', function () {
    it('refuses an invalid transition however the status was set', function (BookingStatus $from, BookingStatus $to, bool $allowed) {
        $booking = bstStored($from);
        $booking->status = $to;
        $booking->notes = 'gewijzigd';

        if ($from === $to || $allowed) {
            // Keeping the status is not a transition, so it is not checked.
            $booking->save();

            expect($booking->fresh()?->status)->toBe($to)
                ->and($booking->fresh()?->notes)->toBe('gewijzigd');

            return;
        }

        expect(fn () => $booking->save())->toThrow(InvalidStatusTransition::class);
        // Nothing of the update is stored, also not the other attributes.
        expect($booking->fresh()?->status)->toBe($from)
            ->and($booking->fresh()?->notes)->toBeNull();
    })->with('status transitions');

    it('refuses an invalid status through update()', function () {
        $booking = bstStored(BookingStatus::Completed);

        expect(fn () => $booking->forceFill(['status' => BookingStatus::Requested])->save())
            ->toThrow(InvalidStatusTransition::class);
        expect($booking->fresh()?->status)->toBe(BookingStatus::Completed);
    });

    it('lets a new booking start in any status', function (BookingStatus $status) {
        expect(Booking::factory()->create(['status' => $status])->fresh()?->status)->toBe($status);
    })->with('booking statuses');

    it('follows the whole path from request to completed', function () {
        $booking = bstStored(BookingStatus::Requested);

        $booking->transitionTo(BookingStatus::Confirmed);
        $booking->save();
        $booking->transitionTo(BookingStatus::Completed);
        $booking->save();

        expect($booking->fresh()?->status)->toBe(BookingStatus::Completed)
            ->and(fn () => $booking->transitionTo(BookingStatus::Cancelled))->toThrow(InvalidStatusTransition::class);
    });
});
