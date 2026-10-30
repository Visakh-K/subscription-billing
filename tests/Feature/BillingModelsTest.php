<?php

use App\Models\Customer;
use App\Models\DailyUsageAggregate;
use App\Models\Invoice;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\SubscriptionItem;
use App\Models\UsageEvent;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(LazilyRefreshDatabase::class);

test('billing records expose their relationships and cast date and monetary fields', function () {
    $merchant = Merchant::create(['name' => 'Acme']);
    $customer = Customer::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ]);
    $plan = Plan::create([
        'merchant_id' => $merchant->id,
        'name' => 'Pro',
        'base_price' => '29.95',
        'included_units' => 1000,
        'overage_rate' => '0.0125',
    ]);
    $subscription = Subscription::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'current_period_start' => '2026-10-01 00:00:00',
        'current_period_end' => '2026-11-01 00:00:00',
    ]);
    $subscriptionItem = SubscriptionItem::create([
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'starts_at' => '2026-10-01 00:00:00',
        'snapshot_base_price' => '29.95',
        'snapshot_included_units' => 1000,
        'snapshot_overage_rate' => '0.0125',
    ]);
    $usageEvent = UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'idempotency_key' => 'event-1',
        'recorded_at' => '2026-10-29 12:00:00',
    ]);
    $dailyAggregate = DailyUsageAggregate::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'date' => '2026-10-29',
        'total_units' => 1,
    ]);
    $invoice = Invoice::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'billing_period_start' => '2026-10-01 00:00:00',
        'billing_period_end' => '2026-11-01 00:00:00',
        'base_amount' => '29.95',
        'overage_amount' => '1.25',
        'total_amount' => '31.20',
    ]);

    expect($merchant->plans)->toHaveCount(1)
        ->and($merchant->customers)->toHaveCount(1)
        ->and($merchant->subscriptions)->toHaveCount(1)
        ->and($merchant->usageEvents)->toHaveCount(1)
        ->and($merchant->dailyUsageAggregates)->toHaveCount(1)
        ->and($merchant->invoices)->toHaveCount(1)
        ->and($plan->merchant->is($merchant))->toBeTrue()
        ->and($plan->subscriptions)->toHaveCount(1)
        ->and($plan->subscriptionItems)->toHaveCount(1)
        ->and($customer->merchant->is($merchant))->toBeTrue()
        ->and($customer->subscriptions)->toHaveCount(1)
        ->and($customer->usageEvents)->toHaveCount(1)
        ->and($customer->dailyUsageAggregates)->toHaveCount(1)
        ->and($customer->invoices)->toHaveCount(1)
        ->and($subscription->merchant->is($merchant))->toBeTrue()
        ->and($subscription->customer->is($customer))->toBeTrue()
        ->and($subscription->plan->is($plan))->toBeTrue()
        ->and($subscription->items)->toHaveCount(1)
        ->and($subscription->invoices)->toHaveCount(1)
        ->and($subscriptionItem->subscription->is($subscription))->toBeTrue()
        ->and($subscriptionItem->plan->is($plan))->toBeTrue()
        ->and($usageEvent->merchant->is($merchant))->toBeTrue()
        ->and($usageEvent->customer->is($customer))->toBeTrue()
        ->and($dailyAggregate->merchant->is($merchant))->toBeTrue()
        ->and($dailyAggregate->customer->is($customer))->toBeTrue()
        ->and($invoice->merchant->is($merchant))->toBeTrue()
        ->and($invoice->customer->is($customer))->toBeTrue()
        ->and($invoice->subscription->is($subscription))->toBeTrue()
        ->and($plan->base_price)->toBe('29.95')
        ->and($plan->overage_rate)->toBe('0.0125')
        ->and($subscription->current_period_start)->toBeInstanceOf(DateTimeInterface::class)
        ->and($subscriptionItem->ends_at)->toBeNull()
        ->and($usageEvent->recorded_at)->toBeInstanceOf(DateTimeInterface::class)
        ->and($dailyAggregate->date)->toBeInstanceOf(DateTimeInterface::class)
        ->and($invoice->total_amount)->toBe('31.20');
});

test('saving and deleting a plan invalidate its merchant-scoped cache key', function () {
    $merchant = Merchant::create(['name' => 'Acme']);
    $plan = Plan::create([
        'merchant_id' => $merchant->id,
        'name' => 'Pro',
        'base_price' => '29.95',
        'included_units' => 1000,
        'overage_rate' => '0.0125',
    ]);
    $cacheKey = "merchant_{$merchant->id}_plan_{$plan->id}";

    Cache::put($cacheKey, ['cached' => true]);
    $plan->update(['name' => 'Business']);

    expect(Cache::has($cacheKey))->toBeFalse();

    Cache::put($cacheKey, ['cached' => true]);
    $plan->delete();

    expect(Cache::has($cacheKey))->toBeFalse();
});

test('deleting a merchant cascades to its billing records', function () {
    $merchant = Merchant::create(['name' => 'Acme']);
    $customer = Customer::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ]);
    $plan = Plan::create([
        'merchant_id' => $merchant->id,
        'name' => 'Pro',
        'base_price' => '29.95',
        'included_units' => 1000,
        'overage_rate' => '0.0125',
    ]);
    $subscription = Subscription::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'current_period_start' => '2026-10-01 00:00:00',
        'current_period_end' => '2026-11-01 00:00:00',
    ]);
    SubscriptionItem::create([
        'subscription_id' => $subscription->id,
        'plan_id' => $plan->id,
        'starts_at' => '2026-10-01 00:00:00',
        'snapshot_base_price' => '29.95',
        'snapshot_included_units' => 1000,
        'snapshot_overage_rate' => '0.0125',
    ]);
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'idempotency_key' => 'event-1',
        'recorded_at' => '2026-10-29 12:00:00',
    ]);
    DailyUsageAggregate::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'date' => '2026-10-29',
        'total_units' => 1,
    ]);
    Invoice::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'subscription_id' => $subscription->id,
        'billing_period_start' => '2026-10-01 00:00:00',
        'billing_period_end' => '2026-11-01 00:00:00',
        'base_amount' => '29.95',
        'overage_amount' => '1.25',
        'total_amount' => '31.20',
    ]);

    $merchant->delete();

    $this->assertDatabaseMissing('merchants', ['id' => $merchant->id]);
    $this->assertDatabaseCount('plans', 0);
    $this->assertDatabaseCount('customers', 0);
    $this->assertDatabaseCount('subscriptions', 0);
    $this->assertDatabaseCount('subscription_items', 0);
    $this->assertDatabaseCount('usage_events', 0);
    $this->assertDatabaseCount('daily_usage_aggregates', 0);
    $this->assertDatabaseCount('invoices', 0);
});
