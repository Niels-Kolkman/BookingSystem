<?php

use App\Scheduling\BusyPeriod;
use App\Scheduling\Slot;
use App\Scheduling\SlotCalculator;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;

/*
 * ISSUE-11: free start times from working ranges, existing bookings and
 * buffer time. Pure logic, no database. Unless a test says otherwise the
 * date is Tuesday 2026-12-01 (winter time, UTC+1) and the interval 15 min.
 */

function slotLocal(string $time, string $date = '2026-12-01'): CarbonImmutable
{
    return CarbonImmutable::parse("{$date} {$time}", 'Europe/Amsterdam');
}

/**
 * A busy period between two local times, e.g. an existing booking.
 */
function slotBusy(string $from, string $until, string $date = '2026-12-01'): BusyPeriod
{
    return new BusyPeriod(slotLocal($from, $date)->utc(), slotLocal($until, $date)->utc());
}

/**
 * @param  list<string>  $ranges  "HH:MM-HH:MM", local working time
 * @param  list<BusyPeriod>  $busy
 * @return list<Slot>
 */
function computeSlots(
    array $ranges,
    int $duration,
    int $buffer = 0,
    array $busy = [],
    int $interval = 15,
    ?CarbonImmutable $notBefore = null,
    string $date = '2026-12-01',
): array {
    return SlotCalculator::calculate(
        date: CarbonImmutable::parse($date, 'Europe/Amsterdam'),
        workingRanges: array_map(fn (string $r): TimeRange => TimeRange::fromTimes(...explode('-', $r)), $ranges),
        durationMinutes: $duration,
        bufferMinutes: $buffer,
        busy: $busy,
        intervalMinutes: $interval,
        notBefore: $notBefore,
    );
}

/**
 * @param  list<Slot>  $slots
 * @return list<string>
 */
function slotStarts(array $slots): array
{
    return array_map(fn (Slot $slot): string => $slot->label(), $slots);
}

describe('working time', function () {
    it('steps through a working range by the interval', function () {
        expect(slotStarts(computeSlots(['09:00-10:00'], duration: 30)))
            ->toBe(['09:00', '09:15', '09:30']);
    });

    it('only offers a start when the whole appointment fits in the range', function () {
        expect(slotStarts(computeSlots(['09:00-10:00'], duration: 60)))->toBe(['09:00'])
            ->and(computeSlots(['09:00-09:20'], duration: 30))->toBe([]);
    });

    it('offers slots in every block of a split shift, not across the break', function () {
        expect(slotStarts(computeSlots(['09:00-10:00', '13:30-14:30'], duration: 60)))
            ->toBe(['09:00', '13:30']);
    });

    it('returns nothing on a day without working time', function () {
        expect(computeSlots([], duration: 30))->toBe([]);
    });

    it('anchors the steps at the start of each range', function () {
        expect(slotStarts(computeSlots(['09:10-10:00'], duration: 20)))
            ->toBe(['09:10', '09:25', '09:40']);
    });

    it('uses an interval longer than the duration', function () {
        expect(slotStarts(computeSlots(['09:00-11:00'], duration: 30, interval: 60)))
            ->toBe(['09:00', '10:00']);
    });

    it('can offer the last appointment of the day up to midnight', function () {
        // Extra hours "until the end of the day" end at 1440 (24:00), see TimeRange.
        $slots = SlotCalculator::calculate(
            date: slotLocal('00:00'),
            workingRanges: [new TimeRange(23 * 60, TimeRange::MINUTES_PER_DAY)],
            durationMinutes: 30,
            bufferMinutes: 0,
            busy: [],
            intervalMinutes: 30,
        );

        expect(slotStarts($slots))->toBe(['23:00', '23:30'])
            ->and($slots[1]->endsAt->format('Y-m-d H:i'))->toBe('2026-12-01 23:00');
    });

    it('returns the slots in time order whatever the order of the ranges', function () {
        expect(slotStarts(computeSlots(['13:00-14:00', '09:00-10:00'], duration: 60)))
            ->toBe(['09:00', '13:00']);
    });
});

