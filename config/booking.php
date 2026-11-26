<?php

/*
|--------------------------------------------------------------------------
| Booking rules
|--------------------------------------------------------------------------
|
| `slot_interval` is the step in minutes between the start times offered to
| a customer (ISSUE-11). With 15, a 09:00-17:00 day offers 09:00, 09:15,
| 09:30, ... The steps start at the beginning of every working block, so a
| block from 13:30 offers 13:30, 13:45, ...
|
| `cancellation_window_hours` is how long before the start a customer can
| still cancel a booking themselves (ISSUE-16). With 24, an appointment on
| Tuesday 10:00 can be cancelled by the customer until Monday 10:00 (real
| elapsed hours, so across a daylight-saving switch the clock may read
| 09:00 or 11:00). After that only an admin can cancel it. 0 lets
| customers cancel until the appointment starts; negative values count
| as 0.
|
| `reminder_hours_before` is how long before the start the customer gets a
| reminder mail (ISSUE-20). The hourly `bookings:send-reminders` command
| picks up every appointment that starts within this many real hours and
| has had no reminder yet, so with 24 a reminder goes out between 24 and 23
| hours before the start. Values below 1 count as 1.
|
*/

return [

    'slot_interval' => (int) env('BOOKING_SLOT_INTERVAL', 15),

    'cancellation_window_hours' => max(0, (int) env('BOOKING_CANCELLATION_WINDOW_HOURS', 24)),

    'reminder_hours_before' => max(1, (int) env('BOOKING_REMINDER_HOURS_BEFORE', 24)),

];
