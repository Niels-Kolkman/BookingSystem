<?php

use App\Scheduling\BusyPeriod;
use App\Scheduling\Slot;
use App\Scheduling\SlotCalculator;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;

/*
 * ISSUE-14: free start times on the daylight-saving switch nights of 2026.
 * - Sunday 2026-03-29: 02:00 local (01:00 UTC) jumps to 03:00.
 * - Sunday 2026-10-25: 03:00 local (01:00 UTC) goes back to 02:00.
 * Start times step in real time, so the skipped hour offers nothing and the
 * repeated hour is offered twice, with labels that tell the two apart.
 */

const DST_SPRING = '2026-03-29';
const DST_AUTUMN = '2026-10-25';

/**
 * @param  list<string>  $ranges  "HH:MM-HH:MM", local working time
 * @param  list<BusyPeriod>  $busy
 * @return list<Slot>
 */
function dstSlots(string $date, array $ranges, int $duration, int $interval = 15, int $buffer = 0, array $busy = []): array
{
    return SlotCalculator::calculate(
        date: CarbonImmutable::parse($date, 'Europe/Amsterdam'),
        workingRanges: array_map(
            fn (string $r): TimeRange => $r === '00:00-24:00' ? TimeRange::wholeDay() : TimeRange::fromTimes(...explode('-', $r)),
            $ranges,
        ),
        durationMinutes: $duration,
        bufferMinutes: $buffer,
        busy: $busy,
        intervalMinutes: $interval,
    );
}

/**
 * @param  list<Slot>  $slots
 * @return list<string>
 */
function dstLabels(array $slots): array
{
    return array_map(fn (Slot $slot): string => $slot->label(), $slots);
}

/**
 * @param  list<Slot>  $slots
 * @return list<string>
 */
function dstUtcStarts(array $slots): array
{
    return array_map(fn (Slot $slot): string => $slot->startsAt->format('H:i'), $slots);
}

function dstUtc(string $dateTime): CarbonImmutable
{
    return CarbonImmutable::parse($dateTime, 'UTC');
}

describe('clock jumps forward (last Sunday of March)', function () {
    it('offers no start time between 02:00 and 03:00', function () {
        $slots = dstSlots(DST_SPRING, ['01:00-04:00'], duration: 30, interval: 30);

        expect(dstLabels($slots))->toBe(['01:00', '01:30', '03:00', '03:30'])
            // In real time the starts follow on without a gap.
            ->and(dstUtcStarts($slots))->toBe(['00:00', '00:30', '01:00', '01:30']);
    });

    it('lets an appointment run through the jump in real minutes', function () {
        $slots = dstSlots(DST_SPRING, ['01:00-04:00'], duration: 60, interval: 30);
        $over = $slots[1];

        expect(dstLabels($slots))->toBe(['01:00', '01:30', '03:00'])
            ->and($over->label())->toBe('01:30')
            ->and($over->endsAt->diffInMinutes($over->startsAt, true))->toEqual(60)
            ->and($over->endsAt->setTimezone('Europe/Amsterdam')->format('H:i'))->toBe('03:30');
    });

    it('ends a range that ends in the skipped hour at the jump', function () {
        // Working "until 02:30": the clock never shows 02:30, it jumps from
        // 02:00 to 03:00, so the last appointment must be done by then.
        $slots = dstSlots(DST_SPRING, ['01:00-02:30'], duration: 30, interval: 15);

        expect(dstLabels($slots))->toBe(['01:00', '01:15', '01:30'])
            ->and(last($slots)->endsAt->format('H:i e'))->toBe('01:00 UTC');
    });

    it('starts a range that starts in the skipped hour at the jump', function () {
        $slots = dstSlots(DST_SPRING, ['02:30-04:00'], duration: 30, interval: 30);

        expect(dstLabels($slots))->toBe(['03:00', '03:30']);
    });

    it('offers nothing for a range entirely inside the skipped hour', function () {
        expect(dstSlots(DST_SPRING, ['02:00-02:45'], duration: 15))->toBe([]);
    });

    it('offers 23 hours of starts on a whole-day range', function () {
        $slots = dstSlots(DST_SPRING, ['00:00-24:00'], duration: 60, interval: 60);

        expect($slots)->toHaveCount(23)
            ->and(dstLabels($slots))->not->toContain('02:00');
    });

    it('leaves a normal daytime schedule alone', function () {
        expect(dstLabels(dstSlots(DST_SPRING, ['09:00-10:00'], duration: 30, interval: 30)))->toBe(['09:00', '09:30'])
            ->and(dstUtcStarts(dstSlots(DST_SPRING, ['09:00-10:00'], duration: 30, interval: 30)))->toBe(['07:00', '07:30']);
    });
});

