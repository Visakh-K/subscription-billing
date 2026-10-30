<?php

namespace App\Http\Controllers;

use App\Models\Merchant;
use App\Services\MerchantDashboardService;
use Illuminate\Contracts\View\View;

class MerchantDashboardPageController extends Controller
{
    public function show(int $id, MerchantDashboardService $service): View
    {
        $merchant = Merchant::query()->findOrFail($id);

        return view('merchants.dashboard', [
            'metrics' => $service->getDashboardMetrics($merchant),
        ]);
    }
}
