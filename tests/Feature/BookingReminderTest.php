<?php

use App\Bookings\QueueBookingReminders;
use App\Enums\BookingStatus;
use App\Jobs\SendBookingReminder;
use App\Mail\BookingReminder;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

use function Pest\Laravel\artisan;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-20: an hourly job queues a reminder for every appointment that
 * starts within 24 hours, never twice for the same booking. "Now" is
 * Monday 2026-11-23 10:00 in Amsterdam (UTC+1).
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.reminder_hours_before' => 24, 'booking.cancellation_window_hours' => 24]);
});

/**
 * A booking at a local time, made a week ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function brBooking(string $startsAt, array $attributes = []): Booking
{
    $start = CarbonImmutable::parse($startsAt, 'Europe/Amsterdam');

    return Booking::factory()->create([
        'starts_at' => $start,
        'ends_at' => $start->addMinutes(30),
        'blocked_until' => $start->addMinutes(45),
        'created_at' => CarbonImmutable::now()->subWeek(),
        ...$attributes,
    ]);
}

function brRun(): int
{
    return app(QueueBookingReminders::class)->handle();
}

function brReminderSentAt(Booking $booking): ?CarbonImmutable
{
    return $booking->fresh()?->getAttribute('reminder_sent_at');
}

/**
 * A booking claimed by a run, with its job serialized as the queue holds it.
 *
 * @return array{Booking, string}
 */
function brClaimed(string $startsAt = '2026-11-24 09:15'): array
{
    Queue::fake();
    $booking = brBooking($startsAt);
    brRun();

    /** @var SendBookingReminder $job */
    $job = Queue::pushed(SendBookingReminder::class)->sole();

    return [$booking, serialize($job)];
}

/**
 * What the worker does: unserialize (which loads the booking fresh) and run.
 */
function brWork(string $payload): void
{
    /** @var SendBookingReminder $job */
    $job = unserialize($payload);
    $job->handle();
}

function brMailBooking(): Booking
{
    return brBooking('2026-11-24 09:15', [
        'customer_id' => User::factory()->customer()->create(['name' => 'Eva de Vries']),
        'employee_id' => Employee::factory()->create(['display_name' => 'Sanne']),
        'service_id' => Service::factory()->create(['name' => 'Knippen']),
        'status' => BookingStatus::Confirmed,
    ]);
}

describe('which bookings are reminded', function () {
    it('queues a reminder for an appointment within 24 hours and marks it', function () {
        Queue::fake();
        $booking = brBooking('2026-11-24 09:15');

        expect(brRun())->toBe(1);

        Queue::assertPushed(SendBookingReminder::class, 1);
        Queue::assertPushed(SendBookingReminder::class, fn (SendBookingReminder $job): bool => $job->booking->is($booking) && $job->startsAt->equalTo($booking->starts_at));
        expect(brReminderSentAt($booking)?->equalTo(CarbonImmutable::now()))->toBeTrue();
    });

    it('includes an appointment exactly 24 hours ahead and leaves out one a minute later', function () {
        Queue::fake();
        $due = brBooking('2026-11-24 10:00');
        $notYet = brBooking('2026-11-24 10:01');

        expect(brRun())->toBe(1)
            ->and(brReminderSentAt($due))->not->toBeNull()
            ->and(brReminderSentAt($notYet))->toBeNull();
    });

    it('reminds the later appointment one hour later, at the next run', function () {
        Queue::fake();
        $booking = brBooking('2026-11-24 10:30');
        expect(brRun())->toBe(0);

        travelTo(CarbonImmutable::parse('2026-11-23 11:00', 'Europe/Amsterdam'));

        expect(brRun())->toBe(1)
            ->and(brReminderSentAt($booking))->not->toBeNull();
    });

    it('catches up an appointment the previous runs missed, if it has not started', function () {
        Queue::fake();
        $soon = brBooking('2026-11-23 13:00');
        $started = brBooking('2026-11-23 10:00');
        $past = brBooking('2026-11-23 08:00');

        expect(brRun())->toBe(1)
            ->and(brReminderSentAt($soon))->not->toBeNull()
            ->and(brReminderSentAt($started))->toBeNull()
            ->and(brReminderSentAt($past))->toBeNull();
    });

    it('counts real hours across the daylight-saving switch', function () {
        Queue::fake();
        // Saturday 10:00 summer time; the clock goes back that night.
        travelTo(CarbonImmutable::parse('2026-10-24 10:00', 'Europe/Amsterdam'));
        $created = ['created_at' => CarbonImmutable::now()->subWeek()];
        // Sunday 09:30 on the clock, but 24.5 real hours away.
        $notYet = brBooking('2026-10-25 09:30', $created);
        // Sunday 08:45 on the clock: 23.75 real hours away.
        $due = brBooking('2026-10-25 08:45', $created);

        expect(brRun())->toBe(1)
            ->and(brReminderSentAt($due))->not->toBeNull()
            ->and(brReminderSentAt($notYet))->toBeNull();
    });

    it('reminds only bookings that still take up time', function (BookingStatus $status, bool $reminded) {
        Queue::fake();
        $booking = brBooking('2026-11-24 09:15', ['status' => $status]);

        expect(brRun())->toBe($reminded ? 1 : 0)
            ->and(brReminderSentAt($booking) !== null)->toBe($reminded);
    })->with([
        'requested' => [BookingStatus::Requested, true],
        'confirmed' => [BookingStatus::Confirmed, true],
        'cancelled' => [BookingStatus::Cancelled, false],
        'completed' => [BookingStatus::Completed, false],
        'no-show' => [BookingStatus::NoShow, false],
    ]);

    it('does not remind a booking made after its reminder moment', function () {
        Queue::fake();
        // Booked at 08:00 today for tomorrow 07:00: 23 hours ahead.
        $late = brBooking('2026-11-24 07:00', ['created_at' => CarbonImmutable::now()->subHours(2)]);
        // Booked exactly 24 hours ahead: on time.
        $onTime = brBooking('2026-11-24 09:30', ['created_at' => CarbonImmutable::parse('2026-11-23 09:30', 'Europe/Amsterdam')]);

        expect(brRun())->toBe(1)
            ->and(brReminderSentAt($late))->toBeNull()
            ->and(brReminderSentAt($onTime))->not->toBeNull();
    });

    it('uses the configured number of hours', function () {
        Queue::fake();
        config(['booking.reminder_hours_before' => 2]);
        $due = brBooking('2026-11-23 11:30');
        $notYet = brBooking('2026-11-23 12:30');

        expect(brRun())->toBe(1)
            ->and(brReminderSentAt($due))->not->toBeNull()
            ->and(brReminderSentAt($notYet))->toBeNull();
    });
});

