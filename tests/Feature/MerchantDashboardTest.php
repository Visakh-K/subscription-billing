<?php

use App\Models\Customer;
use App\Models\DailyUsageAggregate;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\MerchantDashboardService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;

uses(LazilyRefreshDatabase::class);

/**
 * @return array{Merchant, Customer, Customer}
 */
function createMerchantDashboardFixture(): array
{
    $merchant = Merchant::create(['name' => 'Northstar Analytics', 'api_key' => 'northstar-secret']);
    $plan = Plan::create([
        'merchant_id' => $merchant->id,
        'name' => 'Growth',
        'base_price' => '49.00',
        'included_units' => 100,
        'overage_rate' => '0.1000',
    ]);

    $customers = collect([
        ['name' => 'Ada Lovelace', 'email' => 'ada@example.test'],
        ['name' => 'Grace Hopper', 'email' => 'grace@example.test'],
    ])->map(function (array $data) use ($merchant, $plan): Customer {
        $customer = Customer::create([
            'merchant_id' => $merchant->id,
            ...$data,
        ]);

        Subscription::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-11-01 00:00:00',
        ]);

        return $customer;
    });

    return [$merchant, $customers[0], $customers[1]];
}

test('dashboard metrics combine daily aggregates and recent events without double counting', function () {
    $this->travelTo('2026-10-30 12:00:00');
    [$merchant, $ada, $grace] = createMerchantDashboardFixture();
    $cancelledCustomer = Customer::create([
        'merchant_id' => $merchant->id,
        'name' => 'Katherine Johnson',
        'email' => 'katherine@example.test',
    ]);
    $plan = Plan::query()->where('merchant_id', $merchant->id)->firstOrFail();
    Subscription::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $cancelledCustomer->id,
        'plan_id' => $plan->id,
        'status' => 'cancelled',
        'current_period_start' => '2026-10-01 00:00:00',
        'current_period_end' => '2026-11-01 00:00:00',
    ]);

    DailyUsageAggregate::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $ada->id,
        'date' => '2026-10-29',
        'total_units' => 145,
        'created_at' => '2026-10-29 12:00:00',
        'updated_at' => '2026-10-29 12:00:00',
    ]);

    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $ada->id,
        'idempotency_key' => 'ada-late-event',
        'units' => 5,
        'recorded_at' => '2026-10-29 13:00:00',
        'created_at' => '2026-10-30 12:00:00',
    ]);
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $grace->id,
        'idempotency_key' => 'grace-current-month',
        'units' => 20,
        'recorded_at' => '2026-10-30 11:00:00',
    ]);
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $ada->id,
        'idempotency_key' => 'ada-previous-month',
        'units' => 100,
        'recorded_at' => '2026-09-10 11:00:00',
    ]);
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $cancelledCustomer->id,
        'idempotency_key' => 'cancelled-customer-current-month',
        'units' => 1000,
        'recorded_at' => '2026-10-30 11:00:00',
    ]);
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $grace->id,
        'idempotency_key' => 'grace-previous-month',
        'units' => 100,
        'recorded_at' => '2026-09-10 11:00:00',
    ]);

    $metrics = app(MerchantDashboardService::class)->getDashboardMetrics($merchant);

    expect($metrics['merchant'])->toBe(['id' => $merchant->id, 'name' => 'Northstar Analytics'])
        ->and($metrics['summary'])->toBe([
            'current_cycle_usage' => 170,
            'total_allowance' => 200,
            'projected_overage_revenue' => 5.5,
            'actual_overage_revenue_to_date' => 5.0,
            'active_plan_label' => 'Growth — monthly',
        ])
        ->and($metrics['top_customers'][0])->toBe([
            'id' => $ada->id,
            'name' => 'Ada Lovelace',
            'usage' => 150,
            'allowance' => 100,
            'allowance_percentage' => 150.0,
        ])
        ->and($metrics['churn_risk_customers'])->toBe([
            [
                'id' => $grace->id,
                'name' => 'Grace Hopper',
                'previous_month_usage' => 100,
                'current_month_usage' => 20,
                'drop_percentage' => 80.0,
            ],
        ])
        ->and($metrics['daily_usage_trend'])->toHaveCount(30)
        ->and($metrics['daily_usage_trend'][0]['date'])->toBe('2026-10-01')
        ->and($metrics['daily_usage_trend'][28])->toBe(['date' => '2026-10-29', 'units' => 150])
        ->and($metrics['daily_usage_trend'][29])->toBe(['date' => '2026-10-30', 'units' => 1020])
        ->and($metrics['system_status']['plan_pricing_cache'])->toBe('Redis TTL 10m')
        ->and($metrics['system_status']['nightly_aggregation_job'])->toBe('queued, chunked (15k rows/batch)')
        ->and($metrics['system_status']['usage_endpoint_rate_limit'])->toBe('120 req/min per API key');
});

