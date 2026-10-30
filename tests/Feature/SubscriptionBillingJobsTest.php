<?php

use App\Jobs\AggregateDailyUsageJob;
use App\Jobs\GenerateCycleEndInvoicesJob;
use App\Models\Customer;
use App\Models\DailyUsageAggregate;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\UsageEvent;
use App\Services\BillingCalculationService;
use App\Services\PlanCacheService;
use App\Services\SubscriptionService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

/**
 * @return array{Merchant, Customer, Plan}
 */
function createSubscriptionFixture(): array
{
    $merchant = Merchant::create(['name' => 'Acme']);
    $customer = Customer::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ]);
    $plan = Plan::create([
        'merchant_id' => $merchant->id,
        'name' => 'Starter',
        'base_price' => '100.00',
        'included_units' => 1000,
        'overage_rate' => '0.1000',
    ]);

    return [$merchant, $customer, $plan];
}

test('creates a subscription and initial plan segment for a mid-cycle signup', function () {
    [$merchant, $customer, $plan] = createSubscriptionFixture();

    $subscription = app(SubscriptionService::class)->createSubscription(
        $customer,
        $plan,
        Carbon::parse('2026-10-06 00:00:00'),
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-11 00:00:00'),
    );

    expect($subscription->merchant_id)->toBe($merchant->id)
        ->and($subscription->status)->toBe('active')
        ->and($subscription->items)->toHaveCount(1)
        ->and($subscription->items->first()->starts_at->toDateTimeString())->toBe('2026-10-06 00:00:00')
        ->and($subscription->items->first()->ends_at->toDateTimeString())->toBe('2026-10-11 00:00:00');
});

test('closes the current segment and records upgrade and downgrade plan snapshots', function () {
    [, $customer, $starter] = createSubscriptionFixture();
    $service = app(SubscriptionService::class);
    $subscription = $service->createSubscription(
        $customer,
        $starter,
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-11 00:00:00'),
    );
    $upgrade = Plan::create([
        'merchant_id' => $customer->merchant_id,
        'name' => 'Pro',
        'base_price' => '200.00',
        'included_units' => 2000,
        'overage_rate' => '0.2000',
    ]);
    $downgrade = Plan::create([
        'merchant_id' => $customer->merchant_id,
        'name' => 'Basic',
        'base_price' => '50.00',
        'included_units' => 500,
        'overage_rate' => '0.0500',
    ]);
    $samePricePlan = Plan::create([
        'merchant_id' => $customer->merchant_id,
        'name' => 'Basic Plus',
        'base_price' => '50.00',
        'included_units' => 750,
        'overage_rate' => '0.0500',
    ]);

    $upgradeItem = $service->changePlan($subscription, $upgrade, Carbon::parse('2026-10-06 00:00:00'));

    expect($upgradeItem->snapshot_base_price)->toBe('200.00')
        ->and($upgradeItem->snapshot_included_units)->toBe(2000)
        ->and($subscription->fresh()->status)->toBe('upgraded')
        ->and($subscription->fresh()->plan_id)->toBe($upgrade->id)
        ->and($subscription->items()->where('plan_id', $starter->id)->first()->ends_at->toDateTimeString())
        ->toBe('2026-10-06 00:00:00');

    expect(app(PlanCacheService::class)
        ->getActiveSubscriptionWithPlan($customer->merchant_id, $customer->id))
        ->not->toBeNull();

    $downgradeItem = $service->changePlan($subscription->fresh(), $downgrade, Carbon::parse('2026-10-08 00:00:00'));

    expect($downgradeItem->snapshot_base_price)->toBe('50.00')
        ->and($subscription->fresh()->status)->toBe('downgraded')
        ->and($subscription->fresh()->items)->toHaveCount(3);

    $service->changePlan($subscription->fresh(), $samePricePlan, Carbon::parse('2026-10-09 00:00:00'));

    expect($subscription->fresh()->status)->toBe('active')
        ->and($subscription->fresh()->items)->toHaveCount(4);
});

