<?php

use App\Bookings\CancelBooking;
use App\Bookings\QueueBookingReminders;
use App\Bookings\RescheduleBooking;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Jobs\SendBookingReminder;
use App\Mail\BookingReminder;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;

use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-20 edge cases: races between finding and claiming a booking, big
 * batches, a mail server that is down, a booking that is deleted, moved or
 * cancelled through the real actions, and the config clamp. "Now" is
 * Monday 2026-11-23 10:00 in Amsterdam (UTC+1).
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.reminder_hours_before' => 24, 'booking.cancellation_window_hours' => 24, 'booking.slot_interval' => 15]);
});

/**
 * A booking at a local time, made a week ago.
 *
 * @param  array<string, mixed>  $attributes
 */
function breBooking(string $startsAt, array $attributes = []): Booking
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

function breRun(): int
{
    return app(QueueBookingReminders::class)->handle();
}

function breSentAt(Booking $booking): ?CarbonImmutable
{
    return $booking->fresh()?->getAttribute('reminder_sent_at');
}

function breWorkOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

/**
 * @return list<SentMessage>
 */
function breDelivered(): array
{
    /** @var ArrayTransport $transport */
    $transport = Mail::mailer()->getSymfonyTransport();

    return $transport->messages()->values()->all();
}

/**
 * A mailer whose server is down.
 */
function breBreakMailer(): void
{
    Mail::extend('down', fn () => new class extends AbstractTransport
    {
        protected function doSend(SentMessage $message): void
        {
            throw new TransportException('Connection refused');
        }

        public function __toString(): string
        {
            return 'down://';
        }
    });
    config(['mail.mailers.down' => ['transport' => 'down'], 'mail.default' => 'down']);
    app('mail.manager')->forgetMailers();
}

/**
 * An employee who works Tuesday 09:00-12:00 with a 30+15 minute service,
 * and a booking with them on Tuesday 09:15, made a week ago.
 */
function breAgendaBooking(): Booking
{
    $employee = Employee::factory()->create();
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();
    $service = Service::factory()->create(['duration_minutes' => 30, 'buffer_minutes' => 15]);
    $employee->services()->attach($service);

    return breBooking('2026-11-24 09:15', ['employee_id' => $employee->id, 'service_id' => $service->id]);
}

describe('races between finding and claiming', function () {
    it('does not claim a booking that was cancelled after it was found', function () {
        Queue::fake();
        $booking = breBooking('2026-11-24 09:15');
        Booking::retrieved(function (Booking $found): void {
            DB::table('bookings')->where('id', $found->id)->update(['status' => BookingStatus::Cancelled->value]);
        });

        expect(breRun())->toBe(0)
            ->and(breSentAt($booking))->toBeNull();

        Queue::assertNothingPushed();
    });

    it('does not claim a booking that was moved after it was found', function () {
        Queue::fake();
        $booking = breBooking('2026-11-24 09:15');
        Booking::retrieved(function (Booking $found): void {
            DB::table('bookings')->where('id', $found->id)->update(['starts_at' => '2026-11-24 07:00:00']);
        });

        expect(breRun())->toBe(0)
            ->and(breSentAt($booking))->toBeNull();

        Queue::assertNothingPushed();
    });

    it('still reminds the other bookings of the run when one was lost to a race', function () {
        Queue::fake();
        $lost = breBooking('2026-11-24 09:15');
        $kept = breBooking('2026-11-24 09:45');
        Booking::retrieved(function (Booking $found) use ($lost): void {
            if ($found->id === $lost->id) {
                DB::table('bookings')->where('id', $found->id)->update(['reminder_sent_at' => '2026-11-23 08:59:00']);
            }
        });

        expect(breRun())->toBe(1);

        Queue::assertPushed(SendBookingReminder::class, 1);
        Queue::assertPushed(SendBookingReminder::class, fn (SendBookingReminder $job): bool => $job->booking->is($kept));
    });
});

describe('which bookings are reminded, more', function () {
    it('reminds a booking without a created_at', function () {
        Queue::fake();
        $booking = breBooking('2026-11-24 09:15');
        DB::table('bookings')->where('id', $booking->id)->update(['created_at' => null]);

        expect(breRun())->toBe(1)
            ->and(breSentAt($booking))->not->toBeNull();
    });

    it('reminds every due booking exactly once across several chunks', function () {
        Queue::fake();
        $start = CarbonImmutable::parse('2026-11-24 09:15', 'Europe/Amsterdam');

        Booking::factory()->count(250)->create([
            'customer_id' => User::factory()->customer()->create()->id,
            'employee_id' => Employee::factory()->create()->id,
            'service_id' => Service::factory()->create()->id,
            'starts_at' => $start,
            'ends_at' => $start->addMinutes(30),
            'blocked_until' => $start->addMinutes(30),
            'created_at' => CarbonImmutable::now()->subWeek(),
        ]);

        expect(breRun())->toBe(250)
            ->and(Booking::query()->whereNull('reminder_sent_at')->count())->toBe(0)
            ->and(breRun())->toBe(0);

        $ids = Queue::pushed(SendBookingReminder::class)->map(fn (SendBookingReminder $job): int => $job->booking->id);
        expect($ids)->toHaveCount(250)
            ->and($ids->unique())->toHaveCount(250);
    });

    it('stores the claim moment in UTC', function () {
        Queue::fake();
        $booking = breBooking('2026-11-24 09:15');

        breRun();

        expect(DB::table('bookings')->where('id', $booking->id)->value('reminder_sent_at'))->toBe('2026-11-23 09:00:00');
    });
});

