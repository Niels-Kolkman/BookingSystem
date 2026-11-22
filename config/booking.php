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
*/

return [

    'slot_interval' => (int) env('BOOKING_SLOT_INTERVAL', 15),

];
