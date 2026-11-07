<?php

use App\Services\Billing\SubscriptionService;
use App\Services\Billing\TokenMeter;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// End paid periods that are over, then refresh monthly usage for members whose usage
// period ended (paid plans only while the paid period runs). Reads and asks also do
// both lazily, so a missed run only delays what the admin lists show.
Artisan::command('usage:refresh', function (SubscriptionService $subscriptions, TokenMeter $meter) {
    $this->info('Ended '.$subscriptions->expireDue().' paid period(s).');
    $this->info('Refreshed '.$meter->rollOverDue().' member usage period(s).');
})->purpose('End finished paid periods and start new monthly usage periods');

// Classes in app/Console/Commands.
Schedule::command('usage:refresh')->hourly()->withoutOverlapping();
Schedule::command('credits:sweep')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('reminders:send')->hourly()->withoutOverlapping();
Schedule::command('tickets:overdue')->hourly()->withoutOverlapping();
Schedule::command('subscriptions:remind')->dailyAt('09:00')->timezone(config('app.timezone'))->withoutOverlapping();
Schedule::command('issuances:alert')->dailyAt('07:00')->timezone(config('app.timezone'))->withoutOverlapping();
