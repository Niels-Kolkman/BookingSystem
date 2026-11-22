<?php

/*
|--------------------------------------------------------------------------
| Dutch public holidays (ISSUE-10)
|--------------------------------------------------------------------------
|
| Holidays are calculated in App\Scheduling\DutchHolidays. On the holidays
| listed under `closed` nobody is bookable, whatever the weekly schedule
| says. An admin can still let an employee work on such a day by adding
| "extra uren" for that date.
|
| Available keys: nieuwjaarsdag, goede_vrijdag, eerste_paasdag,
| tweede_paasdag, koningsdag, bevrijdingsdag, hemelvaartsdag,
| eerste_pinksterdag, tweede_pinksterdag, eerste_kerstdag, tweede_kerstdag.
|
| Goede Vrijdag and Bevrijdingsdag are left open by default: they are not a
| general day off in the Netherlands (Bevrijdingsdag only once every five
| years in many collective agreements). Add them here to close.
|
*/

return [

    'closed' => [
        'nieuwjaarsdag',
        'eerste_paasdag',
        'tweede_paasdag',
        'koningsdag',
        'hemelvaartsdag',
        'eerste_pinksterdag',
        'tweede_pinksterdag',
        'eerste_kerstdag',
        'tweede_kerstdag',
    ],

];
