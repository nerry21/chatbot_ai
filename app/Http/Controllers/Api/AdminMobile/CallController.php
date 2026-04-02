<?php

namespace App\Http\Controllers\Api\AdminMobile;

use App\Http\Controllers\Api\AdminMobile\Concerns\RespondsWithAdminMobileJson;
use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\User;
use App\Models\WhatsAppCallSession;
use App\Services\WhatsApp\WhatsAppCallService;
use App\Services\WhatsApp\WhatsAppCallReadinessService;
use App\Services\WhatsApp\WhatsAppCallSessionService;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class CallController extends Controller
{
    use RespondsWithAdminMobileJson;

    private readonly int $actionLockSeconds;

    public function __construct(
        private readonly WhatsAppCallSessionService $callSessionService,
        private readonly WhatsAppCallService $callService,
        private readonly WhatsAppCallReadinessService $callReadinessService,
    ) {
        $this->actionLockSeconds = max(2, (int) config('chatbot.whatsapp.calling.action_lock_seconds', 8));
    }

    public function start(Request $request, Conversation $conversation): JsonResponse
    {
        if (($guard = $this->guardRequest($request, $conversation)) !== null) {
            return $guard;
        }

        $validator = $this->startValidator($request);

        if ($validator->fails()) {
            return $this->errorResponse('Data panggilan tidak valid.', [
                'call_session' => null,
                'call_action' => 'failed',
                'permission_required' => false,
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $conversation->loadMissing('customer');
        $user = $this->adminMobileUser($request);
        $validated = $validator->validated();

        return $this->withConversationActionLock($conversation, 'start', function () use ($conversation, $user, $validated): JsonResponse {
            try {
                $session = $this->resolveBusinessInitiatedSession($conversation, $user, $validated);
                $result = $this->callService->startOrRequestPermission($session, $validated);

                return $this->callActionResponse($result);
            } catch (DomainException $exception) {
                return $this->errorResponse($exception->getMessage(), [
                    'call_session' => $this->callSessionService->buildPayload(
                        $this->callSessionService->getActiveSessionForConversation($conversation)
                        ?? $this->callSessionService->getLatestSessionForConversation($conversation),
                    ),
                    'call_action' => 'failed',
                    'permission_required' => false,
                ], 422);
            }
        });
    }

    public function requestPermission(Request $request, Conversation $conversation): JsonResponse
    {
        if (($guard = $this->guardRequest($request, $conversation)) !== null) {
            return $guard;
        }

        $validator = Validator::make($request->all(), [
            'call_type' => ['nullable', 'string', Rule::in(['audio', 'video'])],
            'permission_request_body' => ['nullable', 'string', 'max:1024'],
            'force_permission_request' => ['nullable', 'boolean'],
        ]);

        if ($validator->fails()) {
            return $this->errorResponse('Data permission request tidak valid.', [
                'call_session' => null,
                'call_action' => 'failed',
                'permission_required' => true,
                'errors' => $validator->errors()->toArray(),
            ], 422);
        }

        $conversation->loadMissing('customer');
        $user = $this->adminMobileUser($request);
        $validated = $validator->validated();

        return $this->withConversationActionLock($conversation, 'request-permission', function () use ($conversation, $user, $validated): JsonResponse {
            try {
                $session = $this->resolveBusinessInitiatedSession($conversation, $user, $validated);
                $result = $this->callService->requestUserCallPermission($session, $validated);

                return $this->callActionResponse($result);
            } catch (DomainException $exception) {
                return $this->errorResponse($exception->getMessage(), [
                    'call_session' => $this->callSessionService->buildPayload(
                        $this->callSessionService->getActiveSessionForConversation($conversation)
                        ?? $this->callSessionService->getLatestSessionForConversation($conversation),
                    ),
                    'call_action' => 'failed',
                    'permission_required' => true,
                ], 422);
            }
        });
    }

    public function accept(Request $request, Conversation $conversation): JsonResponse
    {
        if (($guard = $this->guardRequest($request, $conversation)) !== null) {
            return $guard;
        }

        $session = $this->callSessionService->getActiveSessionForConversation($conversation);

        if ($session === null) {
            return $this->errorResponse('Tidak ada panggilan aktif untuk diterima.', [
                'call_session' => $this->callSessionService->buildPayload(
                    $this->callSessionService->getLatestSessionForConversation($conversation),
                ),
                'call_action' => 'failed',
                'permission_required' => false,
            ], 422);
        }

        return $this->withConversationActionLock($conversation, 'accept', function () use ($conversation, $session): JsonResponse {
            try {
                return $this->callActionResponse($this->callService->acceptIncomingCall($session));
            } catch (DomainException $exception) {
                return $this->errorResponse($exception->getMessage(), [
                    'call_session' => $this->callSessionService->buildPayload(
                        $this->callSessionService->getLatestSessionForConversation($conversation),
                    ),
                    'call_action' => 'failed',
                    'permission_required' => false,
                ], 422);
            }
        });
    }

    public function reject(Request $request, Conversation $conversation): JsonResponse
    {
        if (($guard = $this->guardRequest($request, $conversation)) !== null) {
            return $guard;
        }

        $session = $this->callSessionService->getActiveSessionForConversation($conversation);

        if ($session === null) {
            return $this->errorResponse('Tidak ada panggilan aktif untuk ditolak.', [
                'call_session' => $this->callSessionService->buildPayload(
                    $this->callSessionService->getLatestSessionForConversation($conversation),
                ),
                'call_action' => 'failed',
                'permission_required' => false,
            ], 422);
        }

        return $this->withConversationActionLock($conversation, 'reject', function () use ($conversation, $session): JsonResponse {
            try {
                return $this->callActionResponse($this->callService->rejectIncomingCall($session));
            } catch (DomainException $exception) {
                return $this->errorResponse($exception->getMessage(), [
                    'call_session' => $this->callSessionService->buildPayload(
                        $this->callSessionService->getLatestSessionForConversation($conversation),
                    ),
                    'call_action' => 'failed',
                    'permission_required' => false,
                ], 422);
            }
        });
    }

    public function end(Request $request, Conversation $conversation): JsonResponse
    {
        if (($guard = $this->guardRequest($request, $conversation)) !== null) {
            return $guard;
        }

        $session = $this->callSessionService->getActiveSessionForConversation($conversation);

        if ($session === null) {
            return $this->errorResponse('Tidak ada panggilan aktif untuk diakhiri.', [
                'call_session' => $this->callSessionService->buildPayload(
                    $this->callSessionService->getLatestSessionForConversation($conversation),
                ),
                'call_action' => 'failed',
                'permission_required' => false,
            ], 422);
        }

        return $this->withConversationActionLock($conversation, 'end', function () use ($conversation, $session): JsonResponse {
            try {
                return $this->callActionResponse($this->callService->endCall($session));
            } catch (DomainException $exception) {
                return $this->errorResponse($exception->getMessage(), [
                    'call_session' => $this->callSessionService->buildPayload(
                        $this->callSessionService->getLatestSessionForConversation($conversation),
                    ),
                    'call_action' => 'failed',
                    'permission_required' => false,
                ], 422);
            }
        });
    }

    public function status(Request $request, Conversation $conversation): JsonResponse
    {
        if (($guard = $this->guardRequest($request, $conversation)) !== null) {
            return $guard;
        }

        $session = $this->callSessionService->getActiveSessionForConversation($conversation)
            ?? $this->callSessionService->getLatestSessionForConversation($conversation);

        if ($session === null) {
            return $this->successResponse('Status panggilan berhasil diambil.', [
                'call_session' => null,
                'call_action' => 'idle',
                'permission_required' => false,
            ]);
        }

        return $this->callActionResponse($this->callService->fetchCallStatus($session));
    }

    public function readiness(Request $request): JsonResponse
    {
        if (! ($this->adminMobileUser($request) instanceof User)) {
            return $this->errorResponse('Sesi admin mobile tidak valid.', [], 401);
        }

        $summary = $this->callReadinessService->summary();

        return response()->json([
            'success' => true,
            'message' => 'Readiness calling berhasil diambil.',
            'data' => [
                'readiness' => $summary,
            ],
        ]);
    }

    private function guardRequest(Request $request, Conversation $conversation): ?JsonResponse
    {
        $user = $this->adminMobileUser($request);

        if (! ($user instanceof User)) {
            return $this->errorResponse('Sesi admin mobile tidak valid.', [
                'call_session' => null,
                'call_action' => 'failed',
                'permission_required' => false,
            ], 401);
        }

        if (! $conversation->isWhatsApp()) {
            return $this->errorResponse('Panggilan hanya tersedia untuk conversation channel WhatsApp.', [
                'call_session' => null,
                'call_action' => 'failed',
                'permission_required' => false,
            ], 422);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    private function resolveBusinessInitiatedSession(Conversation $conversation, User $user, array $validated): WhatsAppCallSession
    {
        $activeSession = $this->callSessionService->getActiveSessionForConversation($conversation);

        if ($activeSession instanceof WhatsAppCallSession) {
            if ((string) $activeSession->direction !== 'business_initiated') {
                throw new DomainException('Masih ada panggilan aktif lain untuk percakapan ini.');
            }

            return $activeSession;
        }

        return $this->callSessionService->startSession(
            conversation: $conversation,
            customer: $conversation->customer,
            user: $user,
            callType: (string) ($validated['call_type'] ?? 'audio'),
            direction: 'business_initiated',
        );
    }

    private function adminMobileUser(Request $request): ?User
    {
        $user = $request->attributes->get('admin_mobile_user');

        return $user instanceof User ? $user : null;
    }

    private function startValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'call_type' => ['nullable', 'string', Rule::in(['audio', 'video'])],
            'direction' => ['nullable', 'string', Rule::in(['business_initiated'])],
            'permission_request_body' => ['nullable', 'string', 'max:1024'],
            'biz_opaque_callback_data' => ['nullable', 'string', 'max:255'],
            'session' => ['nullable', 'array'],
            'force_permission_request' => ['nullable', 'boolean'],
            'force_remote_permission_status' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @param  callable(): JsonResponse  $callback
     */
    private function withConversationActionLock(Conversation $conversation, string $action, callable $callback): JsonResponse
    {
        $lock = Cache::lock(sprintf('whatsapp-call:%d:%s', (int) $conversation->id, $action), $this->actionLockSeconds);

        if (! $lock->get()) {
            return $this->errorResponse('Permintaan aksi panggilan yang sama masih diproses.', [
                'call_session' => $this->callSessionService->buildPayload(
                    $this->callSessionService->getActiveSessionForConversation($conversation)
                    ?? $this->callSessionService->getLatestSessionForConversation($conversation),
                ),
                'call_action' => 'duplicate_action',
                'permission_required' => false,
                'meta_error' => [
                    'code' => 'duplicate_action',
                    'message' => 'Action panggilan yang sama masih berjalan. Silakan tunggu sebentar.',
                ],
            ], 409);
        }

        try {
            return $callback();
        } finally {
            rescue(static function () use ($lock): void {
                $lock->release();
            }, report: false);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function callActionResponse(array $result): JsonResponse
    {
        $ok = (bool) ($result['ok'] ?? $result['success'] ?? false);
        $message = trim((string) ($result['message'] ?? ''));
        $message = $message !== '' ? $message : ($ok ? 'Aksi panggilan berhasil diproses.' : 'Aksi panggilan gagal diproses.');
        $status = $ok ? 200 : $this->resolveErrorStatusCode((int) ($result['status_code'] ?? 0));

        return response()->json([
            'success' => $ok,
            'message' => $message,
            'data' => array_filter([
                'call_session' => $result['call_session'] ?? null,
                'call_action' => $result['action'] ?? null,
                'permission_required' => (bool) ($result['permission_required'] ?? false),
                'permission_status' => $result['permission_status'] ?? null,
                'meta_call_id' => $result['meta_call_id'] ?? null,
                'meta_error' => $result['meta_error'] ?? null,
            ], static fn (mixed $value): bool => $value !== null),
        ], $status);
    }

    private function resolveErrorStatusCode(int $statusCode): int
    {
        if ($statusCode >= 400 && $statusCode <= 599) {
            return $statusCode;
        }

        return 422;
    }

    /**
     * @param  array<string, mixed>|null  $data
     */
    private function errorResponse(string $message, ?array $data = null, int $status = 422): JsonResponse
    {
        return response()->json([
            'success' => false,
            'message' => $message,
            'data' => $data ?? [],
        ], $status);
    }
}
