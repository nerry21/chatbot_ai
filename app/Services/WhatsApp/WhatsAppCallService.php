<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppCallSession;
use App\Services\Support\PhoneNumberService;
use App\Support\WaLog;
use DomainException;
use Illuminate\Support\Arr;

class WhatsAppCallService
{
    private const META_HISTORY_LIMIT = 10;
    private const CONFIG_ERROR_MESSAGE = 'Konfigurasi panggilan belum lengkap. Aktifkan WhatsApp Calling API di Meta Business Manager (Phone Numbers → Calling) untuk nomor ini, lalu coba lagi.';

    private readonly bool $permissionRequestEnabled;
    private readonly int $defaultPermissionTtlMinutes;
    private readonly int $permissionCooldownSeconds;
    private readonly int $startCooldownSeconds;
    private readonly int $rateLimitBackoffSeconds;
    private readonly int $rateLimitCooldownSeconds;

    public function __construct(
        private readonly WhatsAppCallSessionService $callSessionService,
        private readonly MetaWhatsAppCallingApiService $metaCallingApiService,
        private readonly PhoneNumberService $phoneService,
        private readonly WhatsAppCallAuditService $auditService,
    ) {
        $this->permissionRequestEnabled = (bool) config('chatbot.whatsapp.calling.permission_request_enabled', true);
        $this->defaultPermissionTtlMinutes = max(1, (int) config('chatbot.whatsapp.calling.default_permission_ttl_minutes', 1440));
        $this->permissionCooldownSeconds = max(0, (int) config('chatbot.whatsapp.calling.permission_cooldown_seconds', 120));
        $this->startCooldownSeconds = max(0, (int) config('chatbot.whatsapp.calling.start_cooldown_seconds', 15));
        $this->rateLimitBackoffSeconds = max(0, (int) config('chatbot.whatsapp.calling.rate_limit_backoff_seconds', 60));
        $this->rateLimitCooldownSeconds = max(30, (int) config('chatbot.whatsapp.calling.rate_limit_cooldown_seconds', 180));
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function startOrRequestPermission(WhatsAppCallSession $session, array $options = []): array
    {
        $this->ensureBusinessInitiatedSession($session);
        $this->auditService->info('outbound_call_start_attempt', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
        ]));

        // Pre-flight: bail out early with a clear, actionable message instead
        // of round-tripping to Meta with empty credentials.
        $configCheck = $this->preflightConfigurationCheck($session);
        if ($configCheck !== null) {
            return $configCheck;
        }