describe('clock goes back (last Sunday of October)', function () {
    it('offers the repeated hour twice, labelled by summer and winter time', function () {
        $slots = dstSlots(DST_AUTUMN, ['01:00-04:00'], duration: 30, interval: 30);

        expect(dstLabels($slots))->toBe([
            '01:00', '01:30',
            '02:00 (zomertijd)', '02:30 (zomertijd)',
            '02:00 (wintertijd)', '02:30 (wintertijd)',
            '03:00', '03:30',
        ])->and(dstUtcStarts($slots))->toBe([
            '23:00', '23:30', '00:00', '00:30', '01:00', '01:30', '02:00', '02:30',
        ]);
    });

    it('gives every slot a unique moment and a unique label', function () {
        $slots = dstSlots(DST_AUTUMN, ['00:00-24:00'], duration: 15, interval: 15);
        $moments = array_map(fn (Slot $s): int => $s->startsAt->getTimestamp(), $slots);

        expect($slots)->toHaveCount(25 * 4)
            ->and(array_unique($moments))->toHaveCount(100)
            ->and(array_unique(dstLabels($slots)))->toHaveCount(100);
    });

    it('lets an appointment run through the change in real minutes', function () {
        // 02:30 summer time + 60 min = 02:30 winter time on the clock.
        $slot = collect(dstSlots(DST_AUTUMN, ['01:00-04:00'], duration: 60, interval: 30))
            ->first(fn (Slot $s): bool => $s->label() === '02:30 (zomertijd)');

        expect($slot)->not->toBeNull()
            ->and($slot->endsAt->diffInMinutes($slot->startsAt, true))->toEqual(60)
            ->and($slot->endsAt->format('H:i e'))->toBe('01:30 UTC');
    });

    it('keeps a booking in the first 02:00 hour from blocking the second', function () {
        // Existing booking 02:00-03:00 summer time (00:00-01:00 UTC).
        $busy = [new BusyPeriod(dstUtc('2026-10-25 00:00'), dstUtc('2026-10-25 01:00'))];

        $labels = dstLabels(dstSlots(DST_AUTUMN, ['01:00-04:00'], duration: 30, interval: 30, busy: $busy));

        expect($labels)->not->toContain('02:00 (zomertijd)')
            ->and($labels)->not->toContain('02:30 (zomertijd)')
            ->and($labels)->toContain('02:00 (wintertijd)')
            ->and($labels)->toContain('02:30 (wintertijd)');
    });

    it('applies the buffer in real minutes across the change', function () {
        // 30 min + 30 min buffer from 02:30 summer time blocks until 02:30
        // winter time, so the next start is 02:30 winter time.
        $busy = [new BusyPeriod(dstUtc('2026-10-25 00:30'), dstUtc('2026-10-25 01:30'))];

        expect(dstLabels(dstSlots(DST_AUTUMN, ['02:00-04:00'], duration: 30, interval: 30, busy: $busy)))
            ->toBe(['02:00 (zomertijd)', '02:30 (wintertijd)', '03:00', '03:30']);
    });

    it('ends a range that ends in the repeated hour at its first occurrence', function () {
        $slots = dstSlots(DST_AUTUMN, ['01:00-02:30'], duration: 30, interval: 30);

        expect(dstLabels($slots))->toBe(['01:00', '01:30', '02:00 (zomertijd)']);
    });

    it('offers 25 hours of starts on a whole-day range', function () {
        expect(dstSlots(DST_AUTUMN, ['00:00-24:00'], duration: 60, interval: 60))->toHaveCount(25);
    });
});
