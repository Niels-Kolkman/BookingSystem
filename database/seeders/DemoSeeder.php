<?php

namespace Database\Seeders;

use App\Enums\BookingStatus;
use App\Enums\ScheduleExceptionType;
use App\Enums\UserRole;
use App\Enums\Weekday;
use App\Models\Booking;
use App\Models\Employee;
use App\Models\Service;
use App\Models\User;
use App\Scheduling\AvailableSlots;
use App\Scheduling\LocalTime;
use App\Scheduling\Slot;
use App\Scheduling\TimeRange;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * A complete example salon for the demo (ISSUE-26): "Kapsalon De Linde"
 * with services, employees, weekly schedules, time off and bookings from
 * four weeks ago up to three weeks ahead, relative to today.
 *
 * Logins (password `password` for all):
 * - admin@example.com: the owner, admin;
 * - noor@example.com, sem@example.com, lotte@example.com: employees;
 * - klant@example.com: a customer with past, upcoming and cancelled bookings;
 * - 23 more customers, e.g. lucas.peters@example.com.
 *
 * Bookings are only placed in slots that AvailableSlots offers, so they
 * respect working hours, split shifts, public holidays, time off, buffers
 * and each other. A customer is never in two appointments at once. Past
 * bookings end as completed, no-show or cancelled; upcoming ones are
 * requested, confirmed or cancelled. Status changes go through the model,
 * so only valid transitions (ISSUE-18) are used. No mails are queued.
 *
 * Needs no Faker or factories, so it also runs without dev dependencies.
 * A fixed random seed keeps the result the same for the same date. Runs
 * once: when admin@example.com already exists nothing is changed; use
 * `php artisan migrate:fresh --seed` for a clean demo.
 */
class DemoSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'admin@example.com';

    public const CUSTOMER_EMAIL = 'klant@example.com';

    /** Days back from today that get (finished) bookings. */
    public const DAYS_BACK = 28;

    /** Days ahead of today that get bookings. */
    public const DAYS_AHEAD = 21;

    private Randomizer $random;

    private CarbonImmutable $now;

    private CarbonImmutable $today;

    private string $password;

    private AvailableSlots $slots;

    /**
     * Appointment times per customer id, [start, end) in UTC, so a customer
     * never gets two overlapping appointments.
     *
     * @var array<int, list<array{CarbonImmutable, CarbonImmutable}>>
     */
    private array $customerAgenda = [];

    /**
     * Confirmed bookings and the final status they get once all agendas
     * are filled, see book().
     *
     * @var list<array{Booking, BookingStatus}>
     */
    private array $finished = [];

    public function run(): void
    {
        if (User::query()->where('email', self::ADMIN_EMAIL)->exists()) {
            $this->command->warn('Demo data already present ('.self::ADMIN_EMAIL.' exists); nothing seeded. Use `php artisan migrate:fresh --seed` for a fresh demo.');

            return;
        }

        $this->random = new Randomizer(new Mt19937(26));
        $this->now = CarbonImmutable::now();
        $this->today = LocalTime::midnight($this->now);
        $this->password = Hash::make('password');
        $this->slots = AvailableSlots::fromConfig();

        DB::transaction(function (): void {
            $admin = $this->user('Marieke van Leeuwen', self::ADMIN_EMAIL, '06 12 34 56 78', UserRole::Admin);
            $services = $this->services();
            $employees = $this->employees($services);
            $customers = $this->customers();

            $this->bookingsOfDemoCustomer($customers[0], $employees, $services, $admin);
            $this->fillAgendas($employees, array_slice($customers, 1), $admin);

            foreach ($this->finished as [$booking, $status]) {
                $booking->transitionTo($status);
                $booking->save();
            }

            // No longer offered: it stays visible in the admin area, and its
            // past bookings keep pointing at it.
            $services['keratine']->update(['is_active' => false]);
        });

        $this->command->info(sprintf(
            'Demo salon seeded: %d services, %d employees, %d customers, %d bookings. Log in as %s or %s, password "password".',
            Service::query()->count(),
            Employee::query()->count(),
            User::query()->where('role', UserRole::Customer)->count(),
            Booking::query()->count(),
            self::ADMIN_EMAIL,
            self::CUSTOMER_EMAIL,
        ));
    }

    /**
     * @return array<string, Service>
     */
    private function services(): array
    {
        $definitions = [
            'dames' => ['Knippen dames', 'Wassen, knippen en in model föhnen, met advies over verzorging.', 45, 15, 4250],
            'heren' => ['Knippen heren', 'Tondeuse of schaar, strak bijgewerkte nek en contouren.', 30, 5, 2750],
            'kinderen' => ['Knippen kinderen (t/m 12 jaar)', 'Rustig en vlot, met een lolly na afloop.', 20, 5, 1750],
            'fohnen' => ['Wassen en föhnen', 'Wassen, verzorgen en in model föhnen, zonder knippen.', 30, 10, 2950],
            'uitgroei' => ['Uitgroei kleuren', 'Uitgroei bijwerken tot 3 cm, inclusief wassen en föhnen.', 75, 15, 6500],
            'balayage' => ['Balayage', 'Handgeschilderde highlights voor een natuurlijk zonnig effect, inclusief toner.', 150, 15, 13500],
            'baard' => ['Baard trimmen', 'Baard in model met tondeuse en mes, afgewerkt met baardolie.', 20, 5, 1500],
            'bruid' => ['Bruidskapsel proefsessie', 'Proefsessie voor de grote dag: we maken het kapsel samen met jou.', 90, 15, 8500],
            'keratine' => ['Keratinebehandeling', 'Gladmakende behandeling tegen pluis. Tijdelijk niet beschikbaar.', 120, 15, 14500],
        ];

        $services = [];
        foreach ($definitions as $key => [$name, $description, $duration, $buffer, $price]) {
            $services[$key] = Service::query()->create([
                'name' => $name,
                'description' => $description,
                'duration_minutes' => $duration,
                'buffer_minutes' => $buffer,
                'price_cents' => $price,
                'is_active' => true,
            ]);
        }

        return $services;
    }

    /**
     * Three active employees with different schedules, plus one who left.
     *
     * @param  array<string, Service>  $services
     * @return array<string, Employee> the active ones
     */
    private function employees(array $services): array
    {
        $noor = $this->employee('Noor Bakker', 'noor@example.com', 'Noor',
            ['dames', 'kinderen', 'fohnen', 'uitgroei', 'balayage', 'bruid', 'keratine'], $services);
        // Tuesday to Saturday with a lunch break; late on Thursday (koopavond).
        foreach ([Weekday::Tuesday, Weekday::Wednesday, Weekday::Friday, Weekday::Saturday] as $day) {
            $this->block($noor, $day, '09:00', '12:30');
            $this->block($noor, $day, '13:00', '17:30');
        }
        $this->block($noor, Weekday::Thursday, '12:00', '17:00');
        $this->block($noor, Weekday::Thursday, '17:30', '21:00');

        $sem = $this->employee('Sem de Vries', 'sem@example.com', 'Sem',
            ['heren', 'kinderen', 'baard', 'dames', 'fohnen'], $services);
        // Monday to Friday with a short lunch, Saturday morning.
        foreach ([Weekday::Monday, Weekday::Tuesday, Weekday::Wednesday, Weekday::Thursday, Weekday::Friday] as $day) {
            $this->block($sem, $day, '08:30', '12:30');
            $this->block($sem, $day, '13:00', '17:00');
        }
        $this->block($sem, Weekday::Saturday, '09:00', '14:00');

        $lotte = $this->employee('Lotte Jansen', 'lotte@example.com', 'Lotte',
            ['dames', 'fohnen', 'uitgroei', 'balayage', 'keratine'], $services);
        // Part-time: Wednesday, Friday and Saturday.
        foreach ([Weekday::Wednesday, Weekday::Friday, Weekday::Saturday] as $day) {
            $this->block($lotte, $day, '09:00', '16:00');
        }

        // Left the salon: kept for history, not bookable.
        $daan = $this->employee('Daan Visser', 'daan@example.com', 'Daan', ['heren', 'baard'], $services);
        $daan->update(['is_active' => false]);

        // Time off and extra hours around today.
        $this->exception($sem, -10, ScheduleExceptionType::Unavailable, null, null, 'Ziek');
        $this->exception($sem, 2, ScheduleExceptionType::Unavailable, '14:00', '17:00', 'Tandarts');
        for ($day = 7; $day <= 13; $day++) {
            $this->exception($lotte, $day, ScheduleExceptionType::Unavailable, null, null, 'Vakantie');
        }
        $this->exception($noor, $this->daysUntil(Weekday::Monday, 4), ScheduleExceptionType::ExtraHours, '10:00', '16:00', 'Extra koopdag');
        $this->exception($noor, $this->daysUntil(Weekday::Saturday, 3), ScheduleExceptionType::Unavailable, '13:00', '17:30', 'Bruiloft in de familie');

        return ['noor' => $noor, 'sem' => $sem, 'lotte' => $lotte];
    }

    /**
     * @return non-empty-list<User> the demo customer klant@example.com first
     */
    private function customers(): array
    {
        $customers = [$this->user('Emma de Jong', self::CUSTOMER_EMAIL, '06 21 43 65 87', UserRole::Customer)];

        $names = [
            'Lucas Peters', 'Sophie van Dijk', 'Daan Smit', 'Julia Meijer', 'Milan de Boer',
            'Tess Mulder', 'Finn de Groot', 'Sara Bos', 'Levi Vos', 'Anna Hendriks',
            'Thijs van den Berg', 'Fleur Dekker', 'Jesse van der Linden', 'Lisa Kok', 'Bram Jacobs',
            'Eva Willems', 'Ruben Schouten', 'Mila van Wijk', 'Sanne Koster', 'Jayden Brouwer',
            'Yara El Amrani', 'Mehmet Yılmaz', 'Ilse Prins',
        ];

        foreach ($names as $i => $name) {
            // "Thijs van den Berg" -> "thijs.vandenberg@example.com".
            [$first, $last] = explode(' ', strtr($name, ['ı' => 'i']), 2);
            $email = strtolower($first.'.'.str_replace(' ', '', $last)).'@example.com';
            $phone = sprintf('06 %02d %02d %02d %02d', 10 + $i, $this->random->getInt(10, 99), $this->random->getInt(10, 99), $this->random->getInt(10, 99));

            $customers[] = $this->user($name, $email, $i % 4 === 3 ? null : $phone, UserRole::Customer);
        }

        return $customers;
    }

    /**
     * A fixed history for klant@example.com, so logging in as the customer
     * shows every kind of booking straight away. Each entry is booked on the
     * first day from its offset that has a free slot.
     *
     * @param  array<string, Employee>  $employees
     * @param  array<string, Service>  $services
     */
    private function bookingsOfDemoCustomer(User $customer, array $employees, array $services, User $admin): void
    {
        $plan = [
            [-25, 'noor', 'uitgroei', BookingStatus::Completed, null],
            [-12, 'lotte', 'dames', BookingStatus::Completed, 'Graag weer zo kort als de vorige keer.'],
            [-5, 'sem', 'fohnen', BookingStatus::Cancelled, null],
            [3, 'noor', 'dames', BookingStatus::Confirmed, 'Ik wil graag een pony laten knippen.'],
            [15, 'noor', 'balayage', BookingStatus::Requested, null],
            [18, 'sem', 'fohnen', BookingStatus::Cancelled, null],
        ];

        foreach ($plan as [$offset, $employeeKey, $serviceKey, $status, $notes]) {
            for ($day = $offset; $day < $offset + 7; $day++) {
                $slots = $this->slotsOn($services[$serviceKey], $employees[$employeeKey], $day, $customer);
                if ($slots === []) {
                    continue;
                }

                $this->book($customer, $employees[$employeeKey], $services[$serviceKey], $slots[intdiv(count($slots), 3)], $status, $notes, $admin);
                break;
            }
        }
    }

    /**
     * Fills every working day of the active employees: busy in the past and
     * the coming week, quieter further ahead, so there is still room to book.
     *
     * @param  array<string, Employee>  $employees
     * @param  non-empty-list<User>  $customers
     */
    private function fillAgendas(array $employees, array $customers, User $admin): void
    {
        foreach ($employees as $employee) {
            $employee->load(['workingHours', 'scheduleExceptions']);
            /** @var non-empty-list<Service> $offered */
            $offered = $employee->services()->get()->values()->all();

            for ($day = -self::DAYS_BACK; $day <= self::DAYS_AHEAD; $day++) {
                $ranges = $employee->scheduleOn($this->today->addDays($day))->ranges;
                $workMinutes = array_sum(array_map(fn (TimeRange $range): int => $range->end - $range->start, $ranges));
                if ($workMinutes === 0) {
                    continue;
                }

                [$min, $max] = match (true) {
                    $day <= 0 => [60, 90],
                    $day <= 7 => [45, 75],
                    default => [15, 40],
                };
                $target = intdiv($workMinutes * $this->random->getInt($min, $max), 100);
                $booked = 0;
                // Free slots per service id, looked up once per day and then
                // kept up to date by hand, which is much faster.
                /** @var array<int, list<Slot>> $free */
                $free = [];

                for ($attempt = 0; $attempt < 30 && $booked < $target; $attempt++) {
                    $service = $this->pick($offered);
                    $customer = $this->pick($customers);
                    $free[$service->id] ??= $this->slotsOn($service, $employee, $day);
                    $slots = array_values(array_filter(
                        $free[$service->id],
                        fn (Slot $slot): bool => ! $this->customerIsBusy($customer, $slot->startsAt, $slot->endsAt),
                    ));
                    if ($slots === []) {
                        continue;
                    }

                    $slot = $this->pick($slots);
                    $status = $this->randomStatus($slot);
                    $this->book($customer, $employee, $service, $slot, $status, $this->randomNote(), $admin);
                    $booked += $service->duration_minutes;

                    if ($status !== BookingStatus::Cancelled) {
                        // The same overlap rule as Booking::blockingBetween().
                        foreach ($free as $id => $list) {
                            $free[$id] = array_values(array_filter(
                                $list,
                                fn (Slot $s): bool => ! ($s->startsAt < $slot->blockedUntil && $s->blockedUntil > $slot->startsAt),
                            ));
                        }
                    }
                }
            }
        }
    }

    /**
     * Slots the employee has free on today + $day for the service, the past
     * included, optionally without those that clash with the customer's
     * other appointments.
     *
     * @return list<Slot>
     */
    private function slotsOn(Service $service, Employee $employee, int $day, ?User $customer = null): array
    {
        $date = $this->today->addDays($day);

        return array_values(array_filter(
            $this->slots->for($service, $employee, $date, $date),
            fn (Slot $slot): bool => $customer === null || ! $this->customerIsBusy($customer, $slot->startsAt, $slot->endsAt),
        ));
    }

    /**
     * Stores the booking as requested or confirmed, like a real booking
     * starts, and moves it to its final status through the model.
     *
     * Slot lookups only see blocking bookings. A cancelled one is cancelled
     * right away, so its time may be booked again, as in real life.
     * Completed and no-show must keep their time taken while the agendas
     * are filled, so they stay confirmed until finish().
     */
    private function book(User $customer, Employee $employee, Service $service, Slot $slot, BookingStatus $status, ?string $notes, User $admin): void
    {
        $createdAt = $this->notAfterNow($slot->startsAt->subMinutes($this->random->getInt(2 * 60, 21 * 24 * 60)));

        $booking = new Booking;
        $booking->customer_id = $customer->id;
        $booking->employee_id = $employee->id;
        $booking->service_id = $service->id;
        $booking->starts_at = $slot->startsAt;
        $booking->ends_at = $slot->endsAt;
        $booking->blocked_until = $slot->blockedUntil;
        $booking->duration_minutes = $service->duration_minutes;
        $booking->price_cents = $service->price_cents;
        $booking->status = $status === BookingStatus::Requested ? BookingStatus::Requested : BookingStatus::Confirmed;
        $booking->notes = $notes;
        $booking->created_at = $createdAt;
        $booking->save();

        if ($status === BookingStatus::Cancelled) {
            $this->cancel($booking, $createdAt, $customer, $admin);
        } elseif ($booking->status !== $status) {
            $this->finished[] = [$booking, $status];
        }

        // Due reminders count as sent, so the hourly reminder run does not
        // mail every demo customer at once (ISSUE-20).
        $reminderAt = $slot->startsAt->subHours((int) config('booking.reminder_hours_before'));
        if ($status !== BookingStatus::Cancelled && $reminderAt <= $this->now) {
            $booking->setAttribute('reminder_sent_at', $reminderAt->max($createdAt));
        }

        $booking->save();

        if ($status !== BookingStatus::Cancelled) {
            $this->customerAgenda[$customer->id][] = [$slot->startsAt, $slot->endsAt];
        }
    }

    /**
     * Mostly by the customer before the cancellation deadline (ISSUE-16),
     * otherwise by the salon.
     */
    private function cancel(Booking $booking, CarbonImmutable $createdAt, User $customer, User $admin): void
    {
        $deadline = $booking->cancellationDeadline();
        $byCustomer = $deadline > $createdAt && $this->random->getInt(1, 100) <= 70;
        $latest = $this->notAfterNow($byCustomer ? $deadline : $booking->starts_at->subMinutes(30))->max($createdAt);
        $span = (int) $createdAt->diffInMinutes($latest);

        $booking->transitionTo(BookingStatus::Cancelled);
        $booking->cancelled_at = $createdAt->addMinutes($this->random->getInt(0, $span));
        $booking->cancelled_by = $byCustomer ? $customer->id : $admin->id;
        $booking->cancellation_reason = $byCustomer
            ? $this->pick([null, 'Ik ben verhinderd.', 'Ziek, sorry!', 'Ik moet onverwacht werken.', 'Toch op vakantie.'])
            : $this->pick(['Medewerker ziek, klant gebeld voor een nieuwe afspraak.', 'Telefonisch geannuleerd op verzoek van de klant.']);
    }

    private function randomStatus(Slot $slot): BookingStatus
    {
        $roll = $this->random->getInt(1, 100);

        if ($slot->endsAt <= $this->now) {
            return match (true) {
                $roll <= 7 => BookingStatus::NoShow,
                $roll <= 15 => BookingStatus::Cancelled,
                default => BookingStatus::Completed,
            };
        }

        // Started but not finished: the customer is in the chair.
        if ($slot->startsAt <= $this->now) {
            return BookingStatus::Confirmed;
        }

        return match (true) {
            $roll <= 8 => BookingStatus::Cancelled,
            $roll <= 35 => BookingStatus::Requested,
            default => BookingStatus::Confirmed,
        };
    }

    private function randomNote(): ?string
    {
        if ($this->random->getInt(1, 100) > 15) {
            return null;
        }

        return $this->pick([
            'Graag dezelfde kleur als de vorige keer.',
            'Ik kom met mijn zoontje, hij is wat zenuwachtig.',
            'Gevoelige hoofdhuid.',
            'Ik wil graag advies over een nieuw model.',
            'Kan ik met pin betalen?',
            'Ik ben misschien vijf minuten later.',
        ]);
    }

    private function customerIsBusy(User $customer, CarbonImmutable $startsAt, CarbonImmutable $endsAt): bool
    {
        foreach ($this->customerAgenda[$customer->id] ?? [] as [$start, $end]) {
            if ($start < $endsAt && $end > $startsAt) {
                return true;
            }
        }

        return false;
    }

    private function user(string $name, string $email, ?string $phone, UserRole $role): User
    {
        // The hashed cast keeps an already hashed password as it is.
        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => $this->password,
            'phone' => $phone,
        ]);
        $user->role = $role;
        $user->email_verified_at = $this->now;
        $user->save();

        return $user;
    }

    /**
     * @param  list<string>  $serviceKeys
     * @param  array<string, Service>  $services
     */
    private function employee(string $name, string $email, string $displayName, array $serviceKeys, array $services): Employee
    {
        $user = $this->user($name, $email, null, UserRole::Employee);

        $employee = new Employee(['display_name' => $displayName]);
        $employee->user_id = $user->id;
        $employee->save();

        $employee->services()->attach(array_map(fn (string $key): int => $services[$key]->id, $serviceKeys));

        return $employee;
    }

    private function block(Employee $employee, Weekday $weekday, string $startsAt, string $endsAt): void
    {
        $employee->workingHours()->create(['weekday' => $weekday, 'starts_at' => $startsAt, 'ends_at' => $endsAt]);
    }

    private function exception(Employee $employee, int $day, ScheduleExceptionType $type, ?string $startsAt, ?string $endsAt, string $reason): void
    {
        $employee->scheduleExceptions()->create([
            'date' => $this->today->addDays($day),
            'type' => $type,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'reason' => $reason,
        ]);
    }

    /**
     * Days from today to the first given weekday at least $minDays ahead.
     */
    private function daysUntil(Weekday $weekday, int $minDays): int
    {
        $day = $minDays;
        while (Weekday::fromDate($this->today->addDays($day)) !== $weekday) {
            $day++;
        }

        return $day;
    }

    private function notAfterNow(CarbonImmutable $moment): CarbonImmutable
    {
        return $moment->min($this->now);
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $options
     * @return T
     */
    private function pick(array $options): mixed
    {
        return $options[$this->random->getInt(0, count($options) - 1)];
    }
}
