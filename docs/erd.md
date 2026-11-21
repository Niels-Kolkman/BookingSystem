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

The customer's own double-booking check uses `starts_at`/`ends_at` only, because a buffer is the employee's cleanup time, not the customer's.

### Snapshots on the booking
`duration_minutes` and `price_cents` are copied from the service at booking time. Changing a service later must not rewrite history or move existing appointments.

### Which bookings block a slot
`requested` and `confirmed` block their time range. `cancelled` frees it. `completed` and `no_show` are in the past and never block new bookings. Allowed status transitions are defined in ISSUE-18.

### Deleting data
Employees and services are **deactivated** (`is_active = false`), not deleted, because bookings reference them. Foreign keys from `bookings` use `RESTRICT` on delete. Deleting a user is out of scope for now. `employee_service`, `working_hours` and `schedule_exceptions` cascade when their employee is deleted.

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

1. locks the employee row (`SELECT ... FOR UPDATE`, `lockForUpdate()`),
2. re-checks for overlap with the query above,
3. inserts the booking.

Locking the `employees` row serialises bookings per employee without blocking other employees.

## Open points

- **Cancellation window** (ISSUE-16) is a single global value, so it lives in config for now. If it must differ per service it becomes a column on `services`.
- **Dutch public holidays** (ISSUE-10) are not stored in a table. They are calculated in code and turned into `unavailable` exceptions, so employees can still opt in to work on a holiday.
- **Status history** (who changed what, when) is not modelled. If it turns out to be needed for ISSUE-18, add a `booking_status_changes` table.
