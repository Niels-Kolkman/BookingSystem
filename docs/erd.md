# Domain model (ERD)

Design of the database schema, written before the first migration. See [decisions.md](decisions.md) for the choices behind it (MySQL/InnoDB, UTC storage).

```mermaid
erDiagram
    users {
        bigint id PK
        string name
        string email UK
        timestamp email_verified_at "nullable"
        string password
        enum role "customer, employee, admin"
        string phone "nullable"
        string remember_token "nullable"
        timestamp created_at
        timestamp updated_at
    }

    employees {
        bigint id PK
        bigint user_id FK, UK "users.id"
        string display_name
        boolean is_active "default true"
        timestamp created_at
        timestamp updated_at
    }

    services {
        bigint id PK
        string name
        text description "nullable"
        smallint duration_minutes
        smallint buffer_minutes "default 0"
        int price_cents
        boolean is_active "default true"
        timestamp created_at
        timestamp updated_at
    }

    employee_service {
        bigint employee_id PK, FK "employees.id"
        bigint service_id PK, FK "services.id"
    }

    working_hours {
        bigint id PK
        bigint employee_id FK "employees.id"
        tinyint weekday "1 = Monday ... 7 = Sunday"
        time starts_at "local time"
        time ends_at "local time"
        timestamp created_at
        timestamp updated_at
    }

    schedule_exceptions {
        bigint id PK
        bigint employee_id FK "employees.id"
        date date "local date"
        enum type "unavailable, extra_hours"
        time starts_at "nullable, null = whole day"
        time ends_at "nullable, null = whole day"
        string reason "nullable"
        timestamp created_at
        timestamp updated_at
    }

    bookings {
        bigint id PK
        bigint customer_id FK "users.id"
        bigint employee_id FK "employees.id"
        bigint service_id FK "services.id"
        datetime starts_at "UTC"
        datetime ends_at "UTC, starts_at + duration"
        datetime blocked_until "UTC, ends_at + buffer"
        smallint duration_minutes "snapshot of service"
        int price_cents "snapshot of service"
        enum status "requested, confirmed, cancelled, completed, no_show"
        text notes "nullable"
        timestamp cancelled_at "nullable"
        bigint cancelled_by FK "nullable, users.id"
        string cancellation_reason "nullable"
        timestamp reminder_sent_at "nullable"
        timestamp created_at
        timestamp updated_at
    }

    users ||--o| employees : "has profile"
    users ||--o{ bookings : "books"
    users |o--o{ bookings : "cancels"
    employees ||--o{ employee_service : "offers"
    services ||--o{ employee_service : "offered by"
    employees ||--o{ working_hours : "works"
    employees ||--o{ schedule_exceptions : "has"
    employees ||--o{ bookings : "performs"
    services ||--o{ bookings : "booked as"
```

## Entities

| Table | Purpose |
|---|---|
| `users` | Everyone who can log in. `role` decides what they may do (customer, employee, admin). |
| `employees` | Profile for users with the employee role: who can be booked. Kept separate so login data and "bookable resource" data do not mix. |
| `services` | What can be booked: duration, buffer after the appointment, price. |
| `employee_service` | Which employee can perform which service (many-to-many). |
| `working_hours` | Recurring weekly schedule. Several rows per employee per weekday allow split shifts (e.g. 09:00-12:30 and 13:30-17:00). |
| `schedule_exceptions` | One-off deviations for a date: time off, holidays (`unavailable`) or extra hours (`extra_hours`). They override `working_hours` for that date. |
| `bookings` | An appointment: one customer, one employee, one service, one time range. |

## Design decisions

### Two kinds of time
- `working_hours` and `schedule_exceptions` store **local wall-clock time** (`TIME`/`DATE` in `Europe/Amsterdam`). "Works 09:00-17:00 on Mondays" must stay 09:00-17:00 across daylight-saving changes, which UTC would break.
- `bookings` store **UTC** (`DATETIME`). A booking is a concrete moment in time.
- The slot calculation (ISSUE-11) converts the local schedule into concrete UTC ranges for a given date, and ISSUE-14 covers the switch weekends.

### `blocked_until` instead of computing the buffer
The overlap check has to be a simple, indexable range comparison. Storing `blocked_until = ends_at + buffer` means two bookings of the same employee overlap when:

```
existing.starts_at < new.blocked_until AND existing.blocked_until > new.starts_at
```

The customer's own double-booking check (`Booking::attendedByBetween()`) uses `starts_at`/`ends_at` only, because a buffer is the employee's cleanup time, not the customer's.

### Snapshots on the booking
`duration_minutes` and `price_cents` are copied from the service at booking time. Changing a service later must not rewrite history or move existing appointments.

### Which bookings block a slot
`requested` and `confirmed` block their time range. `cancelled` frees it. `completed` and `no_show` are in the past and never block new bookings. Allowed status transitions are defined in ISSUE-18.

### Which employee can perform which service
`employee_service` links employees to the services they can perform (ISSUE-8). Admins set the links on the service form; an unchecked employee is unlinked. The form only accepts existing employees, each once, and the composite primary key rejects duplicates in the database as well.

