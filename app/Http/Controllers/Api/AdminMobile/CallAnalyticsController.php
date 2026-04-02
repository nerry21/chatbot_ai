<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Services\WhatsApp\WhatsAppCallAnalyticsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CallAnalyticsController extends Controller
{
    use RespondsWithAdminMobileJson;

    public function __construct(
        private readonly WhatsAppCallAnalyticsService $analyticsService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        return $this->successResponse('Ringkasan panggilan berhasil diambil.', $this->analyticsService->getGlobalSummary($filters));
    }

    public function recent(Request $request): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        return $this->successResponse('Daftar panggilan terbaru berhasil diambil.', [
            'recent_calls' => $this->analyticsService->getRecentCalls($filters),
            'capabilities' => $this->analyticsService->getCapabilities(),
        ]);
    }

    public function conversationHistory(Request $request, Conversation $conversation): JsonResponse
    {
        $filters = $this->validatedFilters($request);

        return $this->successResponse('Riwayat panggilan percakapan berhasil diambil.', $this->analyticsService->getConversationCallHistory($conversation, $filters));
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedFilters(Request $request): array
    {
        $validator = Validator::make($request->query(), [
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'final_status' => ['nullable', 'string', Rule::in([
                'completed',
                'missed',
                'rejected',
                'failed',
                'cancelled',
                'permission_pending',
                'in_progress',
            ])],
            'call_type' => ['nullable', 'string', Rule::in(['audio', 'video'])],
            'admin_user_id' => ['nullable', 'integer'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        return $validator->fails() ? [] : $validator->validated();
    }
}
