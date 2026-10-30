<?php

use App\Http\Controllers\Api\MerchantDashboardController;
use App\Http\Controllers\Api\UsageController;
use App\Http\Middleware\AuthenticateMerchantApiKey;
use Illuminate\Support\Facades\Route;

Route::post('/usage', UsageController::class)
    ->middleware(['throttle:usage_api', AuthenticateMerchantApiKey::class]);

Route::get('/merchants/{id}/dashboard', [MerchantDashboardController::class, 'show'])
    ->middleware(AuthenticateMerchantApiKey::class)
    ->whereNumber('id');
