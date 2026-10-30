<?php

namespace App\Services;

use App\Models\DailyUsageAggregate;
use App\Models\Merchant;
use App\Models\Subscription;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\JoinClause;

class MerchantDashboardService
{
    private const ACTIVE_SUBSCRIPTION_STATUSES = ['active', 'upgraded', 'downgraded'];

    /**
     * @return array
     */
    public function getDashboardMetrics(Merchant $merchant): array
    {
        $now = now();
        $currentMonthStart = $now->copy()->startOfMonth();
        $currentMonthEnd = $now->copy()->endOfMonth();
        $previousMonthStart = $now->copy()->subMonthNoOverflow()->startOfMonth();
        $previousMonthEnd = $previousMonthStart->copy()->endOfMonth();
        $trendStart = $now->copy()->subDays(29)->startOfDay();
        $usageStart = $previousMonthStart->lessThan($trendStart) ? $previousMonthStart : $trendStart;

        $subscriptions = $merchant->subscriptions()
            ->whereIn('status', self::ACTIVE_SUBSCRIPTION_STATUSES)
            ->whereHas('customer', fn(Builder $query): Builder => $query->where('merchant_id', $merchant->id))
            ->whereHas('plan', fn(Builder $query): Builder => $query->where('merchant_id', $merchant->id))
            ->with([
                'customer:id,merchant_id,name',
                'plan:id,merchant_id,name,billing_cycle,included_units,overage_rate',
            ])
            ->orderBy('id')
            ->get();

        $activeCustomerIds = $subscriptions->pluck('customer_id')->unique()->values()->all();
        $usageByCustomerAndDate = $this->getUsageByCustomerAndDate(
            $merchant,
            $usageStart,
            $currentMonthEnd,
        );
        $currentUsageByCustomer = [];
        $previousUsageByCustomer = [];

        foreach ($activeCustomerIds as $customerId) {
            foreach ($usageByCustomerAndDate[$customerId] ?? [] as $date => $units) {
                if ($date >= $currentMonthStart->toDateString() && $date <= $currentMonthEnd->toDateString()) {
                    $currentUsageByCustomer[$customerId] = ($currentUsageByCustomer[$customerId] ?? 0) + $units;
                }

                if ($date >= $previousMonthStart->toDateString() && $date <= $previousMonthEnd->toDateString()) {
                    $previousUsageByCustomer[$customerId] = ($previousUsageByCustomer[$customerId] ?? 0) + $units;
                }
            }
        }

        $totalAllowance = 0;
        $projectedOverageRevenue = 0.0;
        $actualOverageRevenue = 0.0;
        $planCounts = [];
        $daysElapsed = $now->day;
        $totalDaysInCycle = $now->daysInMonth;

        foreach ($subscriptions as $subscription) {
            $plan = $subscription->plan;
            $customerUsage = $currentUsageByCustomer[$subscription->customer_id] ?? 0;
            $allowance = (int) $plan->included_units;
            $overageRate = (float) $plan->overage_rate;
            $projectedUnits = $daysElapsed > 0
                ? ($customerUsage / $daysElapsed) * $totalDaysInCycle
                : $customerUsage;
            $projectedUnits = max($customerUsage, $projectedUnits);

            $totalAllowance += $allowance;
            $projectedOverageRevenue += max(0, $projectedUnits - $allowance) * $overageRate;
            $actualOverageRevenue += max(0, $customerUsage - $allowance) * $overageRate;

            $planLabel = "{$plan->name} — {$plan->billing_cycle}";
            $planCounts[$planLabel] = ($planCounts[$planLabel] ?? 0) + 1;
        }

        arsort($planCounts);
        $activePlanLabel = array_key_first($planCounts) ?? 'N/A';

        $customers = $subscriptions
            ->map(fn(Subscription $subscription) => $subscription->customer)
            ->unique('id')
            ->sortBy('id')
            ->values();

        $customerMetrics = $customers->map(function ($customer) use ($currentUsageByCustomer, $subscriptions): array {
            $customerSubscription = $subscriptions
                ->where('customer_id', $customer->id)
                ->last();
            $usage = $currentUsageByCustomer[$customer->id] ?? 0;
            $allowance = (int) $customerSubscription->plan->included_units;

            return [
                'id' => (int) $customer->id,
                'name' => $customer->name,
                'usage' => $usage,
                'allowance' => $allowance,
                'allowance_percentage' => round(($usage / max(1, $allowance)) * 100, 1),
            ];
        });

        $topCustomers = $customerMetrics
            ->sortByDesc('usage')
            ->take(5)
            ->values()
            ->all();

        $churnRiskCustomers = [];

        foreach ($customers as $customer) {
            $previousUsage = $previousUsageByCustomer[$customer->id] ?? 0;

            if ($previousUsage <= 0) {
                continue;
            }

            $currentUsage = $currentUsageByCustomer[$customer->id] ?? 0;
            $dropPercentage = round((($previousUsage - $currentUsage) / $previousUsage) * 100, 1);

            if ($dropPercentage > 50) {
                $churnRiskCustomers[] = [
                    'id' => (int) $customer->id,
                    'name' => $customer->name,
                    'previous_month_usage' => $previousUsage,
                    'current_month_usage' => $currentUsage,
                    'drop_percentage' => $dropPercentage,
                ];
            }
        }

        usort(
            $churnRiskCustomers,
            fn(array $first, array $second): int => $second['drop_percentage'] <=> $first['drop_percentage'],
        );

        $dailyUsageTrend = [];

        for ($date = $trendStart->copy(); $date->lte($now); $date->addDay()) {
            $dateString = $date->toDateString();
            $dailyUnits = 0;

            foreach ($usageByCustomerAndDate as $dailyUsage) {
                $dailyUnits += $dailyUsage[$dateString] ?? 0;
            }

            $dailyUsageTrend[] = [
                'date' => $dateString,
                'units' => $dailyUnits,
            ];
        }

        return [
            'merchant' => [
                'id' => (int) $merchant->id,
                'name' => $merchant->name,
            ],
            'summary' => [
                'current_cycle_usage' => array_sum($currentUsageByCustomer),
                'total_allowance' => $totalAllowance,
                'projected_overage_revenue' => round($projectedOverageRevenue, 2),
                'actual_overage_revenue_to_date' => round($actualOverageRevenue, 2),
                'active_plan_label' => $activePlanLabel,
            ],
            'top_customers' => $topCustomers,
            'churn_risk_customers' => $churnRiskCustomers,
            'daily_usage_trend' => $dailyUsageTrend,
            'system_status' => [
                'plan_pricing_cache' => 'Redis TTL 10m',
                'nightly_aggregation_job' => 'queued, chunked (15k rows/batch)',
                'usage_endpoint_rate_limit' => '120 req/min per API key',
            ],
        ];
    }

