<?php

use App\Bookings\BookingCalendarEvent;
use App\Bookings\CreateBooking;
use App\Enums\BookingStatus;
use App\Enums\Weekday;
use App\Jobs\SendBookingConfirmation;
use App\Mail\BookingConfirmation;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Models\WorkingHour;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Part\DataPart;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\travel;
use function Pest\Laravel\travelTo;

/*
 * ISSUE-19: after booking, the customer gets a confirmation mail with an
 * .ics file, sent from the queue. "Now" is Monday 2026-11-23 10:00 in
 * Amsterdam; the booking is on Tuesday 2026-12-01 09:15 (UTC+1).
 */

beforeEach(function () {
    travelTo(CarbonImmutable::parse('2026-11-23 10:00', 'Europe/Amsterdam'));
    config(['booking.slot_interval' => 15, 'booking.cancellation_window_hours' => 24]);
});

/**
 * @return array{User, Employee, Service}
 */
function bcSetup(): array
{
    $employee = Employee::factory()->create(['display_name' => 'Sanne']);
    WorkingHour::factory()->for($employee)->on(Weekday::Tuesday, '09:00', '12:00')->create();

    $service = Service::factory()->create(['name' => 'Knippen', 'duration_minutes' => 30, 'buffer_minutes' => 15, 'price_cents' => 2500]);
    $employee->services()->attach($service);

    $customer = User::factory()->customer()->create(['name' => 'Eva de Vries', 'email' => 'eva@example.test']);

    return [$customer, $employee, $service];
}

function bcBook(User $customer, Employee $employee, Service $service, string $time = '09:15', ?string $notes = null): Booking
{
    return app(CreateBooking::class)->handle($customer, $service, $employee, CarbonImmutable::parse("2026-12-01 {$time}", 'Europe/Amsterdam'), $notes);
}

/**
 * @return array<string, string>
 */
function bcInput(Service $service, Employee $employee, string $startsAt = '2026-12-01T09:15:00+01:00'): array
{
    return [
        'service_id' => (string) $service->id,
        'employee_id' => (string) $employee->id,
        'starts_at' => $startsAt,
    ];
}

describe('queueing', function () {
    it('queues the confirmation when a customer books, without sending it during the request', function () {
        Queue::fake();
        Mail::fake();
        [$customer, $employee, $service] = bcSetup();

        actingAs($customer)->post(route('bookings.store'), bcInput($service, $employee))->assertRedirect();

        $booking = Booking::query()->sole();
        Queue::assertPushed(SendBookingConfirmation::class, 1);
        Queue::assertPushed(SendBookingConfirmation::class, fn (SendBookingConfirmation $job): bool => $job->booking->is($booking));
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    });

    it('queues the confirmation for a booking made from the booking page (JSON)', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();

        actingAs($customer)->postJson(route('bookings.store'), bcInput($service, $employee))->assertCreated();

        Queue::assertPushed(SendBookingConfirmation::class, 1);
    });

    it('queues nothing when the booking is refused', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();
        bcBook($customer, $employee, $service);
        Queue::fake();

        // Same time again: the customer and the employee are busy.
        actingAs($customer)->post(route('bookings.store'), bcInput($service, $employee))->assertSessionHasErrors();
        // Not offered: outside working hours.
        actingAs($customer)->post(route('bookings.store'), bcInput($service, $employee, '2026-12-01T13:00:00+01:00'))->assertSessionHasErrors();

        Queue::assertNothingPushed();
        expect(Booking::count())->toBe(1);
    });

    it('is queued only after the booking is committed', function () {
        expect(new SendBookingConfirmation(new Booking))->toBeInstanceOf(ShouldQueueAfterCommit::class);
    });

    it('stores the job on the database queue and sends nothing yet', function () {
        config(['queue.default' => 'database']);
        Mail::fake();
        [$customer, $employee, $service] = bcSetup();

        bcBook($customer, $employee, $service);

        expect(DB::table('jobs')->count())->toBe(1)
            ->and((string) DB::table('jobs')->value('payload'))->toContain('SendBookingConfirmation');
        Mail::assertNothingSent();
    });

    it('queues nothing when an outer transaction rolls the booking back', function () {
        config(['queue.default' => 'database']);
        [$customer, $employee, $service] = bcSetup();

        try {
            DB::transaction(function () use ($customer, $employee, $service): void {
                bcBook($customer, $employee, $service);

                throw new RuntimeException('rolled back');
            });
        } catch (RuntimeException) {
            // expected
        }

        expect(Booking::count())->toBe(0)
            ->and(DB::table('jobs')->count())->toBe(0);
    });

    it('retries a failing mail server a few times with a growing pause', function () {
        $job = new SendBookingConfirmation(new Booking);

        expect($job->tries)->toBe(5)
            ->and($job->backoff())->toBe([30, 120, 600, 1800])
            ->and($job->deleteWhenMissingModels)->toBeTrue();
    });
});

