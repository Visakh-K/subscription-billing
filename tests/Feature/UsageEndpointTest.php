<?php

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\UsageEvent;
use App\Services\PlanCacheService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(LazilyRefreshDatabase::class);

/**
 * @return array{Merchant, Customer}
 */
function createUsageEndpointFixture(): array
{
    $merchant = Merchant::create(['name' => 'Acme', 'api_key' => 'merchant-secret']);
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

    Subscription::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'plan_id' => $plan->id,
        'current_period_start' => '2026-10-01 00:00:00',
        'current_period_end' => '2026-11-01 00:00:00',
    ]);

    return [$merchant, $customer];
}

test('caches the active subscription plan within its merchant scope', function () {
    [$merchant, $customer] = createUsageEndpointFixture();
    $planCache = app(PlanCacheService::class);
    $subscription = $planCache->getActiveSubscriptionWithPlan($merchant->id, $customer->id);

    expect($subscription)->toBeInstanceOf(Subscription::class)
        ->and($subscription->relationLoaded('plan'))->toBeTrue()
        ->and($subscription->plan->merchant_id)->toBe($merchant->id)
        ->and(Cache::has("merchant_{$merchant->id}_plan_{$subscription->plan_id}"))->toBeTrue();

    $otherMerchant = Merchant::create(['name' => 'Other merchant', 'api_key' => 'other-secret']);

    expect($planCache->getPlan($otherMerchant->id, $subscription->plan_id))->toBeNull()
        ->and($planCache->getActiveSubscriptionWithPlan($otherMerchant->id, $customer->id))->toBeNull();
});

test('records usage once and serves cached replays without querying billing records', function () {
    [$merchant, $customer] = createUsageEndpointFixture();
    $usageQueries = 0;

    DB::listen(function (QueryExecuted $query) use (&$usageQueries): void {
        $sql = strtolower($query->sql);

        if (str_starts_with($sql, 'select') && preg_match('/\b(from|join)\s+"?(customers|subscriptions|plans|usage_events)/', $sql) === 1) {
            $usageQueries++;
        }
    });

    $payload = [
        'customer_id' => $customer->id,
        'units' => 3,
        'recorded_at' => '2026-10-29 12:00:00',
    ];
    $headers = [
        'X-API-Key' => $merchant->api_key,
        'Idempotency-Key' => 'request-123',
    ];

    $firstResponse = $this->postJson('/api/usage', $payload, $headers);

    $firstResponse
        ->assertCreated()
        ->assertJsonPath('idempotent_replay', false)
        ->assertJsonPath('event.customer_id', $customer->id)
        ->assertJsonPath('event.units', 3)
        ->assertJsonPath('event.idempotency_key', 'request-123');
    $this->assertDatabaseCount('usage_events', 1);
    $eventId = $firstResponse->json('event.id');
    $usageQueries = 0;

    $this->postJson('/api/usage', $payload, $headers)
        ->assertOk()
        ->assertJsonPath('idempotent_replay', true)
        ->assertJsonPath('event.id', $eventId);

    expect($usageQueries)->toBe(0);
    $this->assertDatabaseCount('usage_events', 1);
});

test('rejects requests without a valid merchant API key', function (?string $apiKey) {
    $headers = $apiKey === null ? [] : ['X-API-Key' => $apiKey];

    $this->postJson('/api/usage', [], $headers)->assertUnauthorized();
})->with([
    'missing key' => null,
    'invalid key' => 'not-a-valid-key',
]);

test('accepts a bearer token and the idempotency header', function () {
    [$merchant, $customer] = createUsageEndpointFixture();

    $this->postJson('/api/usage', [
        'customer_id' => $customer->id,
        'units' => 1,
    ], [
        'Authorization' => 'Bearer '.$merchant->api_key,
        'Idempotency-Key' => 'bearer-request',
    ])
        ->assertCreated()
        ->assertJsonPath('event.idempotency_key', 'bearer-request')
        ->assertJsonPath('event.units', 1);
});

test('returns not found when the customer belongs to another merchant', function () {
    [, $customer] = createUsageEndpointFixture();
    $otherMerchant = Merchant::create(['name' => 'Other merchant', 'api_key' => 'other-secret']);

    $this->postJson('/api/usage', [
        'customer_id' => $customer->id,
        'idempotency_key' => 'cross-tenant',
        'units' => 1,
    ], ['X-API-Key' => $otherMerchant->api_key])
        ->assertNotFound();

    $this->assertDatabaseCount('usage_events', 0);
});

test('rejects usage when the customer has no active subscription', function () {
    $merchant = Merchant::create(['name' => 'Acme', 'api_key' => 'merchant-secret']);
    $customer = Customer::create([
        'merchant_id' => $merchant->id,
        'name' => 'Ada Lovelace',
        'email' => 'ada@example.test',
    ]);

    $this->postJson('/api/usage', [
        'customer_id' => $customer->id,
        'idempotency_key' => 'inactive-customer',
        'units' => 1,
    ], ['X-API-Key' => $merchant->api_key])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('customer_id');

    $this->assertDatabaseCount('usage_events', 0);
});

