<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('usage_api', function (Request $request): Limit {
            $apiKey = $request->header('X-API-Key');
            $key = is_string($apiKey) && trim($apiKey) !== ''
                ? 'api-key:'.hash('sha256', $apiKey)
                : 'ip:'.$request->ip();

            return Limit::perMinute(120)->by($key);
        });
    }
}
