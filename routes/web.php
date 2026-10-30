<?php

use App\Http\Controllers\MerchantDashboardPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/merchants/{id}/dashboard', [MerchantDashboardPageController::class, 'show'])
    ->whereNumber('id')
    ->name('merchants.dashboard');
