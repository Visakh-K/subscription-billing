<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\UsageEvent;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

class UsageIngestionService
{
    public function __construct(private PlanCacheService $planCacheService) {}

    /**
     * @param  array{customer_id: int|string, idempotency_key: string, units: int|string, recorded_at?: mixed}  $data
     * @return array{event: array<string, mixed>, idempotent_replay: bool}
     */
    public function record(Merchant $merchant, array $data): array
    {
        $cacheKey = "idempotency_{$merchant->id}_{$data['idempotency_key']}";
        $cachedEvent = Cache::get($cacheKey);

        if ($cachedEvent !== null) {
            return ['event' => $cachedEvent, 'idempotent_replay' => true];
        }

        $customer = Customer::query()
            ->whereKey($data['customer_id'])
            ->where('merchant_id', $merchant->id)
            ->first();

        if ($customer === null) {
            throw (new ModelNotFoundException)->setModel(Customer::class, [$data['customer_id']]);
        }

        if ($this->planCacheService->getActiveSubscriptionWithPlan($merchant->id, $customer->id) === null) {
            throw ValidationException::withMessages([
                'customer_id' => 'The customer does not have an active subscription with a valid plan.',
            ]);
        }

        try {
            $event = UsageEvent::query()->create([
                'merchant_id' => $merchant->id,
                'customer_id' => $customer->id,
                'idempotency_key' => $data['idempotency_key'],
                'units' => $data['units'],
                'recorded_at' => $data['recorded_at'] ?? now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $event = UsageEvent::query()
                ->where('merchant_id', $merchant->id)
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($event === null) {
                abort(409, 'The idempotency key has already been used.');
            }

            $eventSummary = $this->eventSummary($event);
            Cache::put($cacheKey, $eventSummary, now()->addHours(24));

            return ['event' => $eventSummary, 'idempotent_replay' => true];
        }

        $eventSummary = $this->eventSummary($event);
        Cache::put($cacheKey, $eventSummary, now()->addHours(24));

        return ['event' => $eventSummary, 'idempotent_replay' => false];
    }

    /**
     * @return array<string, mixed>
     */
    private function eventSummary(UsageEvent $event): array
    {
        return [
            'id' => $event->id,
            'merchant_id' => $event->merchant_id,
            'customer_id' => $event->customer_id,
            'idempotency_key' => $event->idempotency_key,
            'units' => $event->units,
            'recorded_at' => $event->recorded_at->toISOString(),
            'created_at' => $event->created_at->toISOString(),
        ];
    }
}
