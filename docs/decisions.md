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

## 7. Cancelling: one policy, checked on the locked booking

**Decision:** Customers cancel their own booking until a configurable number of hours before the start (`booking.cancellation_window_hours`, default 24). After that only an admin can (ISSUE-16). The rule lives in `BookingPolicy::cancel()`. The page uses `@can('cancel', ...)` to show the form, and `CancelBooking` checks the same policy again on the booking row locked with `lockForUpdate()`.

**Why:** One source of truth for "may this user cancel now", used both to show the button and to decide. Checking it again on the locked, fresh row means a booking that was just cancelled elsewhere is not cancelled twice, and the deadline is judged at the moment of cancelling, not when the page was loaded. Employees cannot cancel, because the issue gives this right only to the customer and the admin.

## 8. Moving a booking: update in place, inside one transaction

**Decision:** Moving a booking to another time (ISSUE-17) updates the times of the same row, in one transaction that first locks the employee, the customer and the booking. The new time gets the same checks as a new booking, with the booking's own old time counted as free. Only when all checks pass are the times written. Who may move, and until when, is `BookingPolicy::reschedule()`: the same rules and deadline as cancelling. Moves stay with the same employee and service.

**Why:** The issue asks that the old slot is only freed once the change succeeds. Updating in place does this by construction: until the commit the row still holds the old time, and a refusal or error rolls back. Cancel-and-rebook would need two rows and a moment where the old one is already cancelled, and it would change the booking's id and history. Using the cancellation deadline stops moving from becoming a way around it. Changing the employee or service is a different booking (other price, duration, agenda), so it stays out of scope.

## 9. Confirmation mail: a queued job, after commit, with an .ics file

**Decision:** After a booking is made, `CreateBooking` dispatches `App\Jobs\SendBookingConfirmation` (ISSUE-19). The job runs on the queue (`QUEUE_CONNECTION=database` by default; run a worker with `php artisan queue:work`, or use `composer dev`) and sends `App\Mail\BookingConfirmation` to the customer.
- **After commit** (`ShouldQueueAfterCommit`): the job is only queued once the booking is committed. A refused or rolled-back booking never gets a mail, even when `CreateBooking` runs inside an outer transaction.
- **The worker decides:** the job only carries the booking id and loads the booking fresh, so the mail shows the time the booking has then. A booking that no longer takes up time (cancelled in the meantime) gets no confirmation.
- **Retries:** 5 tries, waiting 30 s, 2 min, 10 min, then 30 min. After that the job lands in `failed_jobs` (`php artisan queue:retry`).
- **The .ics file** (`App\Bookings\BookingCalendarEvent`, RFC 5545) holds one event from `starts_at` to `ends_at`. The buffer is the employee's time, so it is left out. Times are written in UTC (`...Z`), so the file needs no time-zone block and the daylight-saving nights need no special care. The UID is stable per booking (`booking-{id}@{APP_URL host}`), so a later file for the same booking with a higher `SEQUENCE` updates the event instead of adding one. `STATUS` is `TENTATIVE` while the booking is requested and `CONFIRMED` once it is confirmed. Text is escaped and lines are folded at 75 octets.

**Why:** The issue asks for queued, not synchronous, sending. A slow or broken mail server must not slow down or break booking; with the mail in a separate job, a mail failure is retried and never undoes the booking. Making the job, not the mailable, the queued unit lets it check the booking's state at send time. UTC in the .ics file reuses decision 3 and avoids shipping VTIMEZONE rules.

**Not in scope:** mails for moving or cancelling a booking. The reminder is decision 10. The stable UID and `SEQUENCE` are there so they can send an updated or cancelled event later.

## 10. Reminder: an hourly scan that claims each booking once

**Decision:** The command `bookings:send-reminders` runs every hour (`routes/console.php`, `withoutOverlapping()`; in production run `php artisan schedule:work` or a cron calling `schedule:run` every minute, next to a queue worker). It calls `App\Bookings\QueueBookingReminders` (ISSUE-20), which queues `App\Jobs\SendBookingReminder` for every booking that:
- still takes up time (requested or confirmed),
- starts after now and at most `booking.reminder_hours_before` real hours from now (default 24),
- has no `reminder_sent_at`,
- was made before its reminder moment. Someone who books less than 24 hours ahead just got the confirmation, so a second mail would only repeat it.

So a reminder goes out between 24 and 23 hours before the start. A missed run is caught up by the next one, until the appointment starts.
- **Never twice:** each booking is claimed with one conditional `UPDATE bookings SET reminder_sent_at = now WHERE id = ? AND reminder_sent_at IS NULL AND status IN (...) AND starts_at = ?`. Only the run whose update changed the row queues the job. Two runs at the same time (overlap, two servers, a manual run) cannot both claim it.
- **Queue down:** if dispatching the job throws, the claim is released, so the next run tries again.
- **Moving** a booking (decision 8) empties `reminder_sent_at`, so the new time gets its own reminder. The job carries the start it was claimed for. The worker loads the booking fresh and sends nothing if it is no longer requested or confirmed, if its reminder was reset, if it now starts at another time, or if it has already started. The reminder for the new time is then the only one.
- **Retries:** as for the confirmation: 5 tries, then `failed_jobs`. The claim stays, so a failed reminder is not sent again by a later run (`php artisan queue:retry` does).