An employee can be booked for a service only when `Employee::canPerform($service)` is true: the employee is active and linked to the service. `Employee::active()->offering($service)` returns the employees to offer for a service. The slot calculation (ISSUE-11) and booking creation (ISSUE-12) must use these, so a request for an unlinked or inactive employee is rejected server side.

Unlinking an employee does not touch existing bookings; it only prevents new ones. An active service without an active linked employee cannot be booked, and the admin service overview warns about that.

### Weekly schedule
`working_hours` holds the recurring week of each employee (ISSUE-9). Admins edit it under *Beheer → Medewerkers en werkroosters* (`/admin/employees`).

- **Weekdays** are ISO-8601 numbers, 1 = Monday ... 7 = Sunday (`App\Enums\Weekday`, `Weekday::fromDate()`), the same as PHP's `N` format.
- **Blocks:** a day can have up to six blocks; a lunch break is two blocks (09:00-12:30 and 13:30-17:00). A weekday without blocks is a day off.
- **Rules** (checked in `ScheduleRequest`): times are `HH:MM` local time; a block ends after it starts on the same day (no night shifts past midnight, latest end 23:59); blocks on one day do not overlap, but may touch (12:00-12:00).
- **Saving** replaces the whole schedule of that employee in one transaction. Existing bookings are not touched: a booking outside the new hours stays, it is only no longer offered as a new slot.
- **Storage:** times are written as `HH:MM:00` and read as `HH:MM`, so SQLite and MySQL behave the same.

For a concrete date, use `Employee::scheduleOn($localDate)` (see *Schedule exceptions and public holidays* below), not `workingHoursOn()` directly.

### Schedule exceptions and public holidays
`schedule_exceptions` holds one-off deviations per employee and date (ISSUE-10). Admins manage them under *Beheer → Medewerkers → Uitzonderingen* (`/admin/employees/{employee}/exceptions`).

- **Types:** `unavailable` (leave, sick, dentist) for the whole day (both times empty) or for a time range; `extra_hours` always with a time range.
- **Ranges of days:** the form accepts a start date and an optional end date (at most 62 days). This creates one row per day, so every row stays a single date and deleting one day of a holiday is easy.
- **Rules** (checked in `ScheduleExceptionRequest`): dates are `Y-m-d` and not in the past (today in `Europe/Amsterdam`); times are `HH:MM` and the end is after the start. Overlapping exceptions are allowed, because the result is merged anyway and is shown per date on the admin page.
- **Dutch public holidays** are calculated in code (`App\Scheduling\DutchHolidays`: fixed dates, Easter-based dates, Koningsdag on 26 April when the 27th is a Sunday), not stored. `config/holidays.php` lists which holidays close the business; by default all of them except Goede Vrijdag and Bevrijdingsdag.
- **Storage:** `date` is written as `Y-m-d` and read as midnight `Europe/Amsterdam`; times as for `working_hours`.

`Employee::scheduleOn($localDate)` returns an `App\Scheduling\DaySchedule` with the working time of that date as sorted, non-overlapping `TimeRange`s (minutes since midnight). Precedence, from low to high:

1. the weekly schedule for that weekday;
2. a closed public holiday clears the weekly hours;
3. `extra_hours` are added, also on a holiday (this is how someone works on Koningsdag);
4. `unavailable` is removed last and always wins.

Exceptions do not touch existing bookings: a booking on a new day off stays and must be moved or cancelled by hand. The slot calculation (ISSUE-11) starts from `scheduleOn($localDate)->ranges`, subtracts existing bookings and converts to UTC (ISSUE-14). Pass a local date: `scheduleOn()` only looks at the calendar date of its argument. When computing many dates, eager load `workingHours` and `scheduleExceptions` to avoid a query per day.

### Available slots
`App\Scheduling\AvailableSlots::for($service, $employee, $localDate)` returns the free start times for one service with one employee on one local date (ISSUE-11), as `Slot`s with `startsAt`, `endsAt` and `blockedUntil` in UTC, ready to store on a booking. Resolve it from the container; `config/booking.php` sets the interval. The rules live in the pure `SlotCalculator`, which has no database, clock or HTTP and is unit tested on its own.

- **Bookable at all:** nothing is offered when the service is inactive or `Employee::canPerform($service)` is false.
- **Working time:** `scheduleOn($localDate)->ranges`, so weekly hours, closed holidays, extra hours and time off are already applied.
- **Start times** step by `booking.slot_interval` minutes (default 15) from the start of every working range: 09:00, 09:15, ... and after a lunch break 13:30, 13:45, ...
- **Fit:** the appointment itself (service duration) must end within the same working range. The buffer after it may run past the end of working time (tidying up after the last customer).
- **Existing bookings:** every `requested` or `confirmed` booking of the employee blocks `starts_at` up to `blocked_until`, whatever service it is for (`Booking::blockingBetween()`). A new slot is free when its own `starts_at` up to `blocked_until` (duration + buffer) does not overlap any of them. This is the same overlap rule as above, so booking creation (ISSUE-12) re-checks with the same scope and never rejects an offered slot for overlap. Touching is allowed: a slot may start when the previous blocked time ends.
- **Past:** start times before now are left out; a slot starting exactly now is still offered. There is no minimum lead time yet.
- **Time zones:** each range boundary is converted from Europe/Amsterdam to UTC, and durations are added in elapsed time. The switch weekends are ISSUE-14.