test('returns the existing event when the database idempotency constraint catches a duplicate', function () {
    [$merchant, $customer] = createUsageEndpointFixture();
    $existingEvent = UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'idempotency_key' => 'database-duplicate',
        'units' => 5,
        'recorded_at' => '2026-10-29 12:00:00',
    ]);
    Cache::forget("idempotency_{$merchant->id}_database-duplicate");

    $this->postJson('/api/usage', [
        'customer_id' => $customer->id,
        'idempotency_key' => 'database-duplicate',
        'units' => 1,
    ], ['X-API-Key' => $merchant->api_key])
        ->assertOk()
        ->assertJsonPath('idempotent_replay', true)
        ->assertJsonPath('event.id', $existingEvent->id)
        ->assertJsonPath('event.units', 5);

    $this->assertDatabaseCount('usage_events', 1);
});

test('does not expose an event when another merchant used the idempotency key', function () {
    [$merchant, $customer] = createUsageEndpointFixture();
    UsageEvent::create([
        'merchant_id' => $merchant->id,
        'customer_id' => $customer->id,
        'idempotency_key' => 'shared-key',
        'units' => 5,
        'recorded_at' => '2026-10-29 12:00:00',
    ]);

    $otherMerchant = Merchant::create(['name' => 'Other merchant', 'api_key' => 'other-secret']);
    $otherCustomer = Customer::create([
        'merchant_id' => $otherMerchant->id,
        'name' => 'Grace Hopper',
        'email' => 'grace@example.test',
    ]);
    $otherPlan = Plan::create([
        'merchant_id' => $otherMerchant->id,
        'name' => 'Starter',
        'base_price' => '10.00',
        'included_units' => 100,
        'overage_rate' => '0.0100',
    ]);
    Subscription::create([
        'merchant_id' => $otherMerchant->id,
        'customer_id' => $otherCustomer->id,
        'plan_id' => $otherPlan->id,
        'current_period_start' => '2026-10-01 00:00:00',
        'current_period_end' => '2026-11-01 00:00:00',
    ]);

    $this->postJson('/api/usage', [
        'customer_id' => $otherCustomer->id,
        'idempotency_key' => 'shared-key',
        'units' => 1,
    ], ['X-API-Key' => $otherMerchant->api_key])
        ->assertConflict()
        ->assertJsonMissingPath('event');

    $this->assertDatabaseCount('usage_events', 1);
});

test('validates required fields before recording usage', function () {
    $merchant = Merchant::create(['name' => 'Acme', 'api_key' => 'merchant-secret']);

    $this->postJson('/api/usage', [], ['X-API-Key' => $merchant->api_key])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['customer_id', 'idempotency_key', 'units']);

    $this->assertDatabaseCount('usage_events', 0);
});

test('rejects invalid usage field values', function (array $payload, string $field) {
    $merchant = Merchant::create(['name' => 'Acme', 'api_key' => 'merchant-secret']);

    $this->postJson('/api/usage', $payload, ['X-API-Key' => $merchant->api_key])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    $this->assertDatabaseCount('usage_events', 0);
})->with([
    'customer ID must be an integer' => [
        ['customer_id' => 'not-an-integer', 'idempotency_key' => 'invalid-customer', 'units' => 1],
        'customer_id',
    ],
    'idempotency key must be a string' => [
        ['customer_id' => 1, 'idempotency_key' => ['invalid'], 'units' => 1],
        'idempotency_key',
    ],
    'idempotency key cannot exceed 255 characters' => [
        ['customer_id' => 1, 'idempotency_key' => str_repeat('a', 256), 'units' => 1],
        'idempotency_key',
    ],
    'units must be an integer' => [
        ['customer_id' => 1, 'idempotency_key' => 'invalid-units-type', 'units' => 'one'],
        'units',
    ],
    'units must be at least one' => [
        ['customer_id' => 1, 'idempotency_key' => 'invalid-units-value', 'units' => 0],
        'units',
    ],
    'recorded at must be a date' => [
        [
            'customer_id' => 1,
            'idempotency_key' => 'invalid-date',
            'units' => 1,
            'recorded_at' => 'not-a-date',
        ],
        'recorded_at',
    ],
]);

test('limits each API key to 120 requests per minute', function () {
    $merchant = Merchant::create(['name' => 'Acme', 'api_key' => 'merchant-secret']);

    for ($request = 0; $request < 120; $request++) {
        $response = $this->postJson('/api/usage', [], ['X-API-Key' => $merchant->api_key]);
        $response->assertUnprocessable();
    }

    $this->postJson('/api/usage', [], ['X-API-Key' => $merchant->api_key])
        ->assertTooManyRequests();
});

test('limits requests without an API key by client IP', function () {
    for ($request = 0; $request < 120; $request++) {
        $this->postJson('/api/usage')
            ->assertUnauthorized();
    }

    $this->postJson('/api/usage')
        ->assertTooManyRequests();
});
