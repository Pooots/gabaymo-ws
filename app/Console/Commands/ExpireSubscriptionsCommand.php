<?php

namespace App\Console\Commands;

use App\Services\Billing\SubscriptionService;
use Illuminate\Console\Command;

class ExpireSubscriptionsCommand extends Command
{
    protected $signature = 'subscriptions:expire';

    protected $description = 'Move members whose paid period ended to their organization plan or the free plan';

    public function handle(SubscriptionService $subscriptions): int
    {
        $this->info('Ended '.$subscriptions->expireDue().' paid period(s).');

        return self::SUCCESS;
    }
}