describe('the worker', function () {
    it('keeps the claim when the mail server is down, so no run queues a second job', function () {
        config(['queue.default' => 'database']);
        breBreakMailer();
        $booking = breBooking('2026-11-24 09:15');

        Artisan::call('bookings:send-reminders');
        breWorkOnce();

        $job = DB::table('jobs')->sole();
        expect((int) $job->attempts)->toBe(1)
            // The first backoff: 30 seconds.
            ->and((int) $job->available_at)->toBe(now()->getTimestamp() + 30)
            ->and(breSentAt($booking))->not->toBeNull();

        Artisan::call('bookings:send-reminders');
        expect(DB::table('jobs')->count())->toBe(1);
    });

    it('lands in failed_jobs after the fifth try and is never queued again', function () {
        config(['queue.default' => 'database']);
        breBreakMailer();
        breBooking('2026-11-24 09:15');
        Artisan::call('bookings:send-reminders');

        foreach ([30, 120, 600, 1800] as $pause) {
            breWorkOnce();
            travel($pause + 1)->seconds();
        }
        expect(DB::table('jobs')->count())->toBe(1);
        breWorkOnce();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and((string) DB::table('failed_jobs')->value('exception'))->toContain('Connection refused');

        Artisan::call('bookings:send-reminders');
        expect(DB::table('jobs')->count())->toBe(0);
    });

    it('drops the job without failing when the booking is deleted before it runs', function () {
        config(['queue.default' => 'database']);
        $booking = breBooking('2026-11-24 09:15');
        Artisan::call('bookings:send-reminders');
        expect(DB::table('jobs')->count())->toBe(1);

        $booking->delete();
        breWorkOnce();

        expect(breDelivered())->toHaveCount(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('mails a requested booking with its own status', function () {
        Mail::fake();
        $booking = breBooking('2026-11-24 09:15', ['status' => BookingStatus::Requested]);

        breRun();

        Mail::assertSent(BookingReminder::class, 1);
        (new BookingReminder($booking->fresh() ?? $booking))
            ->assertSeeInHtml('aangevraagd')
            ->assertDontSeeInHtml('bevestigd');
    });
});

describe('with the real actions', function () {
    it('sends one reminder, for the new time, when an admin moves a claimed booking', function () {
        config(['queue.default' => 'database']);
        $booking = breAgendaBooking();
        Artisan::call('bookings:send-reminders');
        expect(DB::table('jobs')->count())->toBe(1);

        $admin = User::factory()->admin()->create();
        app(RescheduleBooking::class)->handle($admin, $booking, CarbonImmutable::parse('2026-11-24 10:00', 'Europe/Amsterdam'));
        expect(breSentAt($booking))->toBeNull();

        Artisan::call('bookings:send-reminders');
        expect(DB::table('jobs')->count())->toBe(2);

        breWorkOnce();
        breWorkOnce();

        $messages = breDelivered();
        expect($messages)->toHaveCount(1);
        $email = $messages[0]->getOriginalMessage();
        if (! $email instanceof Email) {
            throw new RuntimeException('Expected an e-mail message.');
        }
        expect($email->getSubject())->toBe('Herinnering: je afspraak op 24-11-2026 om 10:00')
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('sends nothing when an admin cancels a claimed booking before the worker runs', function () {
        config(['queue.default' => 'database']);
        $booking = breBooking('2026-11-24 09:15');
        Artisan::call('bookings:send-reminders');

        app(CancelBooking::class)->handle(User::factory()->admin()->create(), $booking, 'Ziek');
        breWorkOnce();

        expect(breDelivered())->toHaveCount(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });
});

describe('the config', function () {
    it('reads the hours from the environment and counts values below 1 as 1', function (string $value, int $hours) {
        $_ENV['BOOKING_REMINDER_HOURS_BEFORE'] = $_SERVER['BOOKING_REMINDER_HOURS_BEFORE'] = $value;

        try {
            $config = require config_path('booking.php');
        } finally {
            unset($_ENV['BOOKING_REMINDER_HOURS_BEFORE'], $_SERVER['BOOKING_REMINDER_HOURS_BEFORE']);
        }

        expect($config['reminder_hours_before'])->toBe($hours);
    })->with([
        'twelve' => ['12', 12],
        'one' => ['1', 1],
        'zero' => ['0', 1],
        'negative' => ['-5', 1],
    ]);

    it('defaults to 24 hours', function () {
        $config = require config_path('booking.php');

        expect($config['reminder_hours_before'])->toBe(24);
    });
});