### Creating a booking
A customer books a free slot with `POST /bookings` (`bookings.store`, ISSUE-12). Only users with the customer role may do this (`role:customer`, others get 403). The rules live in `App\Bookings\CreateBooking`, which has no HTTP and is called by `BookingController::store`.

- **Input** (`StoreBookingRequest`): `service_id`, `employee_id`, `starts_at` and optional `notes` (max 1000 characters). `starts_at` is ISO 8601 with a numeric offset, e.g. `2026-12-01T09:15:00+01:00` (`Slot::$startsAt->toIso8601String()`), so a start on the daylight-saving switch night is never ambiguous.
- **Checks**, in this order. The first one that fails refuses the booking with a `BookingRefused` carrying a Dutch message and the field to show it on:
  1. the service is active and `Employee::canPerform($service)` (on `employee_id`);
  2. **the employee is free:** no `requested`/`confirmed` booking of that employee overlaps the new `starts_at` up to `blocked_until`, buffers included (`Booking::blockingBetween()`, "Dit tijdstip is helaas net bezet") (on `starts_at`);
  3. **the customer is free:** no `requested`/`confirmed` appointment of that customer, with any employee, overlaps the new `starts_at` up to `ends_at` (`Booking::attendedByBetween()`, "Je hebt op dit tijdstip al een andere afspraak"). The buffer is not counted here, so a customer can go straight from one appointment to the next (on `starts_at`);
  4. **the start is an offered slot:** it is one of `AvailableSlots::for()` on that local date, so working time, exceptions, the interval grid, the fit in the working block and "not in the past" all apply (on `starts_at`).

  Checks 2 and 3 come before 4 so the customer gets the specific reason. Touching is allowed in both overlap checks.
- **Stored:** times from the matching `Slot` (UTC), a snapshot of `duration_minutes` and `price_cents`, status `requested`. Confirming is ISSUE-18.
- **Result:** a redirect to the booking with a confirmation. A refusal redirects back with the input and the message on the field.
- The checks and the insert run in one transaction that first locks the employee and the customer row, so simultaneous requests cannot both pass the checks (ISSUE-13, see *Concurrency*).

### Deleting data
Employees and services are **deactivated** (`is_active = false`), not deleted, because bookings reference them. Foreign keys from `bookings` use `RESTRICT` on delete. Deleting a user is out of scope for now. `employee_service`, `working_hours` and `schedule_exceptions` cascade when their employee is deleted; `employee_service` also cascades when a service (without bookings) is deleted.

## Indexes

| Table | Index | Used for |
|---|---|---|
| `users` | unique `email` | login |
| `employees` | unique `user_id` | one profile per user |
| `employee_service` | primary `(employee_id, service_id)` | prevents duplicates, lookup by employee |
| `employee_service` | `service_id` | lookup by service |
| `working_hours` | `(employee_id, weekday)` | weekly schedule per day |
| `schedule_exceptions` | `(employee_id, date)` | exceptions for a day |
| `bookings` | `(employee_id, starts_at)` | overlap check and agenda view |
| `bookings` | `(customer_id, starts_at)` | customer overview and double-booking check |
| `bookings` | `(status, starts_at, reminder_sent_at)` | reminder job (ISSUE-20) |

## Concurrency

Preventing double bookings (ISSUE-13) is done in the application, because MySQL has no exclusion constraint for time ranges. Creating a booking runs in one transaction that:

1. locks the employee row, then the customer's `users` row (`SELECT ... FOR UPDATE`, `lockForUpdate()`),
2. re-checks for overlap with the queries above,
3. inserts the booking.

Locking the `employees` row serialises bookings per employee without blocking other employees. Locking the customer's row as well serialises one customer's requests for different employees, so check 3 (the customer is free) cannot be raced either. The lock order is always employee, then customer, so two requests never wait on each other in a cycle (no deadlock). A request that has to wait sees the other's committed booking once it gets the lock and is refused with the normal message. The locks must be the first queries in the transaction, because InnoDB takes the REPEATABLE READ snapshot at the first plain read.

SQLite ignores `lockForUpdate()`. The test that replays the race (`tests/Concurrency/ConcurrentBookingTest.php`) therefore only runs on MySQL/MariaDB (the CI job *Tests (MySQL 8)*) and is skipped on SQLite.

## Open points

- **Cancellation window** (ISSUE-16) is a single global value, so it lives in config for now. If it must differ per service it becomes a column on `services`.
- **Status history** (who changed what, when) is not modelled. If it turns out to be needed for ISSUE-18, add a `booking_status_changes` table.
