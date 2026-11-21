# Architecture decisions

Short records of the choices that shape this project, and why they were made.

## 1. Framework: Laravel 12 (PHP 8.2+)

**Decision:** Build the booking system on Laravel 12.

**Why:**
- Queues, scheduled jobs, mail, policies and Eloquent cover most of the feature list without extra packages.
- Pest and Larastan give a strong testing and static-analysis setup, which matters for the booking logic.
- It is the framework I know best, so the effort goes into the hard part (availability and concurrency), not into learning tooling.

## 2. Database: MySQL / MariaDB (InnoDB)

**Decision:** MySQL/MariaDB with InnoDB is the target database for development and production. The fast test suite runs on in-memory SQLite.

**Why:**
- The core requirement is that two simultaneous requests can never book the same slot. That needs real transactions and row-level locking (`SELECT ... FOR UPDATE`, in Laravel `lockForUpdate()`), which InnoDB provides.
- SQLite is not suitable for that guarantee: it has no row locks and serialises all writes, so it would hide the race condition instead of proving it is solved.
- MySQL/MariaDB is already available on the development machine (XAMPP) and runs fine on a Raspberry Pi for hosting.

**Trade-off:** PostgreSQL could enforce non-overlapping time ranges with an exclusion constraint, which is more elegant. MySQL has no equivalent, so overlap is prevented in the application: a transaction that locks the employee row, re-checks for overlap, then inserts. The concurrency test (ISSUE-13) must run against MySQL in CI, not SQLite.

## 3. Time handling: store UTC, display Europe/Amsterdam

**Decision:** All timestamps are stored in UTC. Presentation and slot calculation use the `Europe/Amsterdam` time zone.

**Why:** Daylight-saving changes make local times ambiguous (02:00-03:00 on the switch weekends). Storing UTC avoids that, and slot calculation in local time keeps "09:00" meaning 09:00 for the customer. Details and tests are tracked in ISSUE-14.

## 4. Authorization: role middleware + policies

**Decision:** Two layers, each with one job.
- The `role` middleware (`role:admin`, `role:employee,admin`) guards whole areas, such as the admin section.
- Policies decide per record: `BookingPolicy::view` (customer: own bookings, employee: bookings in their own agenda, admin: all) and `EmployeePolicy::viewAgenda` (employee: own agenda, admin: all).
- Lists never load everything and filter afterwards. They use `Booking::visibleTo($user)`, which applies the same rules in SQL, and a test checks that the scope and the policy agree.

**Why:** Role checks alone cannot express "own" bookings, and policies alone make area-wide rules repetitive. Denied records answer **404, not 403**, so a user cannot probe which booking or agenda ids exist.

**Note:** an employee who also books as a customer is not supported. The role is a single value, and the employee view shows their agenda, not bookings they made themselves.

## 5. Tooling

- **Tests:** Pest (on top of PHPUnit).
- **Static analysis:** Larastan, level 6.
- **Code style:** Laravel Pint.
- **CI:** GitHub Actions (ISSUE-3).