describe('existing bookings and buffer', function () {
    it('skips starts that overlap an existing booking', function () {
        expect(slotStarts(computeSlots(['09:00-11:00'], duration: 30, busy: [slotBusy('09:30', '10:00')])))
            ->toBe(['09:00', '10:00', '10:15', '10:30']);
    });

    it('allows a slot that touches a booking on either side', function () {
        $slots = computeSlots(['09:00-10:30'], duration: 30, interval: 30, busy: [slotBusy('09:30', '10:00')]);

        expect(slotStarts($slots))->toBe(['09:00', '10:00']);
    });

    it('keeps the buffer of the new appointment free before the next booking', function () {
        // 09:15 + 30 min + 15 min buffer = 10:00, still free; 09:30 would run into the booking.
        expect(slotStarts(computeSlots(['09:00-11:00'], duration: 30, buffer: 15, busy: [slotBusy('10:00', '10:30')])))
            ->toBe(['09:00', '09:15', '10:30']);
    });

    it('respects the buffer of an existing booking (its blocked_until)', function () {
        // Existing booking 09:00-09:30 plus 15 min buffer: blocked until 09:45.
        expect(slotStarts(computeSlots(['09:00-10:30'], duration: 30, busy: [slotBusy('09:00', '09:45')])))
            ->toBe(['09:45', '10:00']);
    });

    it('lets the buffer run past the end of working time', function () {
        expect(slotStarts(computeSlots(['09:00-10:00'], duration: 30, buffer: 15)))
            ->toBe(['09:00', '09:15', '09:30']);
    });

    it('does not let the buffer run into a booking after working time', function () {
        expect(slotStarts(computeSlots(['09:00-10:00'], duration: 30, buffer: 15, busy: [slotBusy('10:00', '11:00')])))
            ->toBe(['09:00', '09:15']);
    });

    it('returns nothing when bookings fill the whole day', function () {
        expect(computeSlots(['09:00-12:00'], duration: 30, busy: [slotBusy('09:00', '10:30'), slotBusy('10:30', '12:00')]))
            ->toBe([]);
    });

    it('ignores busy periods on other days', function () {
        expect(slotStarts(computeSlots(['09:00-10:00'], duration: 60, busy: [slotBusy('09:00', '10:00', '2026-12-02')])))
            ->toBe(['09:00']);
    });
});

describe('time', function () {
    it('leaves out starts before the given moment, keeping one exactly at it', function () {
        expect(slotStarts(computeSlots(['09:00-10:30'], duration: 30, notBefore: slotLocal('09:20'))))
            ->toBe(['09:30', '09:45', '10:00'])
            ->and(slotStarts(computeSlots(['09:00-10:00'], duration: 30, notBefore: slotLocal('09:30'))))
            ->toBe(['09:30']);
    });

    it('returns UTC moments for start, end and blocked_until in winter time', function () {
        [$slot] = computeSlots(['09:00-10:00'], duration: 45, buffer: 10, interval: 60);

        expect($slot->startsAt->getTimezone()->getName())->toBe('UTC')
            ->and($slot->startsAt->format('Y-m-d H:i'))->toBe('2026-12-01 08:00')
            ->and($slot->endsAt->format('Y-m-d H:i'))->toBe('2026-12-01 08:45')
            ->and($slot->blockedUntil->format('Y-m-d H:i'))->toBe('2026-12-01 08:55')
            ->and($slot->label())->toBe('09:00');
    });

    it('converts with the summer offset in summer time', function () {
        [$slot] = computeSlots(['09:00-10:00'], duration: 60, date: '2027-06-15');

        expect($slot->startsAt->format('Y-m-d H:i'))->toBe('2027-06-15 07:00')
            ->and($slot->localStart()->format('Y-m-d H:i'))->toBe('2027-06-15 09:00');
    });
});

describe('invalid input', function () {
    it('rejects a duration, buffer or interval out of range', function (int $duration, int $buffer, int $interval) {
        computeSlots(['09:00-10:00'], duration: $duration, buffer: $buffer, interval: $interval);
    })->throws(InvalidArgumentException::class)->with([
        'no duration' => [0, 0, 15],
        'negative buffer' => [30, -5, 15],
        'no interval' => [30, 0, 0],
    ]);

    it('rejects an empty or inverted busy period', function () {
        slotBusy('10:00', '10:00');
    })->throws(InvalidArgumentException::class);
});
