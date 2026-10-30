<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class Plan extends Model
{
    protected $guarded = [];

    protected $casts = [
        'base_price' => 'decimal:2',
        'included_units' => 'integer',
        'overage_rate' => 'decimal:4',
    ];

    protected static function booted(): void
    {
        static::saved(function (Plan $plan): void {
            Cache::forget("merchant_{$plan->merchant_id}_plan_{$plan->id}");
        });

        static::deleted(function (Plan $plan): void {
            Cache::forget("merchant_{$plan->merchant_id}_plan_{$plan->id}");
        });
    }

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    public function subscriptionItems(): HasMany
    {
        return $this->hasMany(SubscriptionItem::class);
    }
}
