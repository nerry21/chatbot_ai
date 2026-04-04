<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppCallReadinessService;
use Illuminate\Http\JsonResponse;

class OmnichannelCallReadinessCacheClearController extends Controller
{
    public function __construct(
        private readonly WhatsAppCallReadinessService $readinessService,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $result = $this->readinessService->clearEligibilityCache();

        return response()->json([
            'success' => (bool) ($result['ok'] ?? false),
            'message' => $result['message'] ?? 'Permintaan selesai diproses.',
            'data' => $result,
        ], ($result['ok'] ?? false) ? 200 : 422);
    }
}