test('rejects plan changes to a plan owned by another merchant', function () {
    [, $customer, $plan] = createSubscriptionFixture();
    $subscription = app(SubscriptionService::class)->createSubscription(
        $customer,
        $plan,
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-11 00:00:00'),
    );
    $otherMerchant = Merchant::create(['name' => 'Other merchant']);
    $otherPlan = Plan::create([
        'merchant_id' => $otherMerchant->id,
        'name' => 'Other',
        'base_price' => '200.00',
        'included_units' => 2000,
        'overage_rate' => '0.2000',
    ]);

    expect(fn () => app(SubscriptionService::class)->changePlan(
        $subscription,
        $otherPlan,
        Carbon::parse('2026-10-06 00:00:00'),
    ))->toThrow(ModelNotFoundException::class);

    expect($subscription->fresh()->items)->toHaveCount(1);
});

test('prorates each plan segment and usage overages and updates the same invoice on reruns', function () {
    [, $customer, $starter] = createSubscriptionFixture();
    $subscriptionService = app(SubscriptionService::class);
    $subscription = $subscriptionService->createSubscription(
        $customer,
        $starter,
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-11 00:00:00'),
    );
    $pro = Plan::create([
        'merchant_id' => $customer->merchant_id,
        'name' => 'Pro',
        'base_price' => '200.00',
        'included_units' => 2000,
        'overage_rate' => '0.2000',
    ]);
    $subscriptionService->changePlan($subscription, $pro, Carbon::parse('2026-10-06 00:00:00'));

    foreach ([600, 600, 600] as $index => $units) {
        UsageEvent::create([
            'merchant_id' => $customer->merchant_id,
            'customer_id' => $customer->id,
            'idempotency_key' => 'billing-usage-'.$index,
            'units' => $units,
            'recorded_at' => $index === 0 ? '2026-10-03 12:00:00' : '2026-10-08 12:00:00',
        ]);
    }

    UsageEvent::create([
        'merchant_id' => $customer->merchant_id,
        'customer_id' => $customer->id,
        'idempotency_key' => 'outside-billing-period',
        'units' => 10000,
        'recorded_at' => '2026-10-11 00:00:00',
    ]);

    $billingService = app(BillingCalculationService::class);
    $invoice = $billingService->generateInvoiceForSubscription($subscription);
    $updatedInvoice = $billingService->generateInvoiceForSubscription($subscription);

    expect($invoice->base_amount)->toBe('150.00')
        ->and($invoice->overage_amount)->toBe('50.00')
        ->and($invoice->total_amount)->toBe('200.00')
        ->and($updatedInvoice->is($invoice))->toBeTrue();

    $this->assertDatabaseCount('invoices', 1);
});

test('aggregates yesterday when no date is provided', function () {
    [$merchant, $customer] = createSubscriptionFixture();
    $this->travelTo(Carbon::parse('2026-10-30 12:00:00'));
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'idempotency_key' => 'default-aggregation-date',
        'units' => 7,
        'recorded_at' => '2026-10-29 12:00:00',
    ]);

    (new AggregateDailyUsageJob)->handle();

    expect(DailyUsageAggregate::query()->firstOrFail()->total_units)->toBe(7)
        ->and(DailyUsageAggregate::query()->firstOrFail()->date->toDateString())->toBe('2026-10-29');
});

test('cycle-end billing generates invoices for expired subscriptions only', function () {
    [, $customer, $plan] = createSubscriptionFixture();
    $service = app(SubscriptionService::class);
    $service->createSubscription(
        $customer,
        $plan,
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-01 00:00:00'),
        Carbon::parse('2026-10-10 00:00:00'),
    );

    $futureCustomer = Customer::create([
        'merchant_id' => $customer->merchant_id,
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
    ]);
    $service->createSubscription(
        $futureCustomer,
        $plan,
        Carbon::parse('2026-10-29 00:00:00'),
        Carbon::parse('2026-10-29 00:00:00'),
        Carbon::parse('2026-11-10 00:00:00'),
    );

    $this->travelTo(Carbon::parse('2026-10-29 12:00:00'));
    (new GenerateCycleEndInvoicesJob)->handle(app(BillingCalculationService::class));

    $this->assertDatabaseCount('invoices', 1);
    expect(Invoice::query()->first()->customer_id)->toBe($customer->id);
});
