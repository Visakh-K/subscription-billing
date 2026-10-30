<?php

namespace App\Jobs;

use App\Models\DailyUsageAggregate;
use App\Models\UsageEvent;
use Carbon\Carbon;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class AggregateDailyUsageJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $uniqueFor = 86400;

    public function __construct(public ?string $date = null) {}

    public function uniqueId(): string
    {
        return $this->date ?? now()->subDay()->toDateString();
    }

    public function handle(): void
    {
        $date = Carbon::parse($this->date ?? now()->subDay()->toDateString())->toDateString();
        $start = Carbon::parse($date)->startOfDay();
        $end = $start->copy()->addDay();

        DailyUsageAggregate::query()->where('date', $date)->delete();

        UsageEvent::query()
            ->select(['id', 'merchant_id', 'customer_id', 'units'])
            ->where('recorded_at', '>=', $start)
            ->where('recorded_at', '<', $end)
            ->chunkById(15000, function ($events) use ($date): void {
                $aggregates = $events
                    ->groupBy(fn(UsageEvent $event): string => "{$event->merchant_id}:{$event->customer_id}")
                    ->map(fn($customerEvents): array => [
                        'merchant_id' => $customerEvents->first()->merchant_id,
                        'customer_id' => $customerEvents->first()->customer_id,
                        'total_units' => $customerEvents->sum('units'),
                    ])
                    ->map(fn(array $aggregate): array => [
                        'merchant_id' => $aggregate['merchant_id'],
                        'customer_id' => $aggregate['customer_id'],
                        'date' => $date,
                        'total_units' => $aggregate['total_units'],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                    ->all();

                if ($aggregates !== []) {
                    foreach (array_chunk($aggregates, 100) as $aggregateBatch) {
                        $this->upsertChunkTotals($aggregateBatch);
                    }
                }
            });
    }

    /**
     * Add chunk totals atomically; the date-scoped delete makes complete retries exact.
     *
     * @param  array<int, array>  $aggregates
     */
    private function upsertChunkTotals(array $aggregates): void
    {
        // Clean array values for Eloquent upsert
        $data = array_map(function ($aggregate) {
            return [
                'merchant_id' => $aggregate['merchant_id'],
                'customer_id' => $aggregate['customer_id'],
                'date' => $aggregate['date'],
                'total_units' => $aggregate['total_units'],
                'created_at' => $aggregate['created_at'] ?? now(),
                'updated_at' => $aggregate['updated_at'] ?? now(),
            ];
        }, $aggregates);

        DailyUsageAggregate::upsert(
            $data,
            ['customer_id', 'date'],
            ['total_units', 'updated_at']
        );
    }
}
