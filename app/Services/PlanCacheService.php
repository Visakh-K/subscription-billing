<?php

namespace App\Services;

use App\Models\Plan;
use App\Models\Subscription;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

class PlanCacheService
{
    public function getPlan(int $merchantId, int $planId): ?Plan
    {
        $cacheKey = "merchant_{$merchantId}_plan_{$planId}";
        $cached = Cache::get($cacheKey);

        // If already cached as a plain array, hydrate the Plan model cleanly
        if (is_array($cached)) {
            $plan = (new Plan)->newFromBuilder($cached);

            return ((int) $plan->merchant_id === $merchantId) ? $plan : null;
        }

        // If cached as a valid Plan instance (e.g., array cache driver in tests)
        if ($cached instanceof Plan) {
            return ((int) $cached->merchant_id === $merchantId) ? $cached : null;
        }

        // Otherwise (cache miss or __PHP_Incomplete_Class), fetch fresh from DB and store as array
        $plan = Plan::where('merchant_id', $merchantId)->find($planId);

        if ($plan) {
            Cache::put($cacheKey, $plan->getAttributes(), now()->addMinutes(10));
        } else {
            Cache::forget($cacheKey);
        }

        return $plan;
    }

    /**
     * Get an active subscription with its associated plan.
     *
     * @param int $merchantId
     * @param int $customerId
     * @return ?Subscription
     */
    public function getActiveSubscriptionWithPlan(int $merchantId, int $customerId): ?Subscription
    {
        $subscription = Subscription::query()
            ->where('merchant_id', $merchantId)
            ->where('customer_id', $customerId)
            ->whereIn('status', ['active', 'upgraded', 'downgraded'])
            ->whereHas('customer', fn (Builder $query) => $query->where('merchant_id', $merchantId))
            ->first();

        if ($subscription === null) {
            return null;
        }

        $plan = $this->getPlan($merchantId, $subscription->plan_id);

        if ($plan === null) {
            return null;
        }

        $subscription->setRelation('plan', $plan);

        return $subscription;
    }
}
