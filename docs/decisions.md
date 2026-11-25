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

**Why:** Daylight-saving changes make local times ambiguous (02:00-03:00 on the switch weekends). Storing UTC avoids that, and slot calculation in local time keeps "09:00" meaning 09:00 for the customer.

**How (ISSUE-14):**
- `config/app.php` fixes the app time zone to `UTC`. `Booking` converts every date to UTC before writing (`App\Models\Concerns\StoresDatesInUtc`), because Laravel would otherwise store a Carbon's own wall-clock time, so an Amsterdam time would land one or two hours off.
- `App\Scheduling\LocalTime` is the one place that converts between wall-clock time and moments, and formats moments for display. Views use `LocalTime::dateTime()` / `LocalTime::time()`, not `->timezone(...)->format(...)`.
- **Clock jumps forward** (last Sunday of March, 02:00 → 03:00): a wall-clock time between 02:00 and 03:00 does not exist and means the moment of the jump. A working block "01:00-02:30" ends at the jump, "02:30-04:00" starts at it, so no start in that hour is ever offered.
- **Clock goes back** (last Sunday of October, 03:00 → 02:00): 02:00-02:59 happens twice. A wall-clock time in it (a schedule boundary) means its first occurrence, in summer time. Slots step in real time, so both hours are offered, and they are shown as "02:30 (zomertijd)" and "02:30 (wintertijd)". The suffix only appears in that hour.
- Durations and buffers are real elapsed minutes: a 60-minute appointment at 01:30 on the March night ends at 03:30 on the clock.
- Booking input carries a numeric offset (`2026-10-25T02:30:00+01:00`), so the customer's choice is never ambiguous.
- Tests: `tests/Unit/LocalTimeTest.php`, `tests/Unit/SlotCalculatorDstTest.php`, `tests/Feature/TimeZoneTest.php`.

## 4. Authorization: role middleware + policies

**Decision:** Two layers, each with one job.
- The `role` middleware (`role:admin`, `role:employee,admin`) guards whole areas, such as the admin section.
- Policies decide per record: `BookingPolicy::view` (customer: own bookings, employee: bookings in their own agenda, admin: all) and `EmployeePolicy::viewAgenda` (employee: own agenda, admin: all).
- Lists never load everything and filter afterwards. They use `Booking::visibleTo($user)`, which applies the same rules in SQL, and a test checks that the scope and the policy agree.

**Why:** Role checks alone cannot express "own" bookings, and policies alone make area-wide rules repetitive. Denied records answer **404, not 403**, so a user cannot probe which booking or agenda ids exist.

**Note:** an employee who also books as a customer is not supported. The role is a single value, and the employee view shows their agenda, not bookings they made themselves.

## 5. Services: deactivate instead of delete, prices in cents

**Decision:** Admins manage services under `/admin/services` (ISSUE-7).
- A service that has bookings cannot be deleted; the admin deactivates it instead. Inactive services cannot be booked (`Service::active()`), but existing bookings keep pointing at them.
- Prices are entered in euros (`25`, `25,50` or `25.50`) and stored as integer cents. The conversion is string-based, with no floats involved.
- Duration is 5-480 minutes, the buffer after the appointment 0-120 minutes. `Service::blockedMinutes()` (duration + buffer) is what the slot calculation builds on.
- Editing a service only affects new bookings: a booking stores its own snapshot of duration and price.

**Why:** Deleting a booked service would break booking history, and the foreign key forbids it anyway. Cents avoid rounding errors in totals.

## 6. Booking page: server-rendered steps, enhanced with plain JavaScript

**Decision:** Customers book on `/bookings/create` (ISSUE-15) in four steps: service, employee, date, time.
- The page is complete without JavaScript: the first form goes back to the page with `service_id`, `employee_id` and `date` in the query string, and the free times are rendered on the server.
- `resources/js/booking-flow.js` (no framework) opens the steps on the page. Employees come from a list embedded in the page; free times are fetched live from `GET /bookings/slots` (JSON, customers only) on every change, and an older request still running is cancelled.
- The booking is posted to the existing `POST /bookings` as JSON. A time that was just taken by someone else (`BookingRefused::isConflict()`) answers **409** with the message; the page shows it and fetches the free times again. Other refusals answer 422 in Laravel's validation-error shape. Without JavaScript the same refusal goes back to the page, which shows the message next to fresh free times.
- Slots are sent as local time with their offset (`Slot::inputValue()`), so the two 02:30s of the night the clock goes back stay apart.

**Why:** The slot rules already live on the server (ISSUE-11 to 14); rendering the same data on the server keeps one source of truth and keeps the page usable when the script fails to load. A few small fetches did not justify a frontend framework.

## 7. Tooling

- **Tests:** Pest (on top of PHPUnit).
- **Static analysis:** Larastan, level 6.
- **Code style:** Laravel Pint.
- **CI:** GitHub Actions (ISSUE-3).
