<?php

namespace App\Jobs;

use App\Models\Subscription;
use App\Services\BillingCalculationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class GenerateCycleEndInvoicesJob implements ShouldQueue
{
    use Queueable;

    public function handle(BillingCalculationService $billingCalculationService): void
    {
        Subscription::query()
            ->where('current_period_end', '<=', now())
            ->chunkById(500, function ($subscriptions) use ($billingCalculationService): void {
                foreach ($subscriptions as $subscription) {
                    $billingCalculationService->generateInvoiceForSubscription($subscription);
                }
            });
    }
}