    /**
     * Load daily totals from completed aggregates and add events that are not yet represented there.
     *
     * @return array<int, array<string, int>>
     */
    private function getUsageByCustomerAndDate(Merchant $merchant, Carbon $start, Carbon $end): array
    {
        $usageByCustomerAndDate = [];

        $aggregates = DailyUsageAggregate::query()
            ->where('merchant_id', $merchant->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('customer_id, date, SUM(total_units) as units')
            ->groupBy('customer_id', 'date')
            ->get();

        foreach ($aggregates as $aggregate) {
            $date = $aggregate->date->toDateString();
            $usageByCustomerAndDate[$aggregate->customer_id][$date] = (int) $aggregate->units;
        }

        $unaggregatedEvents = UsageEvent::query()
            ->leftJoin('daily_usage_aggregates as aggregates', function (JoinClause $join): void {
                $join->on('aggregates.merchant_id', '=', 'usage_events.merchant_id')
                    ->on('aggregates.customer_id', '=', 'usage_events.customer_id')
                    ->whereRaw('aggregates.date = DATE(usage_events.recorded_at)');
            })
            ->where('usage_events.merchant_id', $merchant->id)
            ->whereBetween('usage_events.recorded_at', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->where(function (Builder $query): void {
                $query->whereNull('aggregates.id')
                    ->orWhereColumn('usage_events.created_at', '>', 'aggregates.updated_at');
            })
            ->selectRaw('usage_events.customer_id, DATE(usage_events.recorded_at) as usage_date, SUM(usage_events.units) as units')
            ->groupBy('usage_events.customer_id')
            ->groupByRaw('DATE(usage_events.recorded_at)')
            ->get();

        foreach ($unaggregatedEvents as $eventGroup) {
            $customerId = (int) $eventGroup->customer_id;
            $date = (string) $eventGroup->usage_date;
            $usageByCustomerAndDate[$customerId][$date] = ($usageByCustomerAndDate[$customerId][$date] ?? 0)
                + (int) $eventGroup->units;
        }

        return $usageByCustomerAndDate;
    }
}
