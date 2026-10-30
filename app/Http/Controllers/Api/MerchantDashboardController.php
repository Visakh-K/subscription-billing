<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Services\MerchantDashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MerchantDashboardController extends Controller
{
    public function show(Request $request, int $id, MerchantDashboardService $service): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        abort_unless($merchant instanceof Merchant, JsonResponse::HTTP_UNAUTHORIZED);
        abort_if($merchant->id !== $id, JsonResponse::HTTP_FORBIDDEN);

        return response()->json($service->getDashboardMetrics($merchant));
    }
}