describe('no double reminders', function () {
    it('queues nothing on a second run', function () {
        Queue::fake();
        brBooking('2026-11-24 09:15');

        expect(brRun())->toBe(1)
            ->and(brRun())->toBe(0);

        Queue::assertPushed(SendBookingReminder::class, 1);
    });

    it('sends one mail however often it runs', function () {
        Mail::fake();
        brBooking('2026-11-24 09:15');

        brRun();
        brRun();
        travelTo(CarbonImmutable::parse('2026-11-23 11:00', 'Europe/Amsterdam'));
        brRun();

        Mail::assertSent(BookingReminder::class, 1);
    });

    it('skips a booking that already had its reminder', function () {
        Queue::fake();
        brBooking('2026-11-24 09:15', ['reminder_sent_at' => CarbonImmutable::now()->subHour()]);

        expect(brRun())->toBe(0);

        Queue::assertNothingPushed();
    });

    it('queues nothing when another run claimed the booking first', function () {
        Queue::fake();
        $booking = brBooking('2026-11-24 09:15');
        // Another run claims the booking between our select and our claim.
        Booking::retrieved(function (Booking $found): void {
            DB::table('bookings')->where('id', $found->id)->update(['reminder_sent_at' => '2026-11-23 08:59:00']);
        });

        expect(brRun())->toBe(0);

        Queue::assertNothingPushed();
        expect(brReminderSentAt($booking)?->format('Y-m-d H:i:s'))->toBe('2026-11-23 08:59:00');
    });

    it('releases the claim when the queue refuses the job, so the next run tries again', function () {
        config([
            'queue.connections.broken' => ['driver' => 'database', 'table' => 'missing_jobs', 'queue' => 'default', 'retry_after' => 90],
            'queue.default' => 'broken',
        ]);
        $booking = brBooking('2026-11-24 09:15');

        expect(fn () => brRun())->toThrow(QueryException::class)
            ->and(brReminderSentAt($booking))->toBeNull();

        Queue::fake();
        expect(brRun())->toBe(1);
    });

    it('reminds a moved booking again for its new time', function () {
        Queue::fake();
        $booking = brBooking('2026-11-24 09:15');
        brRun();

        // RescheduleBooking moves it and empties reminder_sent_at (ISSUE-17).
        $moved = CarbonImmutable::parse('2026-11-24 09:45', 'Europe/Amsterdam');
        $booking = $booking->fresh();
        assert($booking instanceof Booking);
        $booking->starts_at = $moved;
        $booking->ends_at = $moved->addMinutes(30);
        $booking->blocked_until = $moved->addMinutes(45);
        $booking->setAttribute('reminder_sent_at', null);
        $booking->save();

        expect(brRun())->toBe(1);

        Queue::assertPushed(SendBookingReminder::class, 2);
        Queue::assertPushed(SendBookingReminder::class, fn (SendBookingReminder $job): bool => $job->startsAt->equalTo($moved));
    });
});