        if ($session->isConnected()) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'connected',
                message: 'Panggilan WhatsApp sudah terhubung.',
                permissionRequired: false,
                permissionStatus: (string) $session->permission_status,
                metaCallId: $session->wa_call_id,
            );
        }

        if (in_array((string) $session->status, [
            WhatsAppCallSession::STATUS_RINGING,
            WhatsAppCallSession::STATUS_CONNECTING,
        ], true)) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'call_started',
                message: 'Panggilan WhatsApp sedang berjalan.',
                permissionRequired: false,
                permissionStatus: (string) $session->permission_status,
                metaCallId: $session->wa_call_id,
            );
        }

        if ((string) $session->permission_status === WhatsAppCallSession::PERMISSION_DENIED) {
            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'permission_denied',
                message: 'Izin panggilan ditolak oleh pengguna.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_DENIED,
                statusCode: 409,
                metaCallId: $session->wa_call_id,
            );
        }

        if ((string) $session->permission_status === WhatsAppCallSession::PERMISSION_RATE_LIMITED && $session->isPermissionCoolingDown()) {
            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'permission_rate_limited',
                message: 'Permintaan izin terlalu sering. Coba lagi nanti.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_RATE_LIMITED,
                statusCode: 429,
                metaCallId: $session->wa_call_id,
            );
        }

        $permissionResult = $this->ensureUserCallPermission($session, array_merge($options, [
            'force_remote_permission_status' => (bool) ($options['force_remote_permission_status'] ?? true),
        ]));

        /** @var WhatsAppCallSession|null $sessionAfterPermissionCheck */
        $sessionAfterPermissionCheck = $permissionResult['session'] ?? null;
        $session = $sessionAfterPermissionCheck instanceof WhatsAppCallSession ? $sessionAfterPermissionCheck : $session->fresh() ?? $session;

        if (! ($permissionResult['ok'] ?? false)) {
            return $permissionResult;
        }

        $resolvedPermissionStatus = (string) ($permissionResult['permission_status'] ?? $session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN);

        if ($resolvedPermissionStatus === WhatsAppCallSession::PERMISSION_GRANTED) {
            if (($permissionResult['can_start_call'] ?? null) === false) {
                return $this->buildServiceResult(
                    session: $session,
                    ok: false,
                    action: 'call_rate_limited',
                    message: 'Panggilan belum bisa dimulai karena batas start call di Meta sedang aktif.',
                    permissionRequired: false,
                    permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
                    metaResult: is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : null,
                    metaError: [
                        'code' => 'meta_call_rate_limited',
                        'message' => 'Meta belum mengizinkan start call baru untuk user ini saat ini.',
                    ],
                    statusCode: 429,
                    metaCallId: $session->wa_call_id,
                );
            }

            return $this->startBusinessInitiatedCall($session, array_merge($options, [
                'permission_result' => $permissionResult,
            ]));
        }

        if ($resolvedPermissionStatus === WhatsAppCallSession::PERMISSION_DENIED) {
            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'permission_denied',
                message: 'Izin panggilan ditolak oleh pengguna.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_DENIED,
                metaResult: is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : null,
                metaCallId: $session->wa_call_id,
                statusCode: 409,
            );
        }

        if ($resolvedPermissionStatus === WhatsAppCallSession::PERMISSION_RATE_LIMITED || $session->isPermissionCoolingDown()) {
            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'permission_rate_limited',
                message: 'Layanan panggilan sedang dibatasi sementara. Coba lagi nanti.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_RATE_LIMITED,
                metaResult: is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : null,
                metaError: $this->extractMetaError(is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : []),
                metaCallId: $session->wa_call_id,
                statusCode: 429,
            );
        }

        if ($resolvedPermissionStatus === WhatsAppCallSession::PERMISSION_EXPIRED) {
            if (! $this->shouldReRequestPermission($session, $options)) {
                return $this->buildServiceResult(
                    session: $session,
                    ok: false,
                    action: 'permission_expired',
                    message: 'Izin panggilan pengguna sudah kedaluwarsa. Tunggu sejenak sebelum meminta izin lagi.',
                    permissionRequired: true,
                    permissionStatus: WhatsAppCallSession::PERMISSION_EXPIRED,
                    metaResult: is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : null,
                    metaCallId: $session->wa_call_id,
                    statusCode: 409,
                );
            }
        }

        if ($session->hasPendingPermissionRequest() && ! $this->shouldReRequestPermission($session, $options)) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'permission_still_pending',
                message: 'Permintaan izin panggilan masih menunggu respons pengguna.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_REQUESTED,
                metaResult: is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : null,
                metaCallId: $session->wa_call_id,
            );
        }

        if (($permissionResult['can_request_permission'] ?? null) === false && ! $session->hasPendingPermissionRequest()) {
            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'permission_request_failed',
                message: 'Izin panggilan belum tersedia dan Meta belum mengizinkan permintaan izin baru.',
                permissionRequired: true,
                permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_REQUIRED),
                metaResult: is_array($permissionResult['meta_result'] ?? null) ? $permissionResult['meta_result'] : null,
                metaError: [
                    'code' => 'permission_request_rate_limited',
                    'message' => 'Meta membatasi permintaan izin panggilan untuk user ini saat ini.',
                ],
                statusCode: 429,
                metaCallId: $session->wa_call_id,
            );
        }

        return $this->requestUserCallPermission($session, $options);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function ensureUserCallPermission(WhatsAppCallSession $session, array $options = []): array
    {
        $this->ensureBusinessInitiatedSession($session);

        // Pre-flight: bail out early with a clear, actionable message instead
        // of round-tripping to Meta with empty credentials.
        $configCheck = $this->preflightConfigurationCheck($session);
        if ($configCheck !== null) {
            return $configCheck;
        }

        $customerTarget = $this->resolveCustomerTarget($session);

        if ($session->hasGrantedPermission() && ! (bool) ($options['force_remote_permission_status'] ?? false)) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'permission_granted',
                message: 'Izin panggilan pengguna sudah tersedia.',
                permissionRequired: false,
                permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
                metaCallId: $session->wa_call_id,
            );
        }

        $metaPayload = [
            'conversation_id' => (int) $session->conversation_id,
            'call_session_id' => (int) $session->id,
            'phone_number_id' => (string) config('chatbot.whatsapp.calling.phone_number_id', ''),
            'customer_phone' => $customerTarget['phone_e164'],
            'customer_wa_id' => $customerTarget['wa_id_digits'],
            'payload' => [
                'user_wa_id' => $customerTarget['wa_id_digits'],
            ],
        ];

        $metaResult = $this->metaCallingApiService->getCallPermissionStatus($metaPayload);
        $metaUpdate = $this->buildMetaPayloadUpdate(
            session: $session,
            type: 'permission_check',
            requestPayload: $metaPayload,
            metaResult: $metaResult,
            context: [
                'customer_phone' => $customerTarget['phone_e164'],
            ],
        );

        $permissionStatus = $this->normalizePermissionStatus(
            $metaResult['permission_status'] ?? $session->permission_status,
            default: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
        );

        if (! ($metaResult['ok'] ?? false)) {
            $updatedSession = $this->applyPermissionStatusToSession($session, $permissionStatus, [
                'meta_payload' => $metaUpdate,
                'rate_limited_until' => $this->resolveRateLimitedUntilValue($metaResult),
            ]);

            $metaError = $this->extractMetaError($metaResult);
            $configError = $this->isConfigurationError($metaError);

            if ($configError) {
                return $this->buildServiceResult(
                    session: $updatedSession,
                    ok: false,
                    action: 'call_blocked_configuration_error',
                    message: self::CONFIG_ERROR_MESSAGE,
                    permissionRequired: true,
                    permissionStatus: WhatsAppCallSession::PERMISSION_FAILED,
                    metaResult: $metaResult,
                    metaError: $metaError,
                    statusCode: 422,
                    metaCallId: $updatedSession->wa_call_id,
                );
            }

            if (in_array($permissionStatus, [
                WhatsAppCallSession::PERMISSION_REQUESTED,
                WhatsAppCallSession::PERMISSION_DENIED,
                WhatsAppCallSession::PERMISSION_EXPIRED,
                WhatsAppCallSession::PERMISSION_RATE_LIMITED,
            ], true)) {
                return $this->buildServiceResult(
                    session: $updatedSession,
                    ok: true,
                    action: $this->permissionActionForStatus($permissionStatus),
                    message: $this->permissionMessageForStatus($permissionStatus),
                    permissionRequired: $permissionStatus !== WhatsAppCallSession::PERMISSION_GRANTED,
                    permissionStatus: $permissionStatus,
                    metaResult: $metaResult,
                    metaError: $metaError,
                    statusCode: (int) ($metaResult['status_code'] ?? 200),
                    metaCallId: $updatedSession->wa_call_id,
                    extra: [
                        'can_start_call' => $metaResult['can_start_call'] ?? null,
                        'can_request_permission' => $metaResult['can_request_permission'] ?? null,
                    ],
                );
            }

            return $this->buildServiceResult(
                session: $updatedSession,
                ok: false,
                action: 'permission_status_failed',
                message: 'Gagal mengecek status izin panggilan WhatsApp.',
                permissionRequired: ! $updatedSession->hasGrantedPermission(),
                permissionStatus: $permissionStatus,
                metaResult: $metaResult,
                metaError: $metaError,
                statusCode: (int) ($metaResult['status_code'] ?? 0),
                metaCallId: $updatedSession->wa_call_id,
            );
        }

        $updatedSession = $this->applyPermissionStatusToSession($session, $permissionStatus, [
            'meta_payload' => $metaUpdate,
            'rate_limited_until' => $this->resolveRateLimitedUntilValue($metaResult),
        ]);

        return $this->buildServiceResult(
            session: $updatedSession,
            ok: true,
            action: $this->permissionActionForStatus($permissionStatus),
            message: $this->permissionMessageForStatus($permissionStatus),
            permissionRequired: $permissionStatus !== WhatsAppCallSession::PERMISSION_GRANTED,
            permissionStatus: $permissionStatus,
            metaResult: $metaResult,
            statusCode: (int) ($metaResult['status_code'] ?? 200),
            metaCallId: $updatedSession->wa_call_id,
            extra: [
                'can_start_call' => $metaResult['can_start_call'] ?? null,
                'can_request_permission' => $metaResult['can_request_permission'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function requestUserCallPermission(WhatsAppCallSession $session, array $options = []): array
    {
        $this->ensureBusinessInitiatedSession($session);
        $this->auditService->info('permission_request_attempt', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
        ]));

        if (! $this->permissionRequestEnabled) {
            $this->auditService->error('call_config_error', $this->auditContext($session, [
                'status_before' => $session->status,
                'permission_status' => $session->permission_status,
                'meta_error_code' => 'permission_request_disabled',
            ]));

            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'call_blocked_configuration_error',
                message: 'Fitur permission request panggilan WhatsApp sedang dinonaktifkan.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_FAILED,
                metaError: [
                    'code' => 'permission_request_disabled',
                    'message' => 'WHATSAPP_CALL_PERMISSION_REQUEST_ENABLED=false.',
                ],
                statusCode: 422,
                metaCallId: $session->wa_call_id,
            );
        }

        if ($session->hasGrantedPermission()) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'permission_granted',
                message: 'Izin panggilan pengguna sudah tersedia.',
                permissionRequired: false,
                permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
                metaCallId: $session->wa_call_id,
            );
        }

        if ($session->hasPendingPermissionRequest() && ! $this->shouldReRequestPermission($session, $options)) {
            $this->auditService->info('permission_request_still_pending', $this->auditContext($session, [
                'status_before' => $session->status,
                'permission_status' => $session->permission_status,
            ]));

            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'permission_still_pending',
                message: 'Permintaan izin panggilan masih menunggu respons pengguna.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_REQUESTED,
                metaCallId: $session->wa_call_id,
            );
        }

        if ($session->isPermissionCoolingDown() && ! (bool) ($options['force_permission_request'] ?? false)) {
            $this->auditService->warning('permission_request_rate_limited', $this->auditContext($session, [
                'status_before' => $session->status,
                'permission_status' => $session->permission_status,
                'rate_limited_until' => $session->rateLimitedUntil()?->toIso8601String(),
            ]));

            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'permission_rate_limited',
                message: 'Permintaan izin terlalu sering, coba lagi nanti.',
                permissionRequired: true,
                permissionStatus: WhatsAppCallSession::PERMISSION_RATE_LIMITED,
                statusCode: 429,
                metaCallId: $session->wa_call_id,
            );
        }

        $requestPayload = $this->buildPermissionRequestPayload($session, $options);
        $metaResult = $this->metaCallingApiService->requestUserCallPermission($requestPayload);
        $metaUpdate = $this->buildMetaPayloadUpdate(
            session: $session,
            type: 'permission_request',
            requestPayload: $requestPayload,
            metaResult: $metaResult,
            context: [
                'customer_phone' => (string) ($requestPayload['customer_phone'] ?? ''),
            ],
        );

        $permissionStatus = $this->normalizePermissionStatus(
            $metaResult['permission_status'] ?? WhatsAppCallSession::PERMISSION_REQUESTED,
            default: WhatsAppCallSession::PERMISSION_REQUESTED,
        );

        if (! ($metaResult['ok'] ?? false)) {
            $permissionStatus = $this->inferPermissionStatusFromError($metaResult, $permissionStatus);
            $updatedSession = $this->applyPermissionStatusToSession($session, $permissionStatus, [
                'meta_payload' => $metaUpdate,
                'rate_limited_until' => $this->resolveRateLimitedUntilValue($metaResult),
            ]);
            $metaError = $this->extractMetaError($metaResult);

            if ($permissionStatus === WhatsAppCallSession::PERMISSION_REQUESTED) {
                $this->auditService->info('permission_request_still_pending', $this->auditContext($updatedSession, [
                    'status_after' => $updatedSession->status,
                    'permission_status' => $permissionStatus,
                    'meta_error_code' => $metaError['code'] ?? null,
                ]));

                return $this->buildServiceResult(
                    session: $updatedSession,
                    ok: true,
                    action: 'permission_still_pending',
                    message: 'Permintaan izin panggilan masih menunggu respons pengguna.',
                    permissionRequired: true,
                    permissionStatus: WhatsAppCallSession::PERMISSION_REQUESTED,
                    metaResult: $metaResult,
                    metaError: $metaError,
                    statusCode: 200,
                    metaCallId: $updatedSession->wa_call_id,
                );
            }

            $this->auditService->warning(match ($permissionStatus) {
                WhatsAppCallSession::PERMISSION_DENIED => 'permission_request_denied',
                WhatsAppCallSession::PERMISSION_RATE_LIMITED => 'permission_request_rate_limited',
                default => 'permission_request_failed',
            }, $this->auditContext($updatedSession, [
                'status_after' => $updatedSession->status,
                'permission_status' => $permissionStatus,
                'meta_error_code' => $metaError['code'] ?? null,
                'meta_error_message' => $metaError['message'] ?? null,
                'http_status' => $metaResult['status_code'] ?? null,
            ]));

            return $this->buildServiceResult(
                session: $updatedSession,
                ok: false,
                action: $this->isConfigurationError($metaError)
                    ? 'call_blocked_configuration_error'
                    : $this->permissionActionForStatus($permissionStatus),
                message: $this->isConfigurationError($metaError)
                    ? self::CONFIG_ERROR_MESSAGE
                    : $this->permissionMessageForStatus($permissionStatus, forRequest: true),
                permissionRequired: $permissionStatus !== WhatsAppCallSession::PERMISSION_GRANTED,
                permissionStatus: $permissionStatus,
                metaResult: $metaResult,
                metaError: $metaError,
                statusCode: $this->resolvePermissionErrorStatusCode($permissionStatus, (int) ($metaResult['status_code'] ?? 0)),
                metaCallId: $updatedSession->wa_call_id,
            );
        }

        $updatedSession = $this->applyPermissionStatusToSession($session, $permissionStatus, [
            'meta_payload' => $metaUpdate,
            'rate_limited_until' => $this->resolveRateLimitedUntilValue($metaResult),
        ]);

        $this->auditService->info(
            $permissionStatus === WhatsAppCallSession::PERMISSION_GRANTED
                ? 'permission_request_sent'
                : 'permission_request_sent',
            $this->auditContext($updatedSession, [
                'status_after' => $updatedSession->status,
                'permission_status' => $permissionStatus,
            ]),
        );

        return $this->buildServiceResult(
            session: $updatedSession,
            ok: true,
            action: $permissionStatus === WhatsAppCallSession::PERMISSION_GRANTED ? 'permission_granted' : 'permission_requested',
            message: $this->permissionMessageForStatus(
                $permissionStatus,
                forRequest: true,
            ),
            permissionRequired: $permissionStatus !== WhatsAppCallSession::PERMISSION_GRANTED,
            permissionStatus: $permissionStatus,
            metaResult: $metaResult,
            statusCode: (int) ($metaResult['status_code'] ?? 200),
            metaCallId: $updatedSession->wa_call_id,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function startBusinessInitiatedCall(WhatsAppCallSession $session, array $options = []): array
    {
        $this->ensureBusinessInitiatedSession($session);
        $this->auditService->info('outbound_call_start_attempt', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
        ]));

        if ($session->isConnected()) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'connected',
                message: 'Panggilan WhatsApp sudah terhubung.',
                permissionRequired: false,
                permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_GRANTED),
                metaCallId: $session->wa_call_id,
            );
        }

        if (in_array((string) $session->status, [
            WhatsAppCallSession::STATUS_RINGING,
            WhatsAppCallSession::STATUS_CONNECTING,
        ], true)) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'call_started',
                message: 'Panggilan WhatsApp sedang berjalan.',
                permissionRequired: false,
                permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_GRANTED),
                metaCallId: $session->wa_call_id,
            );
        }

        if (! $session->hasGrantedPermission()) {
            $permissionStatus = $this->normalizePermissionStatus(
                $session->permission_status,
                default: WhatsAppCallSession::PERMISSION_REQUIRED,
            );

            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: $this->permissionActionForStatus($permissionStatus),
                message: $this->permissionMessageForStatus($permissionStatus),
                permissionRequired: true,
                permissionStatus: $permissionStatus,
                statusCode: 422,
                metaCallId: $session->wa_call_id,
            );
        }

        if ($this->hasRecentOutboundCallAttempt($session)) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'call_already_processing',
                message: 'Permintaan start panggilan masih diproses.',
                permissionRequired: false,
                permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
                metaCallId: $session->wa_call_id,
            );
        }

        $requestPayload = $this->buildOutboundCallPayload($session, $options);

        if (! is_array(data_get($requestPayload, 'payload.session')) || data_get($requestPayload, 'payload.session') === []) {
            return $this->buildServiceResult(
                session: $session,
                ok: false,
                action: 'call_start_failed',
                message: 'Sinyal call session belum siap. Kirim payload session/SDP dari client sebelum memulai outbound call.',
                permissionRequired: false,
                permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
                metaError: [
                    'code' => 'signaling_session_required',
                    'message' => 'Payload outbound call ke Meta memerlukan field session/SDP.',
                ],
                statusCode: 422,
                metaCallId: $session->wa_call_id,
            );
        }

        $metaResult = $this->metaCallingApiService->startBusinessInitiatedCall($requestPayload);
        $metaUpdate = $this->buildMetaPayloadUpdate(
            session: $session,
            type: 'outbound_call',
            requestPayload: $requestPayload,
            metaResult: $metaResult,
            context: [
                'customer_phone' => (string) ($requestPayload['customer_phone'] ?? ''),
            ],
        );

        if (! ($metaResult['ok'] ?? false)) {
            $updatedSession = $this->callSessionService->markPermissionGranted($session, [
                'meta_payload' => $metaUpdate,
            ]);

            $metaError = $this->extractMetaError($metaResult);
            $action = $this->isConfigurationError($metaError)
                ? 'call_blocked_configuration_error'
                : (($metaResult['is_rate_limited'] ?? false) ? 'call_rate_limited' : 'call_start_failed');
            $message = match ($action) {
                'call_blocked_configuration_error' => self::CONFIG_ERROR_MESSAGE,
                'call_rate_limited' => 'Layanan panggilan sedang dibatasi sementara.',
                default => 'Gagal memulai panggilan WhatsApp.',
            };

            $this->auditService->warning($action === 'call_rate_limited' ? 'outbound_call_failed' : 'outbound_call_failed', $this->auditContext($updatedSession, [
                'status_after' => $updatedSession->status,
                'permission_status' => $updatedSession->permission_status,
                'meta_error_code' => $metaError['code'] ?? null,
                'meta_error_message' => $metaError['message'] ?? null,
                'http_status' => $metaResult['status_code'] ?? null,
            ]));

            return $this->buildServiceResult(
                session: $updatedSession,
                ok: false,
                action: $action,
                message: $message,
                permissionRequired: false,
                permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
                metaResult: $metaResult,
                metaError: $metaError,
                statusCode: ($metaResult['is_rate_limited'] ?? false)
                    ? 429
                    : (int) ($metaResult['status_code'] ?? 0),
                metaCallId: $updatedSession->wa_call_id,
            );
        }

        $updatedSession = $this->callSessionService->markRinging($session, [
            'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            'wa_call_id' => $this->resolveMetaCallId($metaResult) ?? $session->wa_call_id,
            'meta_payload' => $metaUpdate,
        ]);

        $updatedSession = $this->syncMetaCallIdentifiers($updatedSession, $metaResult);
        $this->auditService->info('outbound_call_started', $this->auditContext($updatedSession, [
            'status_after' => $updatedSession->status,
            'permission_status' => $updatedSession->permission_status,
            'wa_call_id' => $updatedSession->wa_call_id,
            'http_status' => $metaResult['status_code'] ?? null,
        ]));

        return $this->buildServiceResult(
            session: $updatedSession,
            ok: true,
            action: 'call_started',
            message: 'Panggilan WhatsApp berhasil dimulai.',
            permissionRequired: false,
            permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
            metaResult: $metaResult,
            statusCode: (int) ($metaResult['status_code'] ?? 200),
            metaCallId: $updatedSession->wa_call_id,
        );
    }

    /**
     * @param  array<string, mixed>  $metaResponse
     */
    public function syncMetaCallIdentifiers(WhatsAppCallSession $session, array $metaResponse): WhatsAppCallSession
    {
        $metaCallId = $this->resolveMetaCallId($metaResponse);

        if ($metaCallId === null || $metaCallId === (string) ($session->wa_call_id ?? '')) {
            return $session->fresh() ?? $session;
        }

        return $this->callSessionService->syncSessionData($session, [
            'wa_call_id' => $metaCallId,
        ]);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildPermissionRequestPayload(WhatsAppCallSession $session, array $options = []): array
    {
        $customerTarget = $this->resolveCustomerTarget($session);
        $bodyText = trim((string) ($options['permission_request_body'] ?? ''));

        // TODO: Add approved template fallback for permission requests outside the active WhatsApp messaging window.
        return [
            'conversation_id' => (int) $session->conversation_id,
            'call_session_id' => (int) $session->id,
            'phone_number_id' => (string) config('chatbot.whatsapp.calling.phone_number_id', ''),
            'customer_phone' => $customerTarget['phone_e164'],
            'customer_wa_id' => $customerTarget['wa_id_digits'],
            'payload' => [
                'messaging_product' => 'whatsapp',
                'to' => $customerTarget['wa_id_digits'],
                'type' => 'interactive',
                'interactive' => array_filter([
                    'type' => 'call_permission_request',
                    'body' => $bodyText !== '' ? ['text' => $bodyText] : null,
                ], static fn (mixed $value): bool => $value !== null),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function buildOutboundCallPayload(WhatsAppCallSession $session, array $options = []): array
    {
        $customerTarget = $this->resolveCustomerTarget($session);
        // TODO: The caller SDP/session must be supplied by the client signaling layer once Flutter/WebRTC is connected.
        $sessionPayload = is_array($options['session'] ?? null)
            ? $options['session']
            : (is_array(data_get($session->meta_payload, 'signaling.session')) ? data_get($session->meta_payload, 'signaling.session') : []);

        $bizOpaqueCallbackData = trim((string) ($options['biz_opaque_callback_data'] ?? $this->defaultBizOpaqueCallbackData($session)));

        return [
            'conversation_id' => (int) $session->conversation_id,
            'call_session_id' => (int) $session->id,
            'phone_number_id' => (string) config('chatbot.whatsapp.calling.phone_number_id', ''),
            'customer_phone' => $customerTarget['phone_e164'],
            'customer_wa_id' => $customerTarget['wa_id_digits'],
            'payload' => array_filter([
                'messaging_product' => 'whatsapp',
                'to' => $customerTarget['wa_id_digits'],
                'action' => 'connect',
                'session' => $sessionPayload !== [] ? $sessionPayload : null,
                'biz_opaque_callback_data' => $bizOpaqueCallbackData !== '' ? $bizOpaqueCallbackData : null,
            ], static fn (mixed $value): bool => $value !== null),
        ];
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function acceptIncomingCall(WhatsAppCallSession $session, array $options = []): array
    {
        $this->auditService->info('call_accept_requested', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
        ]));

        if ($session->isFinished()) {
            throw new DomainException('Sesi panggilan sudah selesai.');
        }

        if ($session->isConnected()) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'accept_incoming_call',
                message: 'Panggilan sudah terhubung.',
                permissionRequired: false,
                permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_GRANTED),
                metaCallId: $session->wa_call_id,
            );
        }

        $updatedSession = $this->callSessionService->markConnected($session, [
            'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            'meta_payload' => $this->buildMetaPayloadUpdate(
                session: $session,
                type: 'accept_call',
                requestPayload: [
                    'conversation_id' => (int) $session->conversation_id,
                    'call_session_id' => (int) $session->id,
                ],
                metaResult: [
                    'ok' => true,
                    'status_code' => 200,
                    'action' => 'accept_incoming_call',
                    'permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
                    'raw' => [],
                ],
            ),
        ]);

        return $this->buildServiceResult(
            session: $updatedSession,
            ok: true,
            action: 'accept_incoming_call',
            message: 'Panggilan berhasil diterima.',
            permissionRequired: false,
            permissionStatus: WhatsAppCallSession::PERMISSION_GRANTED,
            metaCallId: $updatedSession->wa_call_id,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function rejectIncomingCall(WhatsAppCallSession $session, array $options = []): array
    {
        $this->auditService->info('call_reject_requested', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
        ]));

        if ($session->isFinished()) {
            throw new DomainException('Sesi panggilan sudah selesai.');
        }

        if ($session->isConnected()) {
            throw new DomainException('Panggilan sudah terhubung. Gunakan endpoint end untuk mengakhiri panggilan.');
        }

        $updatedSession = $this->callSessionService->markRejected(
            $session,
            (string) ($options['reason'] ?? 'rejected_by_admin'),
            [
                'permission_status' => WhatsAppCallSession::PERMISSION_DENIED,
                'meta_payload' => $this->buildMetaPayloadUpdate(
                    session: $session,
                    type: 'reject_call',
                    requestPayload: [
                        'conversation_id' => (int) $session->conversation_id,
                        'call_session_id' => (int) $session->id,
                    ],
                    metaResult: [
                        'ok' => true,
                        'status_code' => 200,
                        'action' => 'reject_incoming_call',
                        'permission_status' => WhatsAppCallSession::PERMISSION_DENIED,
                        'raw' => [],
                    ],
                ),
            ],
        );

        return $this->buildServiceResult(
            session: $updatedSession,
            ok: true,
            action: 'reject_incoming_call',
            message: 'Panggilan berhasil ditolak.',
            permissionRequired: true,
            permissionStatus: WhatsAppCallSession::PERMISSION_DENIED,
            metaCallId: $updatedSession->wa_call_id,
        );
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function endCall(WhatsAppCallSession $session, array $options = []): array
    {
        $this->auditService->info('call_end_requested', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
        ]));

        if ($session->isFinished()) {
            return $this->buildServiceResult(
                session: $session,
                ok: true,
                action: 'end_call',
                message: 'Panggilan sudah selesai.',
                permissionRequired: ! $session->hasGrantedPermission(),
                permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
                metaCallId: $session->wa_call_id,
            );
        }

        $updatedSession = $this->callSessionService->endSession(
            $session,
            (string) ($options['reason'] ?? 'ended_by_admin'),
            [
                'meta_payload' => $this->buildMetaPayloadUpdate(
                    session: $session,
                    type: 'end_call',
                    requestPayload: [
                        'conversation_id' => (int) $session->conversation_id,
                        'call_session_id' => (int) $session->id,
                    ],
                    metaResult: [
                        'ok' => true,
                        'status_code' => 200,
                        'action' => 'end_call',
                        'permission_status' => (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
                        'raw' => [],
                    ],
                ),
            ],
        );

        $this->auditService->info('call_end_completed', $this->auditContext($updatedSession, [
            'status_after' => $updatedSession->status,
            'permission_status' => $updatedSession->permission_status,
            'wa_call_id' => $updatedSession->wa_call_id,
        ]));

        return $this->buildServiceResult(
            session: $updatedSession,
            ok: true,
            action: 'end_call',
            message: 'Panggilan berhasil diakhiri.',
            permissionRequired: ! $updatedSession->hasGrantedPermission(),
            permissionStatus: (string) ($updatedSession->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
            metaCallId: $updatedSession->wa_call_id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchCallStatus(WhatsAppCallSession $session): array
    {
        if (
            (string) $session->direction === 'business_initiated'
            && ! $session->isConnected()
            && ! $session->isFinished()
            && in_array((string) $session->status, [
                WhatsAppCallSession::STATUS_INITIATED,
                WhatsAppCallSession::STATUS_PERMISSION_REQUESTED,
            ], true)
        ) {
            $permissionResult = $this->ensureUserCallPermission($session, [
                'force_remote_permission_status' => true,
            ]);

            /** @var WhatsAppCallSession|null $permissionSession */
            $permissionSession = $permissionResult['session'] ?? null;
            $session = $permissionSession instanceof WhatsAppCallSession ? $permissionSession : ($session->fresh() ?? $session);

            if (! ($permissionResult['ok'] ?? false)) {
                return $permissionResult;
            }
        }

        return $this->buildServiceResult(
            session: $session,
            ok: true,
            action: match ((string) $session->status) {
                WhatsAppCallSession::STATUS_CONNECTED => 'connected',
                WhatsAppCallSession::STATUS_RINGING, WhatsAppCallSession::STATUS_CONNECTING => 'ringing',
                WhatsAppCallSession::STATUS_PERMISSION_REQUESTED => 'permission_requested',
                WhatsAppCallSession::STATUS_FAILED => 'failed',
                default => 'status',
            },
            message: 'Status panggilan berhasil diambil.',
            permissionRequired: ! $session->hasGrantedPermission(),
            permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
            metaCallId: $session->wa_call_id,
        );
    }

    private function ensureBusinessInitiatedSession(WhatsAppCallSession $session): void
    {
        if ($session->isFinished()) {
            throw new DomainException('Sesi panggilan sudah selesai.');
        }

        $session->loadMissing(['conversation', 'customer']);

        if (! $session->conversation?->isWhatsApp()) {
            throw new DomainException('Panggilan hanya tersedia untuk conversation channel WhatsApp.');
        }

        if ((string) $session->direction !== 'business_initiated') {
            throw new DomainException('Tahap ini hanya mendukung business initiated WhatsApp call.');
        }
    }

    /**
     * @return array{phone_e164: string, wa_id_digits: string}
     */
    private function resolveCustomerTarget(WhatsAppCallSession $session): array
    {
        $session->loadMissing(['customer', 'conversation.customer']);

        $customerPhone = trim((string) (
            $session->customer?->phone_e164
            ?? $session->conversation?->customer?->phone_e164
            ?? ''
        ));

        if ($customerPhone === '') {
            throw new DomainException('Nomor WhatsApp customer tidak tersedia pada conversation ini.');
        }

        $phoneE164 = $this->phoneService->toE164($customerPhone);

        if ($phoneE164 === '' || ! $this->phoneService->isValidE164($phoneE164)) {
            throw new DomainException('Nomor WhatsApp customer tidak valid.');
        }

        return [
            'phone_e164' => $phoneE164,
            'wa_id_digits' => $this->phoneService->toDigits($phoneE164),
        ];
    }

    /**
     * @param  array<string, mixed>  $metaResult
     */
    private function resolveMetaCallId(array $metaResult): ?string
    {
        $metaCallId = trim((string) ($metaResult['meta_call_id'] ?? ''));

        return $metaCallId !== '' ? $metaCallId : null;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function shouldReRequestPermission(WhatsAppCallSession $session, array $options): bool
    {
        if ((bool) ($options['force_permission_request'] ?? false)) {
            return true;
        }

        if ($session->isPermissionCoolingDown()) {
            return false;
        }

        $requestedAt = $session->permissionRequestedAt();

        if ($requestedAt === null) {
            return true;
        }

        return $requestedAt->lte(now()->subSeconds(max($this->permissionCooldownSeconds, $this->rateLimitBackoffSeconds)));
    }

    /**
     * @param  array<string, mixed>  $metaResult
     */
    private function inferPermissionStatusFromError(array $metaResult, string $fallback = WhatsAppCallSession::PERMISSION_REQUIRED): string
    {
        if (($metaResult['is_rate_limited'] ?? false) === true) {
            return WhatsAppCallSession::PERMISSION_RATE_LIMITED;
        }

        $errorMessage = strtolower(trim((string) data_get($metaResult, 'error.message', '')));

        if (str_contains($errorMessage, 'deny') || str_contains($errorMessage, 'denied')) {
            return WhatsAppCallSession::PERMISSION_DENIED;
        }

        if (str_contains($errorMessage, 'expired') || str_contains($errorMessage, 'no longer valid')) {
            return WhatsAppCallSession::PERMISSION_EXPIRED;
        }

        if (str_contains($errorMessage, 'request already sent') || str_contains($errorMessage, 'pending')) {
            return WhatsAppCallSession::PERMISSION_REQUESTED;
        }

        if ($this->isConfigurationError($this->extractMetaError($metaResult))) {
            return WhatsAppCallSession::PERMISSION_FAILED;
        }

        return $fallback !== '' ? $fallback : WhatsAppCallSession::PERMISSION_FAILED;
    }

    private function normalizePermissionStatus(mixed $value, string $default = WhatsAppCallSession::PERMISSION_REQUIRED): string
    {
        $normalized = trim((string) $value);

        if ($normalized === '') {
            return $default;
        }

        return match ($normalized) {
            WhatsAppCallSession::PERMISSION_UNKNOWN => WhatsAppCallSession::PERMISSION_UNKNOWN,
            WhatsAppCallSession::PERMISSION_REQUIRED => WhatsAppCallSession::PERMISSION_REQUIRED,
            WhatsAppCallSession::PERMISSION_REQUESTED => WhatsAppCallSession::PERMISSION_REQUESTED,
            WhatsAppCallSession::PERMISSION_GRANTED => WhatsAppCallSession::PERMISSION_GRANTED,
            WhatsAppCallSession::PERMISSION_DENIED => WhatsAppCallSession::PERMISSION_DENIED,
            WhatsAppCallSession::PERMISSION_EXPIRED => WhatsAppCallSession::PERMISSION_EXPIRED,
            WhatsAppCallSession::PERMISSION_RATE_LIMITED => WhatsAppCallSession::PERMISSION_RATE_LIMITED,
            WhatsAppCallSession::PERMISSION_FAILED => WhatsAppCallSession::PERMISSION_FAILED,
            default => $default,
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function applyPermissionStatusToSession(WhatsAppCallSession $session, string $permissionStatus, array $extra = []): WhatsAppCallSession
    {
        $normalizedStatus = $this->normalizePermissionStatus(
            $permissionStatus,
            default: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
        );

        if (
            $normalizedStatus === WhatsAppCallSession::PERMISSION_REQUESTED
            && ! array_key_exists('last_permission_requested_at', $extra)
            && $session->permissionRequestedAt() !== null
        ) {
            $extra['last_permission_requested_at'] = $session->permissionRequestedAt();
        }

        return match ($normalizedStatus) {
            WhatsAppCallSession::PERMISSION_GRANTED => $this->callSessionService->markPermissionGranted($session, $extra),
            WhatsAppCallSession::PERMISSION_REQUESTED => $this->callSessionService->markPermissionRequested($session, $extra),
            WhatsAppCallSession::PERMISSION_DENIED => $this->callSessionService->markPermissionDenied($session, $extra),
            WhatsAppCallSession::PERMISSION_EXPIRED => $this->callSessionService->markPermissionExpired($session, $extra),
            WhatsAppCallSession::PERMISSION_RATE_LIMITED => $this->callSessionService->markPermissionRateLimited($session, $extra),
            WhatsAppCallSession::PERMISSION_FAILED => $this->callSessionService->markPermissionFailed($session, $extra),
            default => $this->callSessionService->markPermissionRequired($session, $extra),
        };
    }

    private function permissionActionForStatus(string $permissionStatus): string
    {
        return match ($permissionStatus) {
            WhatsAppCallSession::PERMISSION_GRANTED => 'permission_granted',
            WhatsAppCallSession::PERMISSION_REQUESTED => 'permission_still_pending',
            WhatsAppCallSession::PERMISSION_DENIED => 'permission_denied',
            WhatsAppCallSession::PERMISSION_EXPIRED => 'permission_expired',
            WhatsAppCallSession::PERMISSION_RATE_LIMITED => 'permission_rate_limited',
            WhatsAppCallSession::PERMISSION_FAILED => 'call_blocked_configuration_error',
            default => 'permission_required',
        };
    }

    private function permissionMessageForStatus(string $permissionStatus, bool $forRequest = false): string
    {
        return match ($permissionStatus) {
            WhatsAppCallSession::PERMISSION_GRANTED => 'Izin panggilan pengguna sudah tersedia.',
            WhatsAppCallSession::PERMISSION_REQUESTED => $forRequest
                ? 'Permintaan izin panggilan berhasil dikirim.'
                : 'Permintaan izin panggilan masih menunggu respons pengguna.',
            WhatsAppCallSession::PERMISSION_DENIED => 'Izin panggilan ditolak oleh pengguna.',
            WhatsAppCallSession::PERMISSION_EXPIRED => 'Izin panggilan pengguna sudah kedaluwarsa.',
            WhatsAppCallSession::PERMISSION_RATE_LIMITED => 'Permintaan izin terlalu sering, coba lagi nanti.',
            WhatsAppCallSession::PERMISSION_FAILED => self::CONFIG_ERROR_MESSAGE,
            default => 'Izin panggilan masih diperlukan sebelum memulai panggilan.',
        };
    }

    private function resolvePermissionErrorStatusCode(string $permissionStatus, int $fallbackStatusCode = 422): int
    {
        return match ($permissionStatus) {
            WhatsAppCallSession::PERMISSION_RATE_LIMITED => 429,
            WhatsAppCallSession::PERMISSION_DENIED,
            WhatsAppCallSession::PERMISSION_EXPIRED => 409,
            WhatsAppCallSession::PERMISSION_FAILED => 422,
            default => ($fallbackStatusCode >= 400 && $fallbackStatusCode <= 599) ? $fallbackStatusCode : 422,
        };
    }

    private function resolveRateLimitedUntilValue(array $metaResult): ?string
    {
        $rateLimitedUntil = trim((string) ($metaResult['rate_limited_until'] ?? ''));

        if ($rateLimitedUntil !== '') {
            return $rateLimitedUntil;
        }

        if (($metaResult['is_rate_limited'] ?? false) === true) {
            return now()->addSeconds($this->rateLimitCooldownSeconds)->toIso8601String();
        }

        return null;
    }

    /**
     * Validate that the minimum WhatsApp Calling API configuration is present
     * before sending any request to Meta. Returns null when configuration is
     * usable, or a fully-formed error result when something is missing — in
     * which case callers should return that result immediately.
     *
     * @return array<string, mixed>|null
     */
    private function preflightConfigurationCheck(WhatsAppCallSession $session): ?array
    {
        $callingEnabled = (bool) config('chatbot.whatsapp.calling.enabled', false);
        $accessToken = trim((string) config('chatbot.whatsapp.calling.access_token', ''));
        $phoneNumberId = trim((string) config('chatbot.whatsapp.calling.phone_number_id', ''));

        $missing = [];
        if (! $callingEnabled) {
            $missing[] = 'WHATSAPP_CALLING_ENABLED=false';
        }
        if ($accessToken === '') {
            $missing[] = 'WHATSAPP_CALLING_ACCESS_TOKEN kosong';
        }
        if ($phoneNumberId === '') {
            $missing[] = 'WHATSAPP_CALLING_PHONE_NUMBER_ID kosong';
        }

        if ($missing === []) {
            return null;
        }

        $detail = implode(', ', $missing);

        $this->auditService->error('call_config_error', $this->auditContext($session, [
            'status_before' => $session->status,
            'permission_status' => $session->permission_status,
            'meta_error_code' => 'calling_configuration_incomplete',
            'meta_error_message' => $detail,
        ]));

        return $this->buildServiceResult(
            session: $session,
            ok: false,
            action: 'call_blocked_configuration_error',
            message: self::CONFIG_ERROR_MESSAGE,
            permissionRequired: true,
            permissionStatus: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_FAILED),
            metaError: [
                'code' => 'calling_configuration_incomplete',
                'message' => $detail,
            ],
            statusCode: 422,
            metaCallId: $session->wa_call_id,
        );
    }

    private function isConfigurationError(?array $metaError): bool
    {
        $code = trim((string) ($metaError['code'] ?? ''));
        $message = strtolower(trim((string) ($metaError['message'] ?? '')));

        return $code === 'calling_configuration_incomplete'
            || $code === 'permission_request_disabled'
            || str_contains($message, 'calling api not enabled')
            || str_contains($message, 'calling is not enabled')
            || str_contains($message, 'configure call settings')
            || str_contains($message, 'not enabled for this phone number');
    }

    private function hasRecentOutboundCallAttempt(WhatsAppCallSession $session): bool
    {
        $lastStartedAt = trim((string) data_get($session->meta_payload, 'outbound_call.last_started_at', ''));

        if ($lastStartedAt === '' || $this->startCooldownSeconds <= 0) {
            return false;
        }

        try {
            return now()->lt(\Illuminate\Support\Carbon::parse($lastStartedAt)->addSeconds($this->startCooldownSeconds));
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function auditContext(WhatsAppCallSession $session, array $extra = []): array
    {
        return array_merge([
            'conversation_id' => (int) $session->conversation_id,
            'call_session_id' => (int) $session->id,
            'wa_call_id' => $session->wa_call_id,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @param  array<string, mixed>  $metaResult
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildMetaPayloadUpdate(
        WhatsAppCallSession $session,
        string $type,
        array $requestPayload,
        array $metaResult,
        array $context = [],
    ): array {
        $currentMeta = is_array($session->meta_payload) ? $session->meta_payload : [];
        $timestamp = now()->toIso8601String();
        $customerPhone = trim((string) ($context['customer_phone'] ?? $requestPayload['customer_phone'] ?? ''));
        $permissionStatus = $this->normalizePermissionStatus(
            $metaResult['permission_status'] ?? $session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN,
            default: (string) ($session->permission_status ?? WhatsAppCallSession::PERMISSION_UNKNOWN),
        );
        $permissionExpiresAt = trim((string) ($metaResult['permission_expires_at'] ?? ''));
        $rateLimitedUntil = $this->resolveRateLimitedUntilValue($metaResult);

        if ($permissionExpiresAt === '' && in_array($permissionStatus, [
            WhatsAppCallSession::PERMISSION_GRANTED,
            WhatsAppCallSession::PERMISSION_REQUESTED,
        ], true)) {
            $permissionExpiresAt = now()->addMinutes($this->defaultPermissionTtlMinutes)->toIso8601String();
        }

        $entry = [
            'at' => $timestamp,
            'status_code' => $metaResult['status_code'] ?? null,
            'action' => $metaResult['action'] ?? $type,
            'ok' => (bool) ($metaResult['ok'] ?? false),
            'permission_status' => $permissionStatus,
            'meta_call_id' => $this->resolveMetaCallId($metaResult),
            'error' => $this->extractMetaError($metaResult),
            'request' => $this->summarizeRequestPayloadForStorage($requestPayload),
            'response' => $this->summarizeMetaResultForStorage($metaResult),
        ];

        $currentMeta['calling_api'] = array_merge(
            is_array($currentMeta['calling_api'] ?? null) ? $currentMeta['calling_api'] : [],
            [
                'provider' => 'meta_whatsapp_calling_api',
                'last_action' => $type,
                'last_action_at' => $timestamp,
                'last_response' => $entry['response'],
            ],
        );

        $historyKey = match ($type) {
            'permission_check' => 'permission_checks',
            'permission_request' => 'permission_requests',
            'outbound_call' => 'outbound_calls',
            default => 'call_events',
        };

        $currentMeta[$historyKey] = $this->appendHistoryEntry(
            is_array($currentMeta[$historyKey] ?? null) ? $currentMeta[$historyKey] : [],
            $entry,
        );

        $permissionMeta = is_array($currentMeta['permission'] ?? null) ? $currentMeta['permission'] : [];
        $permissionMeta['status'] = $permissionStatus;
        $permissionMeta['raw_status'] = $metaResult['raw_permission_status'] ?? $permissionMeta['raw_status'] ?? null;
        $permissionMeta['checked_at'] = $type === 'permission_check' ? $timestamp : ($permissionMeta['checked_at'] ?? null);
        $permissionMeta['last_checked_at'] = $type === 'permission_check' ? $timestamp : ($permissionMeta['last_checked_at'] ?? null);
        $permissionMeta['requested_at'] = $type === 'permission_request' ? ($permissionMeta['requested_at'] ?? $timestamp) : ($permissionMeta['requested_at'] ?? null);
        $permissionMeta['last_requested_at'] = $type === 'permission_request' ? $timestamp : ($permissionMeta['last_requested_at'] ?? null);
        $permissionMeta['granted_at'] = $permissionStatus === WhatsAppCallSession::PERMISSION_GRANTED ? ($permissionMeta['granted_at'] ?? $timestamp) : ($permissionMeta['granted_at'] ?? null);
        $permissionMeta['denied_at'] = $permissionStatus === WhatsAppCallSession::PERMISSION_DENIED ? $timestamp : ($permissionMeta['denied_at'] ?? null);
        $permissionMeta['expired_at'] = $permissionStatus === WhatsAppCallSession::PERMISSION_EXPIRED ? $timestamp : ($permissionMeta['expired_at'] ?? null);
        $permissionMeta['failed_at'] = $permissionStatus === WhatsAppCallSession::PERMISSION_FAILED ? $timestamp : ($permissionMeta['failed_at'] ?? null);
        $permissionMeta['last_rate_limited_at'] = $permissionStatus === WhatsAppCallSession::PERMISSION_RATE_LIMITED ? $timestamp : ($permissionMeta['last_rate_limited_at'] ?? null);
        $permissionMeta['expires_at'] = $permissionExpiresAt !== '' ? $permissionExpiresAt : ($permissionMeta['expires_at'] ?? null);
        $permissionMeta['last_known_expires_at'] = $permissionExpiresAt !== '' ? $permissionExpiresAt : ($permissionMeta['last_known_expires_at'] ?? null);
        $permissionMeta['cooldown_until'] = $rateLimitedUntil !== null ? $rateLimitedUntil : ($permissionMeta['cooldown_until'] ?? null);
        $permissionMeta['rate_limited_until'] = $rateLimitedUntil !== null ? $rateLimitedUntil : ($permissionMeta['rate_limited_until'] ?? null);
        $permissionMeta['can_request_permission'] = $metaResult['can_request_permission'] ?? ($permissionMeta['can_request_permission'] ?? null);
        $permissionMeta['can_start_call'] = $metaResult['can_start_call'] ?? ($permissionMeta['can_start_call'] ?? null);
        $permissionMeta['user_wa_id'] = trim((string) ($requestPayload['customer_wa_id'] ?? '')) !== ''
            ? (string) $requestPayload['customer_wa_id']
            : ($permissionMeta['user_wa_id'] ?? null);
        $permissionMeta['last_error_code'] = data_get($metaResult, 'error.code') ?? ($permissionMeta['last_error_code'] ?? null);
        $permissionMeta['last_error_message'] = data_get($metaResult, 'error.message') ?? ($permissionMeta['last_error_message'] ?? null);
        $currentMeta['permission'] = $permissionMeta;

        if ($type === 'outbound_call') {
            $currentMeta['outbound_call'] = array_merge(
                is_array($currentMeta['outbound_call'] ?? null) ? $currentMeta['outbound_call'] : [],
                [
                    'last_attempted_at' => $timestamp,
                    'last_started_at' => ($metaResult['ok'] ?? false) ? $timestamp : data_get($currentMeta, 'outbound_call.last_started_at'),
                    'last_meta_call_id' => $this->resolveMetaCallId($metaResult),
                    'last_status' => $metaResult['ok'] ?? false ? 'initiated' : 'failed',
                ],
            );
        }

        if ($customerPhone !== '') {
            $currentMeta['customer_phone_masked'] = WaLog::maskPhone($customerPhone);
        }

        return $currentMeta;
    }

    /**
     * @param  array<int, mixed>  $history
     * @param  array<string, mixed>  $entry
     * @return array<int, mixed>
     */
    private function appendHistoryEntry(array $history, array $entry): array
    {
        $filtered = array_values(array_filter($history, 'is_array'));
        $filtered[] = $entry;

        return array_slice($filtered, -self::META_HISTORY_LIMIT);
    }

    /**
     * @param  array<string, mixed>  $requestPayload
     * @return array<string, mixed>
     */
    private function summarizeRequestPayloadForStorage(array $requestPayload): array
    {
        $payload = is_array($requestPayload['payload'] ?? null) ? $requestPayload['payload'] : [];
        $summary = Arr::except($payload, ['session']);
        $summary['phone_number_id'] = $requestPayload['phone_number_id'] ?? null;
        $summary['customer_wa_id'] = $requestPayload['customer_wa_id'] ?? null;

        if (is_array($payload['session'] ?? null)) {
            $summary['session_present'] = true;
            $summary['session_keys'] = array_values(array_keys($payload['session']));
        }

        return $summary;
    }

    /**
     * @param  array<string, mixed>  $metaResult
     * @return array<string, mixed>
     */
    private function summarizeMetaResultForStorage(array $metaResult): array
    {
        $summary = Arr::except($metaResult, ['raw']);
        $summary['raw'] = $this->truncateMetaValue($metaResult['raw'] ?? []);

        return $summary;
    }

    private function truncateMetaValue(mixed $value, int $depth = 0): mixed
    {
        if ($depth >= 4) {
            return '[truncated-depth]';
        }

        if (is_array($value)) {
            $limited = [];
            $count = 0;

            foreach ($value as $key => $item) {
                $limited[$key] = $this->truncateMetaValue($item, $depth + 1);
                $count++;

                if ($count >= 20) {
                    $limited['_truncated'] = true;
                    break;
                }
            }

            return $limited;
        }

        if (is_string($value) && mb_strlen($value) > 500) {
            return mb_substr($value, 0, 500).'...[truncated]';
        }

        return $value;
    }

    private function defaultBizOpaqueCallbackData(WhatsAppCallSession $session): string
    {
        return sprintf(
            'conversation:%d|session:%d|type:%s',
            (int) $session->conversation_id,
            (int) $session->id,
            (string) $session->call_type,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metaResult
     * @param  array<string, string>|null  $metaError
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function buildServiceResult(
        WhatsAppCallSession $session,
        bool $ok,
        string $action,
        string $message,
        bool $permissionRequired,
        string $permissionStatus,
        ?array $metaResult = null,
        ?array $metaError = null,
        int $statusCode = 200,
        ?string $metaCallId = null,
        array $extra = [],
    ): array {
        $session = $session->fresh() ?? $session;

        return array_merge([
            'ok' => $ok,
            'success' => $ok,
            'action' => $action,
            'message' => $message,
            'permission_required' => $permissionRequired,
            'permission_status' => $permissionStatus,
            'status_code' => $statusCode,
            'meta_call_id' => $metaCallId,
            'meta_error' => $metaError,
            'meta_result' => $metaResult,
            'call_session' => $this->callSessionService->buildPayload($session),
            'session' => $session,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $metaResult
     * @return array<string, string>|null
     */
    private function extractMetaError(array $metaResult): ?array
    {
        $error = $metaResult['error'] ?? null;

        if (! is_array($error)) {
            return null;
        }

        $code = trim((string) ($error['code'] ?? ''));
        $message = trim((string) ($error['message'] ?? ''));

        if ($code === '' && $message === '') {
            return null;
        }

        return [
            'code' => $code !== '' ? $code : 'meta_unknown_error',
            'message' => $message !== '' ? $message : 'Meta Calling API mengembalikan error yang tidak dikenal.',
        ];
    }
}