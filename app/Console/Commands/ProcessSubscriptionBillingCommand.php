<?php

namespace App\Console\Commands;

use App\Services\Landlord\SubscriptionBillingService;
use Illuminate\Console\Command;

class ProcessSubscriptionBillingCommand extends Command
{
    protected $signature = 'saas:process-subscriptions';
    protected $description = 'Process due SaaS subscription renewals, grace periods, and suspensions.';

    public function handle(SubscriptionBillingService $billing): int
    {
        $result = $billing->processDueSubscriptions();
        $this->components->info("Renewed: {$result['renewed']}; grace started: {$result['grace_started']}; suspended: {$result['suspended']}.");
        return self::SUCCESS;
    }
}