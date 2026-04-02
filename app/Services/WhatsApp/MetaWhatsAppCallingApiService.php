<?php

namespace App\Services\WhatsApp;

use App\Models\WhatsAppCallSession;
use App\Support\WaLog;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;

class MetaWhatsAppCallingApiService
{
    private const PERMISSION_GRANTED_STATUSES = [
        'temporary',
        'permanent',
        'granted',
        'allowed',
        'active',
    ];

    private const PERMISSION_REQUESTED_STATUSES = [
        'requested',
        'pending',
        'waiting',
    ];

    private const PERMISSION_DENIED_STATUSES = [
        'denied',
        'rejected',
        'revoked',
        'blocked',
    ];

    private const PERMISSION_EXPIRED_STATUSES = [
        'expired',
        'expired_permission',
        'expired_grant',
    ];

    private readonly bool $enabled;
    private readonly string $baseUrl;
    private readonly string $apiVersion;
    private readonly string $accessToken;
    private readonly string $phoneNumberId;
    private readonly string $wabaId;
    private readonly int $timeoutSeconds;
    private readonly bool $verifySsl;
    private readonly bool $retryEnabled;
    private readonly int $maxRetries;
    private readonly int $retryBackoffMs;
    private readonly int $rateLimitCooldownSeconds;

