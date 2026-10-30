<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\UsageEvent;
use Carbon\Carbon;
use InvalidArgumentException;

class BillingCalculationService
{
    public function generateInvoiceForSubscription(Subscription $subscription): Invoice
    {
        // Proration uses elapsed seconds so partial days are billed consistently.
        $periodStart = $subscription->current_period_start;
        $periodEnd = $subscription->current_period_end;
        $cycleSeconds = $periodEnd->getTimestamp() - $periodStart->getTimestamp();

        if ($cycleSeconds <= 0) {
            throw new InvalidArgumentException('The subscription billing period must have a positive duration.');
        }

        $items = SubscriptionItem::query()
            ->where('subscription_id', $subscription->id)
            ->where('starts_at', '<', $periodEnd)
            ->where(function ($query) use ($periodStart): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $periodStart);
            })
            ->orderBy('starts_at')
            ->get();

        $baseAmountCents = 0;
        $overageAmountCents = 0;

        foreach ($items as $item) {
            [$segmentStart, $segmentEnd] = $this->effectiveWindow($item, $periodStart, $periodEnd);
            $segmentSeconds = $segmentEnd->getTimestamp() - $segmentStart->getTimestamp();

            if ($segmentSeconds <= 0) {
                continue;
            }

            $factor = $segmentSeconds / $cycleSeconds;
            $baseAmountCents += (int) round((float) $item->snapshot_base_price * $factor * 100);
            $includedUnits = (int) round($item->snapshot_included_units * $factor);
            $segmentUsage = (int) UsageEvent::query()
                ->where('merchant_id', $subscription->merchant_id)
                ->where('customer_id', $subscription->customer_id)
                ->where('recorded_at', '>=', $segmentStart)
                ->where('recorded_at', '<', $segmentEnd)
                ->sum('units');
            $overageUnits = max(0, $segmentUsage - $includedUnits);
            $overageAmountCents += (int) round($overageUnits * (float) $item->snapshot_overage_rate * 100);
        }

        $baseAmount = number_format($baseAmountCents / 100, 2, '.', '');
        $overageAmount = number_format($overageAmountCents / 100, 2, '.', '');
        $totalAmount = number_format(($baseAmountCents + $overageAmountCents) / 100, 2, '.', '');

        return Invoice::query()->updateOrCreate(
            [
                'subscription_id' => $subscription->id,
                'billing_period_start' => $periodStart,
                'billing_period_end' => $periodEnd,
            ],
            [
                'merchant_id' => $subscription->merchant_id,
                'customer_id' => $subscription->customer_id,
                'base_amount' => $baseAmount,
                'overage_amount' => $overageAmount,
                'total_amount' => $totalAmount,
            ],
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function effectiveWindow(
        SubscriptionItem $item,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): array {
        $start = $item->starts_at->greaterThan($periodStart) ? $item->starts_at : $periodStart;
        $itemEnd = $item->ends_at ?? $periodEnd;
        $end = $itemEnd->lessThan($periodEnd) ? $itemEnd : $periodEnd;

        return [$start, $end];
    }
}