**Why:** The issue allows a daily or hourly job. A daily run would send reminders anywhere from 24 to 48 hours ahead, while hourly keeps them close to "24 hours before". Marking the booking before queueing, with a conditional update, makes "at most once" a property of the database row. It does not depend on the scheduler's overlap lock or on how often the command runs. The `(status, starts_at, reminder_sent_at)` index (docs/erd.md) serves the scan.

**Trade-off:** marking before sending means a reminder whose job finally fails is lost rather than sent twice. The issue's "no double mails" is the stronger requirement, and the failure is visible in `failed_jobs`.

## 11. Agenda: a server-rendered grid in wall-clock time

**Decision:** The agenda of an employee (ISSUE-21) is `GET /employees/{employee}/agenda?view=week|day&date=Y-m-d`, rendered on the server as a CSS grid with absolutely positioned blocks. There is no calendar library or JavaScript.
- **Week** (the default) runs Monday to Sunday around the date. **Day** shows one date. Missing or unreadable input (an unknown view, a date that does not exist, a date outside 2000-2099) falls back to this week, as on the booking page.
- **Contents** per day: working hours, breaks, time off (part of the day as a block, a whole day in the day header), the public holiday, and every booking that is not cancelled. A booking links to its page and shows time, service, customer and, unless it is confirmed, its status.
- **Breaks** are the gaps between the planned working blocks (weekly schedule plus extra hours), without the time that is off anyway (`DaySchedule::breaks()`). A split shift 09:00-12:30 / 13:30-17:00 has the break 12:30-13:30.
- **Access** is unchanged (decision 4): `EmployeePolicy::viewAgenda` lets an employee see only their own agenda and an admin every agenda; other agendas answer 404. `/agenda` sends an employee to theirs and keeps `view` and `date`. Admins open agendas from *Beheer → Medewerkers*.
- **Layout in wall-clock time:** blocks are placed by the local clock time, the same as the schedule, so the rows of a week line up. On the night the clock goes back, a booking whose wall-clock end lies before its start (02:30 summer time to 02:00 winter time) is drawn with its real length. Both 02:30s then sit in the same spot, in real order, and their labels say "(zomertijd)" / "(wintertijd)". A booking across midnight is drawn on both days, clipped to each.
- **Visible hours** run from the whole hour before the earliest item in the period to the whole hour after the latest, 08:00-18:00 when there is nothing to show.
- **Queries:** `App\Scheduling\Agenda` reads working hours, the exceptions in the period and the bookings in the period with one query each, so a week costs the same as a day.

**Why:** The data and rules (schedule, exceptions, holidays, time zones) already live on the server (decisions 3 and 6). A grid of a week with a few dozen blocks does not need a client-side calendar. Wall-clock placement matches how the salon thinks about its day, and the labels keep the one ambiguous hour a year honest.

**Not in scope:** the buffer after a booking is not drawn, and there are no actions (confirm, complete) in the agenda.

## 12. Admin overview: occupancy of the working time, no-shows of finished appointments

**Decision:** The admin overview (ISSUE-22) is `GET /admin/bookings?from=Y-m-d&until=Y-m-d&employee={id}&status={status}`, linked from *Beheer*. It is a server-rendered page with a filter form, three statistics and a paged list (25 per page, filters kept in the page links).
- **Period:** local dates, inclusive. A booking belongs to the period when it starts on one of those dates. The default is the current month. Unreadable input is ignored, as on the agenda: an unknown employee or status, a date that does not exist, a date outside 2000-2099 or an array. A reversed period is swapped, and a period longer than 366 days is cut off with a notice.
- **Statistics** follow the period and the employee, **not the status filter**: a no-show rate computed over only the completed bookings would say nothing.
  - *Bookings:* the number per status.
  - *No-shows:* no-shows / (completed + no-shows), the appointments whose outcome is known. Shown as "–" when there are none.
  - *Occupancy* (`App\Scheduling\Occupancy`): the minutes of working time (weekly schedule, holidays and exceptions, `DaySchedule`) that are covered by a booking that is not cancelled, divided by all working minutes. Requested, confirmed, completed and no-show bookings all count, because their time was reserved. Booked time outside working hours is not counted, and overlaps count once, so the rate stays at or below 100%. The buffer is not counted, as in the agenda. Without an employee filter it adds up the active employees and also shows a row per employee. A chosen inactive employee is measured too.
- **Queries:** occupancy reuses `Agenda::for()`, so three queries per measured employee whatever the period length. The counts are one grouped query.

**Why:** "Occupancy" only means something against the time that could be booked, and that time already comes from `DaySchedule`. Counting wall-clock minutes inside the working ranges matches the agenda (decision 11). The night the clock changes is off by at most an hour, which is acceptable for a percentage.

**Not in scope:** export, charts, revenue, and statistics per service.

## 13. Tooling

- **Tests:** Pest (on top of PHPUnit).
- **Static analysis:** Larastan, level 6.
- **Code style:** Laravel Pint.
- **CI:** GitHub Actions (ISSUE-3).
