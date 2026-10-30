<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\RecordUsageRequest;
use App\Models\Merchant;
use App\Services\UsageIngestionService;
use Illuminate\Http\JsonResponse;

class UsageController extends Controller
{
    public function __invoke(RecordUsageRequest $request, UsageIngestionService $service): JsonResponse
    {
        $merchant = $request->attributes->get('merchant');
        abort_unless($merchant instanceof Merchant, 401);

        $result = $service->record($merchant, $request->validated());

        return response()->json(
            [
                'event' => $result['event'],
                'idempotent_replay' => $result['idempotent_replay'],
            ],
            $result['idempotent_replay'] ? JsonResponse::HTTP_OK : JsonResponse::HTTP_CREATED,
        );
    }
}
