<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Services\AdminMobile\AdminMobileDashboardSummaryService;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly AdminMobileDashboardSummaryService $dashboardSummaryService,
    ) {}

    public function summary(): JsonResponse
    {
        return $this->successResponse('Ringkasan dashboard admin mobile berhasil diambil.', [
            'dashboard_summary' => $this->dashboardSummaryService->summary(),
        ]);
    }
}