describe('the job', function () {
    it('is queued after commit and retried like the confirmation', function () {
        $job = new SendBookingReminder(new Booking, CarbonImmutable::now());

        expect($job)->toBeInstanceOf(ShouldQueueAfterCommit::class)
            ->and($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([30, 120, 600, 1800])
            ->and($job->deleteWhenMissingModels)->toBeTrue();
    });

    it('mails the reminder to the customer', function () {
        [$booking, $payload] = brClaimed();
        Mail::fake();

        brWork($payload);

        Mail::assertSent(BookingReminder::class, 1);
        Mail::assertSent(BookingReminder::class, fn (BookingReminder $mail): bool => $mail->hasTo($booking->customer->email) && $mail->booking->is($booking));
    });

    it('sends nothing when the booking was cancelled after it was claimed', function () {
        [$booking, $payload] = brClaimed();
        Mail::fake();
        Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Cancelled->value]);

        brWork($payload);

        Mail::assertNothingSent();
    });

    it('sends nothing when the booking was moved and its reminder reset after it was claimed', function () {
        [$booking, $payload] = brClaimed();
        Mail::fake();
        Booking::query()->whereKey($booking->id)->update(['starts_at' => '2026-11-24 08:00:00', 'reminder_sent_at' => null]);

        brWork($payload);

        Mail::assertNothingSent();
    });

    it('sends nothing when the start differs from the one claimed, even if claimed again', function () {
        [$booking, $payload] = brClaimed();
        Mail::fake();
        // Moved and claimed again for the new time by a later run, which
        // queued its own job.
        Booking::query()->whereKey($booking->id)->update(['starts_at' => '2026-11-24 08:00:00']);

        brWork($payload);

        Mail::assertNothingSent();
    });

    it('sends nothing when the appointment has already started', function () {
        [, $payload] = brClaimed();
        Mail::fake();
        travelTo(CarbonImmutable::parse('2026-11-24 09:15', 'Europe/Amsterdam'));

        brWork($payload);

        Mail::assertNothingSent();
    });
});

describe('the mail', function () {
    it('has a Dutch subject with the local date and time', function () {
        (new BookingReminder(brMailBooking()))->assertHasSubject('Herinnering: je afspraak op 24-11-2026 om 09:15');
    });

    it('shows the details of the appointment in local time', function () {
        $booking = brMailBooking();

        (new BookingReminder($booking))
            ->assertSeeInText('Herinnering aan je afspraak')
            ->assertSeeInHtml('Eva de Vries')
            ->assertSeeInHtml('Knippen')
            ->assertSeeInHtml('Sanne')
            ->assertSeeInHtml('24-11-2026 09:15 - 09:45')
            ->assertSeeInHtml('30 minuten')
            ->assertSeeInHtml('25,00')
            ->assertSeeInHtml('bevestigd')
            ->assertSeeInHtml(route('bookings.show', $booking));
    });

    it('asks the customer to get in touch once the cancellation deadline has passed', function () {
        (new BookingReminder(brMailBooking()))
            ->assertSeeInText('Kun je niet komen?')
            ->assertDontSeeInText('Annuleren of verplaatsen kan nog tot');
    });

    it('shows the cancellation deadline while it has not passed', function () {
        config(['booking.cancellation_window_hours' => 2]);

        (new BookingReminder(brMailBooking()))
            ->assertSeeInText('Annuleren of verplaatsen kan nog tot 24-11-2026 07:15')
            ->assertDontSeeInText('Kun je niet komen?');
    });
});

describe('the command and the schedule', function () {
    it('queues the reminders and reports how many', function () {
        Queue::fake();
        brBooking('2026-11-24 09:15');
        brBooking('2026-11-24 09:45');

        artisan('bookings:send-reminders')
            ->expectsOutput('2 herinnering(en) in de wachtrij gezet.')
            ->assertSuccessful();

        Queue::assertPushed(SendBookingReminder::class, 2);
    });

    it('runs every hour, never overlapping itself', function () {
        $event = collect(app(Schedule::class)->events())
            ->first(fn (Event $event): bool => str_contains((string) $event->command, 'bookings:send-reminders'));

        expect($event)->not->toBeNull()
            ->and($event?->expression)->toBe('0 * * * *')
            ->and($event?->withoutOverlapping)->toBeTrue();
    });

    it('delivers one reminder through the database queue, however often it runs', function () {
        config(['queue.default' => 'database']);
        brBooking('2026-11-24 09:15');

        Artisan::call('bookings:send-reminders');
        Artisan::call('bookings:send-reminders');
        expect(DB::table('jobs')->count())->toBe(1);

        Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
        Artisan::call('bookings:send-reminders');

        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();
        expect($transport->messages())->toHaveCount(1)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });
});
