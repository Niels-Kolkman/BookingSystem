<?php

use App\Bookings\QueueBookingReminders;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Reminder mails (ISSUE-20): queue one for every appointment that starts
 * within `booking.reminder_hours_before` hours and has had none. Safe to
 * run as often as you like; a booking is never reminded twice (see
 * QueueBookingReminders). Needs `php artisan schedule:work` (or a cron
 * calling `schedule:run` every minute) and a queue worker.
 */
Artisan::command('bookings:send-reminders', function (QueueBookingReminders $reminders) {
    $this->info($reminders->handle().' herinnering(en) in de wachtrij gezet.');
})->purpose('Queue reminder mails for upcoming appointments');

Schedule::command('bookings:send-reminders')->hourly()->withoutOverlapping();
