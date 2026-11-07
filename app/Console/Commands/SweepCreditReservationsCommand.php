<?php

namespace App\Console\Commands;

use App\Services\Billing\TokenMeter;
use Illuminate\Console\Command;

class SweepCreditReservationsCommand extends Command
{
    protected $signature = 'credits:sweep {--minutes= : Release holds older than this (default services.usage.reservation_ttl_minutes)}';

    protected $description = 'Release AI usage holds that gabaymo-ai never settled';

    public function handle(TokenMeter $meter): int
    {
        $minutes = (int) ($this->option('minutes') ?: config('services.usage.reservation_ttl_minutes', 15));
        $this->info('Released '.$meter->sweepStale($minutes).' stale hold(s).');

        return self::SUCCESS;
    }
}
