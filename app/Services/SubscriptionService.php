<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class SubscriptionService
{
    public function changePlan(
        Subscription $subscription,
        Plan $newPlan,
        ?Carbon $changedAt = null,
    ): SubscriptionItem {
        $changedAt ??= now();

        return DB::transaction(function () use ($subscription, $newPlan, $changedAt): SubscriptionItem {
            $lockedSubscription = Subscription::query()
                ->whereKey($subscription->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($newPlan->merchant_id !== $lockedSubscription->merchant_id) {
                throw (new ModelNotFoundException)->setModel(Plan::class, [$newPlan->id]);
            }

            $periodEnd = $lockedSubscription->current_period_end;

            if ($changedAt->greaterThanOrEqualTo($periodEnd)) {
                throw new InvalidArgumentException('A plan change must start before the current billing period ends.');
            }

            $activeItem = SubscriptionItem::query()
                ->where('subscription_id', $lockedSubscription->id)
                ->where('starts_at', '<=', $changedAt)
                ->where(function ($query) use ($changedAt): void {
                    $query->whereNull('ends_at')->orWhere('ends_at', '>=', $changedAt);
                })
                ->orderByDesc('starts_at')
                ->lockForUpdate()
                ->first();

            $currentPrice = $activeItem?->snapshot_base_price
                ?? Plan::query()
                    ->whereKey($lockedSubscription->plan_id)
                    ->value('base_price');

            if ($activeItem !== null) {
                $activeItem->ends_at = $changedAt;
                $activeItem->save();
            }

            $newItem = $lockedSubscription->items()->create([
                'plan_id' => $newPlan->id,
                'starts_at' => $changedAt,
                'ends_at' => $periodEnd,
                'snapshot_base_price' => $newPlan->base_price,
                'snapshot_included_units' => $newPlan->included_units,
                'snapshot_overage_rate' => $newPlan->overage_rate,
            ]);

            $lockedSubscription->plan_id = $newPlan->id;
            $lockedSubscription->status = match (true) {
                $currentPrice === null || (float) $newPlan->base_price === (float) $currentPrice => 'active',
                (float) $newPlan->base_price > (float) $currentPrice => 'upgraded',
                default => 'downgraded',
            };
            $lockedSubscription->save();

            return $newItem;
        });
    }

    public function createSubscription(
        Customer $customer,
        Plan $plan,
        Carbon $startsAt,
        Carbon $periodStart,
        Carbon $periodEnd,
    ): Subscription {
        if ($customer->merchant_id !== $plan->merchant_id) {
            throw (new ModelNotFoundException)->setModel(Plan::class, [$plan->id]);
        }

        if ($periodEnd->lessThanOrEqualTo($periodStart) || $startsAt->greaterThanOrEqualTo($periodEnd)) {
            throw new InvalidArgumentException('Subscription dates must define a non-empty period and start before it ends.');
        }

        return DB::transaction(function () use ($customer, $plan, $startsAt, $periodStart, $periodEnd): Subscription {
            $subscription = Subscription::query()->create([
                'merchant_id' => $customer->merchant_id,
                'customer_id' => $customer->id,
                'plan_id' => $plan->id,
                'status' => 'active',
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
            ]);

            $subscription->items()->create([
                'plan_id' => $plan->id,
                'starts_at' => $startsAt,
                'ends_at' => $periodEnd,
                'snapshot_base_price' => $plan->base_price,
                'snapshot_included_units' => $plan->included_units,
                'snapshot_overage_rate' => $plan->overage_rate,
            ]);

            return $subscription;
        });
    }
}