    public function __construct(
        private readonly WhatsAppCallAuditService $auditService,
    ) {
        $this->enabled = (bool) config('chatbot.whatsapp.calling.enabled', false);
        $this->baseUrl = rtrim((string) config('chatbot.whatsapp.calling.base_url', 'https://graph.facebook.com'), '/');
        $this->apiVersion = trim((string) config('chatbot.whatsapp.calling.api_version', 'v23.0'), '/');
        $this->accessToken = trim((string) config('chatbot.whatsapp.calling.access_token', ''));
        $this->phoneNumberId = trim((string) config('chatbot.whatsapp.calling.phone_number_id', ''));
        $this->wabaId = trim((string) config('chatbot.whatsapp.calling.waba_id', ''));
        $this->timeoutSeconds = max(1, (int) config('chatbot.whatsapp.calling.timeout_seconds', 20));
        $this->verifySsl = (bool) config('chatbot.whatsapp.calling.verify_ssl', true);
        $this->retryEnabled = (bool) config('chatbot.whatsapp.calling.retry_enabled', true);
        $this->maxRetries = max(0, (int) config('chatbot.whatsapp.calling.max_retries', 2));
        $this->retryBackoffMs = max(100, (int) config('chatbot.whatsapp.calling.retry_backoff_ms', 350));
        $this->rateLimitCooldownSeconds = max(30, (int) config('chatbot.whatsapp.calling.rate_limit_cooldown_seconds', 180));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function requestUserCallPermission(array $payload): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($payload);
        $requestBody = $this->resolveRequestBody($payload);
        $logContext = $this->buildLogContext(
            $payload,
            endpoint: $this->buildBaseUrl(sprintf('/%s/messages', $phoneNumberId)),
            action: 'request_permission',
            requestSummary: $this->summarizeRequestPayload($requestBody),
        );

        if (($configurationError = $this->configurationError($phoneNumberId, 'permission_request_failed')) !== null) {
            $this->auditService->error('call_config_error', array_merge($logContext, [
                'result' => 'failed',
                'normalized_result' => $configurationError,
            ]));

            return $configurationError;
        }

        return $this->sendRequest(
            method: 'POST',
            endpointPath: sprintf('/%s/messages', $phoneNumberId),
            body: $requestBody,
            logContext: $logContext,
            normalizationContext: [
                'success_action' => 'permission_requested',
                'failure_action' => 'permission_request_failed',
                'success_permission_status' => WhatsAppCallSession::PERMISSION_REQUESTED,
                'error_permission_status' => WhatsAppCallSession::PERMISSION_FAILED,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function startBusinessInitiatedCall(array $payload): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($payload);
        $requestBody = $this->resolveRequestBody($payload);
        $logContext = $this->buildLogContext(
            $payload,
            endpoint: $this->buildBaseUrl(sprintf('/%s/calls', $phoneNumberId)),
            action: 'start_outbound_call',
            requestSummary: $this->summarizeRequestPayload($requestBody),
        );

        if (($configurationError = $this->configurationError($phoneNumberId, 'call_start_failed')) !== null) {
            $this->auditService->error('call_config_error', array_merge($logContext, [
                'result' => 'failed',
                'normalized_result' => $configurationError,
            ]));

            return $configurationError;
        }

        return $this->sendRequest(
            method: 'POST',
            endpointPath: sprintf('/%s/calls', $phoneNumberId),
            body: $requestBody,
            logContext: $logContext,
            normalizationContext: [
                'success_action' => 'call_started',
                'failure_action' => 'call_start_failed',
                'success_permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
                'error_permission_status' => WhatsAppCallSession::PERMISSION_GRANTED,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function getCallPermissionStatus(array $payload): array
    {
        $phoneNumberId = $this->resolvePhoneNumberId($payload);
        $requestBody = $this->resolveRequestBody($payload);
        $userWaId = trim((string) ($requestBody['user_wa_id'] ?? $payload['user_wa_id'] ?? ''));
        $logContext = $this->buildLogContext(
            array_merge($payload, ['customer_wa_id' => $userWaId]),
            endpoint: $this->buildBaseUrl(sprintf('/%s/call_permissions', $phoneNumberId)),
            action: 'check_permission',
            requestSummary: [
                'user_wa_id' => $userWaId,
            ],
        );

        if (($configurationError = $this->configurationError($phoneNumberId, 'permission_status_failed')) !== null) {
            $this->auditService->error('call_config_error', array_merge($logContext, [
                'result' => 'failed',
                'normalized_result' => $configurationError,
            ]));

            return $configurationError;
        }

        return $this->sendRequest(
            method: 'GET',
            endpointPath: sprintf('/%s/call_permissions', $phoneNumberId),
            query: [
                'user_wa_id' => $userWaId,
            ],
            logContext: $logContext,
            normalizationContext: [
                'success_action' => 'permission_status_checked',
                'failure_action' => 'permission_status_failed',
                'error_permission_status' => WhatsAppCallSession::PERMISSION_FAILED,
            ],
        );
    }

    /**
     * @return array<string, string>
     */
    public function buildAuthHeaders(): array
    {
        return [
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.$this->accessToken,
        ];
    }

    public function buildBaseUrl(string $path = ''): string
    {
        $base = $this->baseUrl !== '' ? $this->baseUrl : 'https://graph.facebook.com';
        $version = $this->apiVersion !== '' ? trim($this->apiVersion, '/') : 'v23.0';
        $normalizedPath = ltrim($path, '/');

        return $normalizedPath === ''
            ? sprintf('%s/%s', $base, $version)
            : sprintf('%s/%s/%s', $base, $version, $normalizedPath);
    }

    /**
     * @param  Response|mixed  $response
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function normalizeMetaResponse(mixed $response, array $context = []): array
    {
        $statusCode = $response instanceof Response
            ? $response->status()
            : (int) ($context['status_code'] ?? 0);

        $raw = $this->extractRawResponse($response);
        $error = $this->extractMetaError($raw, $context);
        $metaCallId = $this->extractMetaCallId($raw);
        $rawPermissionStatus = $this->extractRawPermissionStatus($raw);
        $permissionStatus = $this->inferInternalPermissionStatus($raw, $context);
        $retryAfterSeconds = $this->extractRetryAfterSeconds($response, $raw, $error);
        $rateLimitedUntil = $this->resolveRateLimitedUntil($retryAfterSeconds, $error);

        $ok = $response instanceof Response
            ? $response->successful()
            : $error === null;

        $result = [
            'ok' => $ok,
            'status_code' => $statusCode,
            'action' => $ok
                ? (string) ($context['success_action'] ?? 'meta_request_succeeded')
                : (string) ($context['failure_action'] ?? 'meta_request_failed'),
            'meta_call_id' => $metaCallId,
            'permission_status' => $permissionStatus,
            'raw_permission_status' => $rawPermissionStatus,
            'permission_expires_at' => $this->extractPermissionExpiration($raw),
            'can_request_permission' => $this->extractActionCapability($raw, 'send_call_permission_request'),
            'can_start_call' => $this->extractActionCapability($raw, 'start_call'),
            'actions' => $this->extractActions($raw),
            'retry_after_seconds' => $retryAfterSeconds,
            'rate_limited_until' => $rateLimitedUntil,
            'raw' => $raw,
        ];

        if ($error !== null) {
            $result['error'] = $error;
            $result['is_rate_limited'] = $this->looksLikeRateLimitError($error);
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $logContext
     * @param  array<string, mixed>  $normalizationContext
     * @return array<string, mixed>
     */
    private function sendRequest(
        string $method,
        string $endpointPath,
        array $query = [],
        array $body = [],
        array $logContext = [],
        array $normalizationContext = [],
    ): array {
        $endpoint = $this->buildBaseUrl($endpointPath);
        $request = Http::withHeaders($this->buildAuthHeaders())
            ->timeout($this->timeoutSeconds)
            ->withOptions([
                'verify' => $this->verifySsl,
            ]);

        $attempt = 0;
        $maxAttempts = 1 + ($this->retryEnabled ? $this->maxRetries : 0);

        while (true) {
            $attempt++;

            try {
                $response = match (strtoupper($method)) {
                    'GET' => $request->get($endpoint, $query),
                    'POST' => $request->post($endpoint, $body),
                    default => $request->send(strtoupper($method), $endpoint, [
                        'query' => $query,
                        'json' => $body,
                    ]),
                };

                $normalized = $this->normalizeMetaResponse($response, array_merge($normalizationContext, [
                    'status_code' => $response->status(),
                ]));

                if ($this->shouldRetryResponse($response, $attempt, $maxAttempts)) {
                    $this->auditService->warning('call_retry_attempt', array_merge($logContext, [
                        'result' => 'retrying',
                        'http_status' => $response->status(),
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'backoff_ms' => $this->backoffForAttempt($attempt),
                    ]));

                    $this->sleepForRetry($attempt);
                    continue;
                }

                $logLevel = $normalized['ok'] ? 'info' : 'warning';
                WaLog::{$logLevel}('[MetaCallingApi] Meta response received', array_merge(
                    $logContext,
                    [
                        'endpoint' => $endpoint,
                        'http_status' => $response->status(),
                        'attempt' => $attempt,
                        'meta_error_code' => data_get($normalized, 'error.code'),
                        'meta_error_message' => data_get($normalized, 'error.message'),
                        'normalized_result' => $normalized,
                    ],
                ));

                return $normalized;
            } catch (ConnectionException $exception) {
                $normalized = $this->normalizeMetaResponse(null, array_merge($normalizationContext, [
                    'status_code' => 0,
                    'failure_action' => (string) ($normalizationContext['failure_action'] ?? 'meta_request_failed'),
                    'error_code' => 'meta_connection_exception',
                    'error_message' => $exception->getMessage(),
                ]));

                if ($this->shouldRetryThrowable($exception, $attempt, $maxAttempts)) {
                    $this->auditService->warning('call_retry_attempt', array_merge($logContext, [
                        'result' => 'retrying',
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'backoff_ms' => $this->backoffForAttempt($attempt),
                        'meta_error_code' => data_get($normalized, 'error.code'),
                        'meta_error_message' => data_get($normalized, 'error.message'),
                    ]));

                    $this->sleepForRetry($attempt);
                    continue;
                }

                WaLog::error('[MetaCallingApi] Connection exception while calling Meta', array_merge(
                    $logContext,
                    [
                        'endpoint' => $endpoint,
                        'http_status' => 0,
                        'attempt' => $attempt,
                        'meta_error_code' => data_get($normalized, 'error.code'),
                        'meta_error_message' => data_get($normalized, 'error.message'),
                        'normalized_result' => $normalized,
                    ],
                ));

                return $normalized;
            } catch (\Throwable $exception) {
                $normalized = $this->normalizeMetaResponse(null, array_merge($normalizationContext, [
                    'status_code' => 0,
                    'failure_action' => (string) ($normalizationContext['failure_action'] ?? 'meta_request_failed'),
                    'error_code' => 'meta_unexpected_exception',
                    'error_message' => $exception->getMessage(),
                ]));

                if ($this->shouldRetryUnexpectedThrowable($exception, $attempt, $maxAttempts)) {
                    $this->auditService->warning('call_retry_attempt', array_merge($logContext, [
                        'result' => 'retrying',
                        'attempt' => $attempt,
                        'max_attempts' => $maxAttempts,
                        'backoff_ms' => $this->backoffForAttempt($attempt),
                        'meta_error_code' => data_get($normalized, 'error.code'),
                        'meta_error_message' => data_get($normalized, 'error.message'),
                    ]));

                    $this->sleepForRetry($attempt);
                    continue;
                }

                WaLog::error('[MetaCallingApi] Unexpected exception while calling Meta', array_merge(
                    $logContext,
                    [
                        'endpoint' => $endpoint,
                        'http_status' => 0,
                        'attempt' => $attempt,
                        'meta_error_code' => data_get($normalized, 'error.code'),
                        'meta_error_message' => data_get($normalized, 'error.message'),
                        'normalized_result' => $normalized,
                    ],
                ));

                return $normalized;
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function resolveRequestBody(array $payload): array
    {
        $requestBody = is_array($payload['payload'] ?? null)
            ? $payload['payload']
            : Arr::except($payload, [
                'conversation_id',
                'call_session_id',
                'customer_wa_id',
                'customer_phone',
                'phone_number_id',
                'action',
                'request_summary',
            ]);

        return is_array($requestBody) ? $requestBody : [];
    }

    private function resolvePhoneNumberId(array $payload): string
    {
        $candidate = trim((string) ($payload['phone_number_id'] ?? ''));

        return $candidate !== '' ? $candidate : $this->phoneNumberId;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $requestSummary
     * @return array<string, mixed>
     */
    private function buildLogContext(
        array $payload,
        string $endpoint,
        string $action,
        array $requestSummary = [],
    ): array {
        $customerIdentifier = trim((string) ($payload['customer_phone'] ?? $payload['customer_wa_id'] ?? ''));

        return [
            'conversation_id' => $payload['conversation_id'] ?? null,
            'call_session_id' => $payload['call_session_id'] ?? null,
            'customer_identifier' => $customerIdentifier !== '' ? WaLog::maskPhone($customerIdentifier) : null,
            'phone_number_id' => $this->resolvePhoneNumberId($payload),
            'waba_id' => $this->wabaId !== '' ? $this->wabaId : null,
            'action' => $action,
            'endpoint' => $endpoint,
            'request_summary' => $requestSummary,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function summarizeRequestPayload(array $payload): array
    {
        $summary = Arr::except($payload, ['session']);

        if (is_array($payload['session'] ?? null)) {
            $summary['session_present'] = true;
            $summary['session_keys'] = array_values(array_keys($payload['session']));
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function configurationError(string $phoneNumberId, string $failureAction): ?array
    {
        $errors = [];

        if (! $this->enabled) {
            $errors[] = 'WHATSAPP_CALLING_ENABLED=false';
        }

        if ($this->accessToken === '') {
            $errors[] = 'access token kosong';
        }

        if ($phoneNumberId === '') {
            $errors[] = 'phone_number_id kosong';
        }

        if ($this->baseUrl === '') {
            $errors[] = 'base_url kosong';
        } elseif (filter_var($this->baseUrl, FILTER_VALIDATE_URL) === false) {
            $errors[] = 'base_url tidak valid';
        }

        if ($this->apiVersion === '') {
            $errors[] = 'api_version kosong';
        }

        if ($errors === []) {
            return null;
        }

        return $this->normalizeMetaResponse(null, [
            'status_code' => 0,
            'failure_action' => $failureAction,
            'error_code' => 'calling_configuration_incomplete',
            'error_message' => 'Konfigurasi WhatsApp Calling belum lengkap: '.implode(', ', $errors).'.',
            'error_permission_status' => WhatsAppCallSession::PERMISSION_FAILED,
        ]);
    }

    private function shouldRetryResponse(Response $response, int $attempt, int $maxAttempts): bool
    {
        if (! $this->retryEnabled || $attempt >= $maxAttempts) {
            return false;
        }

        return $response->serverError();
    }

    private function shouldRetryThrowable(ConnectionException $exception, int $attempt, int $maxAttempts): bool
    {
        if (! $this->retryEnabled || $attempt >= $maxAttempts) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'connection refused')
            || str_contains($message, 'could not resolve')
            || str_contains($message, 'temporary');
    }

    private function shouldRetryUnexpectedThrowable(\Throwable $exception, int $attempt, int $maxAttempts): bool
    {
        if (! $this->retryEnabled || $attempt >= $maxAttempts) {
            return false;
        }

        $message = strtolower($exception->getMessage());

        return str_contains($message, 'timed out')
            || str_contains($message, 'timeout')
            || str_contains($message, 'connection reset')
            || str_contains($message, 'temporary');
    }

    private function sleepForRetry(int $attempt): void
    {
        usleep($this->backoffForAttempt($attempt) * 1000);
    }

    private function backoffForAttempt(int $attempt): int
    {
        return $this->retryBackoffMs * (2 ** max(0, $attempt - 1));
    }

    /**
     * @return array<string, mixed>
     */
    private function extractRawResponse(mixed $response): array
    {
        if ($response instanceof Response) {
            $decoded = $response->json();

            if (is_array($decoded)) {
                return $decoded;
            }

            $fallback = json_decode($response->body(), true);

            if (is_array($fallback)) {
                return $fallback;
            }

            $body = trim($response->body());

            return $body !== '' ? ['body' => $body] : [];
        }

        if (is_array($response)) {
            return $response;
        }

        if (is_string($response)) {
            $decoded = json_decode($response, true);

            if (is_array($decoded)) {
                return $decoded;
            }

            return trim($response) !== '' ? ['body' => $response] : [];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $context
     * @return array<string, string>|null
     */
    private function extractMetaError(array $raw, array $context = []): ?array
    {
        $errorPayload = is_array($raw['error'] ?? null)
            ? $raw['error']
            : (is_array($raw['errors'][0] ?? null) ? $raw['errors'][0] : null);

        $code = trim((string) (
            $errorPayload['code']
            ?? $errorPayload['error_subcode']
            ?? $context['error_code']
            ?? ''
        ));

        $message = trim((string) (
            $errorPayload['message']
            ?? data_get($errorPayload, 'error_data.details')
            ?? $raw['message']
            ?? $raw['body']
            ?? $context['error_message']
            ?? ''
        ));

        if ($code === '' && $message === '') {
            return null;
        }

        return [
            'code' => $code !== '' ? $code : 'meta_unknown_error',
            'message' => $message !== '' ? $message : 'Meta Calling API mengembalikan error yang tidak dikenal.',
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<string, mixed>  $context
     */
    private function inferInternalPermissionStatus(array $raw, array $context = []): ?string
    {
        $rawStatus = $this->extractRawPermissionStatus($raw);
        if ($rawStatus !== null) {
            if (in_array($rawStatus, self::PERMISSION_GRANTED_STATUSES, true)) {
                return WhatsAppCallSession::PERMISSION_GRANTED;
            }

            if (in_array($rawStatus, self::PERMISSION_REQUESTED_STATUSES, true)) {
                return WhatsAppCallSession::PERMISSION_REQUESTED;
            }

            if (in_array($rawStatus, self::PERMISSION_DENIED_STATUSES, true)) {
                return WhatsAppCallSession::PERMISSION_DENIED;
            }

            if (in_array($rawStatus, self::PERMISSION_EXPIRED_STATUSES, true)) {
                return WhatsAppCallSession::PERMISSION_EXPIRED;
            }
        }

        $error = $this->extractMetaError($raw, $context);
        if ($error !== null) {
            if ($this->looksLikeRateLimitError($error)) {
                return WhatsAppCallSession::PERMISSION_RATE_LIMITED;
            }

            if ($this->looksLikeExpiredError($error)) {
                return WhatsAppCallSession::PERMISSION_EXPIRED;
            }

            if ($this->looksLikeDeniedError($error)) {
                return WhatsAppCallSession::PERMISSION_DENIED;
            }

            return (string) ($context['error_permission_status'] ?? WhatsAppCallSession::PERMISSION_FAILED);
        }

        if ($this->extractActionCapability($raw, 'start_call') === true) {
            return WhatsAppCallSession::PERMISSION_GRANTED;
        }

        if ($this->extractActionCapability($raw, 'send_call_permission_request') === false) {
            return WhatsAppCallSession::PERMISSION_REQUESTED;
        }

        $explicit = trim((string) (
            $context['success_permission_status']
            ?? $context['error_permission_status']
            ?? ''
        ));

        return $explicit !== '' ? $explicit : WhatsAppCallSession::PERMISSION_REQUIRED;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractMetaCallId(array $raw): ?string
    {
        foreach ([
            data_get($raw, 'calls.0.id'),
            data_get($raw, 'calls.0.call_id'),
            $raw['id'] ?? null,
            $raw['call_id'] ?? null,
        ] as $candidate) {
            $normalized = trim((string) $candidate);

            if ($normalized !== '') {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractRawPermissionStatus(array $raw): ?string
    {
        $candidate = trim((string) (
            data_get($raw, 'permission.status')
            ?? data_get($raw, 'permission.permission_status')
            ?? $raw['permission_status']
            ?? ''
        ));

        return $candidate !== '' ? strtolower($candidate) : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractPermissionExpiration(array $raw): ?string
    {
        $expiration = data_get($raw, 'permission.expiration_time')
            ?? data_get($raw, 'permission.expiration_timestamp')
            ?? null;

        if (is_numeric($expiration)) {
            return now()->setTimestamp((int) $expiration)->toIso8601String();
        }

        $normalized = trim((string) $expiration);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @param  array<string, mixed>  $raw
     * @return list<array<string, mixed>>
     */
    private function extractActions(array $raw): array
    {
        $actions = data_get($raw, 'actions');

        return is_array($actions) ? array_values(array_filter($actions, 'is_array')) : [];
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function extractActionCapability(array $raw, string $actionName): ?bool
    {
        foreach ($this->extractActions($raw) as $action) {
            if ((string) ($action['action_name'] ?? '') !== $actionName) {
                continue;
            }

            if (array_key_exists('can_perform_action', $action)) {
                return (bool) $action['can_perform_action'];
            }
        }

        return null;
    }

    private function extractRetryAfterSeconds(mixed $response, array $raw, ?array $error): ?int
    {
        if ($response instanceof Response) {
            $header = trim((string) ($response->header('Retry-After') ?? ''));
            if ($header !== '' && is_numeric($header)) {
                return max(0, (int) $header);
            }
        }

        $candidate = data_get($raw, 'error.error_data.retry_after')
            ?? data_get($raw, 'error.retry_after')
            ?? data_get($raw, 'retry_after');

        if (is_numeric($candidate)) {
            return max(0, (int) $candidate);
        }

        if ($error !== null && $this->looksLikeRateLimitError($error)) {
            return $this->rateLimitCooldownSeconds;
        }

        return null;
    }

    private function resolveRateLimitedUntil(?int $retryAfterSeconds, ?array $error): ?string
    {
        if ($retryAfterSeconds !== null && $retryAfterSeconds > 0) {
            return now()->addSeconds($retryAfterSeconds)->toIso8601String();
        }

        if ($error !== null && $this->looksLikeRateLimitError($error)) {
            return now()->addSeconds($this->rateLimitCooldownSeconds)->toIso8601String();
        }

        return null;
    }

    /**
     * @param  array<string, string>  $error
     */
    private function looksLikeRateLimitError(array $error): bool
    {
        $normalizedMessage = strtolower(trim((string) ($error['message'] ?? '')));
        $normalizedCode = trim((string) ($error['code'] ?? ''));

        if (in_array($normalizedCode, ['4', '80007', '130429', '131056', '429'], true)) {
            return true;
        }

        return str_contains($normalizedMessage, 'rate limit')
            || str_contains($normalizedMessage, 'too many')
            || str_contains($normalizedMessage, 'temporarily blocked')
            || str_contains($normalizedMessage, 'throttle');
    }

    /**
     * @param  array<string, string>  $error
     */
    private function looksLikeExpiredError(array $error): bool
    {
        $message = strtolower(trim((string) ($error['message'] ?? '')));

        return str_contains($message, 'expired')
            || str_contains($message, 'no longer valid')
            || str_contains($message, 'permission has expired');
    }

    /**
     * @param  array<string, string>  $error
     */
    private function looksLikeDeniedError(array $error): bool
    {
        $message = strtolower(trim((string) ($error['message'] ?? '')));

        return str_contains($message, 'deny')
            || str_contains($message, 'denied')
            || str_contains($message, 'not eligible')
            || str_contains($message, 'blocked')
            || str_contains($message, 'not allowed');
    }
}
