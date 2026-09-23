<?php

namespace Modules\Packages\Console;

use Illuminate\Console\Command;
use Modules\Packages\Services\SubscriptionService;

class ExpireSubscriptionsCommand extends Command
{
    /**
     * The name and signature of the console command.
     */
    protected $signature = 'subscriptions:expire';

    /**
     * The console command description.
     */
    protected $description = 'Mark active subscriptions whose ends_at has passed as expired';

    public function handle(SubscriptionService $subscriptions): int
    {
        $count = $subscriptions->expireExpired();

        $this->info("Expired {$count} subscription(s).");

        return self::SUCCESS;
    }
}