test('customers with exactly a fifty percent usage drop are not marked as churn risk', function () {
    $this->travelTo('2026-10-30 12:00:00');
    [$merchant, $ada] = createMerchantDashboardFixture();

    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $ada->id,
        'idempotency_key' => 'ada-previous-month',
        'units' => 100,
        'recorded_at' => '2026-09-10 11:00:00',
    ]);
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $ada->id,
        'idempotency_key' => 'ada-current-month',
        'units' => 50,
        'recorded_at' => '2026-10-10 11:00:00',
    ]);

    $metrics = app(MerchantDashboardService::class)->getDashboardMetrics($merchant);

    expect($metrics['churn_risk_customers'])->toBe([]);
});

test('top customer list is limited to five and ordered by monthly usage', function () {
    $this->travelTo('2026-10-30 12:00:00');
    [$merchant] = createMerchantDashboardFixture();
    $plan = Plan::query()->where('merchant_id', $merchant->id)->firstOrFail();

    foreach (range(1, 5) as $units) {
        $customer = Customer::create([
            'merchant_id' => $merchant->id,
            'name' => "Customer {$units}",
            'email' => "customer{$units}@example.test",
        ]);
        Subscription::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'plan_id' => $plan->id,
            'current_period_start' => '2026-10-01 00:00:00',
            'current_period_end' => '2026-11-01 00:00:00',
        ]);
        UsageEvent::create([
            'merchant_id' => $merchant->id,
            'customer_id' => $customer->id,
            'idempotency_key' => "customer-{$units}-usage",
            'units' => $units,
            'recorded_at' => '2026-10-20 11:00:00',
        ]);
    }

    $metrics = app(MerchantDashboardService::class)->getDashboardMetrics($merchant);

    expect($metrics['top_customers'])->toHaveCount(5)
        ->and(array_column($metrics['top_customers'], 'usage'))->toBe([5, 4, 3, 2, 1]);
});

test('API dashboard requires a valid merchant key and denies cross-merchant access', function () {
    [$merchant] = createMerchantDashboardFixture();
    $otherMerchant = Merchant::create(['name' => 'Other merchant', 'api_key' => 'other-secret']);

    $this->getJson("/api/merchants/{$merchant->id}/dashboard")
        ->assertUnauthorized();

    $this->getJson("/api/merchants/{$merchant->id}/dashboard", ['X-API-Key' => $otherMerchant->api_key])
        ->assertForbidden();

    $this->getJson("/api/merchants/{$merchant->id}/dashboard", ['X-API-Key' => $merchant->api_key])
        ->assertOk()
        ->assertJsonPath('merchant.id', $merchant->id)
        ->assertJsonPath('merchant.name', 'Northstar Analytics');
});

test('web dashboard renders merchant metrics for the walkthrough', function () {
    [$merchant] = createMerchantDashboardFixture();

    $this->get("/merchants/{$merchant->id}/dashboard")
        ->assertOk()
        ->assertSeeText('Current Cycle Usage')
        ->assertSeeText('Projected Overage Revenue')
        ->assertSeeText('Active Plan')
        ->assertSeeText('Top 5 Customers by Usage')
        ->assertSeeText('Churn Risk')
        ->assertSeeText('Daily Usage Trend')
        ->assertSeeText('System status')
        ->assertSeeText('Northstar Analytics');
});