describe('the job', function () {
    it('mails the confirmation to the customer', function () {
        Queue::fake();
        Mail::fake();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);

        (new SendBookingConfirmation($booking))->handle();

        Mail::assertSent(BookingConfirmation::class, 1);
        Mail::assertSent(BookingConfirmation::class, fn (BookingConfirmation $mail): bool => $mail->hasTo('eva@example.test') && $mail->booking->is($booking));
    });

    it('sends no confirmation for a booking cancelled before the worker ran', function () {
        Mail::fake();
        $booking = Booking::factory()->create(['status' => BookingStatus::Cancelled]);

        (new SendBookingConfirmation($booking))->handle();

        Mail::assertNothingSent();
    });

    it('mails the time the booking has when the worker runs', function () {
        Queue::fake();
        Mail::fake();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);
        $payload = serialize(new SendBookingConfirmation($booking));

        // Moved before the worker picked the job up.
        $moved = CarbonImmutable::parse('2026-12-01 10:00', 'Europe/Amsterdam');
        $booking->starts_at = $moved;
        $booking->ends_at = $moved->addMinutes(30);
        $booking->blocked_until = $moved->addMinutes(45);
        $booking->save();

        // The worker unserializes the job, which loads the booking fresh.
        /** @var SendBookingConfirmation $job */
        $job = unserialize($payload);
        $job->handle();

        Mail::assertSent(BookingConfirmation::class, fn (BookingConfirmation $mail): bool => $mail->envelope()->subject === 'Bevestiging van je afspraak op 01-12-2026 om 10:00');
    });
});

/**
 * A mailer whose server is down.
 */
function bcBreakMailer(): void
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

function bcRunWorkerOnce(): void
{
    Artisan::call('queue:work', ['connection' => 'database', '--once' => true]);
}

describe('the job, per status', function () {
    it('mails only a booking that still takes up time', function (BookingStatus $status, bool $mailed) {
        Mail::fake();
        $booking = Booking::factory()->create(['status' => $status]);

        (new SendBookingConfirmation($booking))->handle();

        $mailed ? Mail::assertSent(BookingConfirmation::class, 1) : Mail::assertNothingSent();
    })->with([
        'requested' => [BookingStatus::Requested, true],
        'confirmed' => [BookingStatus::Confirmed, true],
        'cancelled' => [BookingStatus::Cancelled, false],
        'completed' => [BookingStatus::Completed, false],
        'no-show' => [BookingStatus::NoShow, false],
    ]);

    it('checks the status the booking has when the worker runs, not when it was queued', function () {
        Queue::fake();
        Mail::fake();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);
        $payload = serialize(new SendBookingConfirmation($booking));

        Booking::query()->whereKey($booking->id)->update(['status' => BookingStatus::Cancelled->value]);

        /** @var SendBookingConfirmation $job */
        $job = unserialize($payload);
        $job->handle();

        Mail::assertNothingSent();
    });
});

describe('the worker', function () {
    it('drops the job without failing when the booking is gone before it runs', function () {
        config(['queue.default' => 'database']);
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);
        expect(DB::table('jobs')->count())->toBe(1);

        $booking->delete();
        bcRunWorkerOnce();

        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();
        expect($transport->messages())->toHaveCount(0)
            ->and(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(0);
    });

    it('delivers the queued mail when the worker runs', function () {
        config(['queue.default' => 'database']);
        [$customer, $employee, $service] = bcSetup();
        bcBook($customer, $employee, $service);

        bcRunWorkerOnce();

        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();
        expect($transport->messages())->toHaveCount(1)
            ->and(DB::table('jobs')->count())->toBe(0);
    });

    it('keeps the booking and retries later when the mail server is down', function () {
        config(['queue.default' => 'database']);
        bcBreakMailer();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);

        bcRunWorkerOnce();

        $job = DB::table('jobs')->sole();
        expect((int) $job->attempts)->toBe(1)
            // The first backoff: 30 seconds.
            ->and((int) $job->available_at)->toBe(now()->getTimestamp() + 30)
            ->and(DB::table('failed_jobs')->count())->toBe(0)
            ->and($booking->fresh()?->status)->toBe(BookingStatus::Requested);
    });

    it('moves the job to failed_jobs after the fifth try, keeping the booking', function () {
        config(['queue.default' => 'database']);
        bcBreakMailer();
        [$customer, $employee, $service] = bcSetup();
        bcBook($customer, $employee, $service);

        foreach ([30, 120, 600, 1800] as $pause) {
            bcRunWorkerOnce();
            travel($pause + 1)->seconds();
        }
        expect(DB::table('jobs')->count())->toBe(1);
        bcRunWorkerOnce();

        expect(DB::table('jobs')->count())->toBe(0)
            ->and(DB::table('failed_jobs')->count())->toBe(1)
            ->and((string) DB::table('failed_jobs')->value('exception'))->toContain('Connection refused')
            ->and(Booking::count())->toBe(1);
    });
});

describe('the mail', function () {
    it('leaves out the notes when the customer gave none', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();

        $mail = new BookingConfirmation(bcBook($customer, $employee, $service, notes: '   '));

        $mail->assertDontSeeInHtml('Je opmerking');
    });

    it('shows the status the booking has when the mail is built', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);
        $booking->transitionTo(BookingStatus::Confirmed);
        $booking->save();

        $mail = new BookingConfirmation($booking);

        $mail->assertSeeInHtml('bevestigd')->assertDontSeeInHtml('aangevraagd');
        expect(BookingCalendarEvent::for($booking))->toContain("STATUS:CONFIRMED\r\n");
    });

    it('has a Dutch subject with the local date and time', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();

        $mail = new BookingConfirmation(bcBook($customer, $employee, $service));

        $mail->assertHasSubject('Bevestiging van je afspraak op 01-12-2026 om 09:15');
    });

    it('shows the details of the appointment in local time', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service, notes: 'Graag kort');

        $mail = new BookingConfirmation($booking);

        $mail->assertSeeInHtml('Eva de Vries')
            ->assertSeeInHtml('Knippen')
            ->assertSeeInHtml('Sanne')
            ->assertSeeInHtml('01-12-2026 09:15 - 09:45')
            ->assertSeeInHtml('30 minuten')
            ->assertSeeInHtml('25,00')
            ->assertSeeInHtml('aangevraagd')
            ->assertSeeInHtml('Graag kort')
            // The cancellation deadline: 24 hours before the start.
            ->assertSeeInHtml('30-11-2026 09:15')
            ->assertSeeInHtml(route('bookings.show', $booking))
            ->assertSeeInText('Je afspraak is geboekt');
    });

    it('escapes the notes of the customer', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();

        $mail = new BookingConfirmation(bcBook($customer, $employee, $service, notes: '<script>alert(1)</script>'));

        $mail->assertDontSeeInHtml('<script>alert(1)</script>', false);
    });

    it('attaches the appointment as an .ics file', function () {
        Queue::fake();
        [$customer, $employee, $service] = bcSetup();
        $booking = bcBook($customer, $employee, $service);

        $mail = new BookingConfirmation($booking);

        $mail->assertHasAttachedData(BookingCalendarEvent::for($booking), 'afspraak.ics', ['mime' => 'text/calendar']);
    });
});

describe('end to end', function () {
    it('delivers the mail with the .ics file once the queue runs the job', function () {
        // phpunit.xml: sync queue, array mailer.
        [$customer, $employee, $service] = bcSetup();

        actingAs($customer)->post(route('bookings.store'), bcInput($service, $employee))->assertRedirect();

        /** @var ArrayTransport $transport */
        $transport = Mail::mailer()->getSymfonyTransport();
        $messages = $transport->messages();
        expect($messages)->toHaveCount(1);

        $email = $messages->first()->getOriginalMessage();
        expect($email)->toBeInstanceOf(Email::class);
        /** @var Email $email */
        expect($email->getTo())->toHaveCount(1)
            ->and($email->getTo()[0]->getAddress())->toBe('eva@example.test')
            ->and($email->getTo()[0]->getName())->toBe('Eva de Vries')
            ->and($email->getSubject())->toBe('Bevestiging van je afspraak op 01-12-2026 om 09:15');

        /** @var DataPart|null $ics */
        $ics = collect($email->getAttachments())->first(fn (DataPart $part): bool => $part->getFilename() === 'afspraak.ics');
        expect($ics)->not->toBeNull()
            ->and($ics->getMediaType().'/'.$ics->getMediaSubtype())->toBe('text/calendar')
            ->and($ics->getPreparedHeaders()->get('Content-Type')?->getBodyAsString())->toBe('text/calendar; name=afspraak.ics')
            ->and(mb_check_encoding($ics->getBody(), 'UTF-8'))->toBeTrue()
            ->and($ics->getBody())->toContain("DTSTART:20261201T081500Z\r\n")
            ->and($ics->getBody())->toContain("DTEND:20261201T084500Z\r\n");
    });
});
