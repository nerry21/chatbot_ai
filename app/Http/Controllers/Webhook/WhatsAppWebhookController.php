<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppCallAuditService;
use App\Services\WhatsApp\WhatsAppWebhookService;
use App\Support\WaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebhookService $webhookService,
        private readonly WhatsAppCallAuditService $auditService,
    ) {
    }

    public function verify(Request $request): Response|JsonResponse
    {
        $mode = trim((string) $request->query('hub_mode', ''));
        $token = trim((string) $request->query('hub_verify_token', ''));
        $challenge = (string) $request->query('hub_challenge', '');

        WaLog::info('[Verify] Parameters received', [
            'hub_mode' => $mode,
            'has_challenge' => $challenge !== '',
            'token_preview' => WaLog::maskToken($token),
            'ip' => $request->ip(),
        ]);

        if ($mode !== 'subscribe') {
            WaLog::warning('[Verify] Rejected — unexpected hub_mode', [
                'hub_mode' => $mode,
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Invalid hub_mode.',
            ], 400);
        }

        $acceptedTokens = $this->acceptedVerifyTokens();

        if ($acceptedTokens === []) {
            WaLog::error('[Verify] Missing config services.whatsapp.verify_token', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Server misconfiguration.',
            ], 500);
        }

        if (! $this->matchesVerifyToken($token, $acceptedTokens)) {
            WaLog::warning('[Verify] Rejected — token mismatch', [
                'received_preview' => WaLog::maskToken($token),
                'expected_preview' => WaLog::maskToken($acceptedTokens[0] ?? ''),
                'accepted_token_count' => count($acceptedTokens),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Forbidden.',
                'message' => 'Webhook verify token mismatch. Samakan token di Meta Webhook dengan WHATSAPP_VERIFY_TOKEN.',
            ], 403);
        }

        WaLog::info('[Verify] SUCCESS — challenge echoed back', [
            'challenge' => $challenge,
            'ip' => $request->ip(),
        ]);

        return response($challenge, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    public function receive(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signatureHeader = trim((string) $request->header('X-Hub-Signature-256', ''));

        WaLog::info('[Receive] POST webhook reached', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'has_signature' => $signatureHeader !== '',
            'content_length' => strlen($rawBody),
        ]);

        if ($this->isSignatureValidationEnabled()) {
            $validation = $this->validateSignature($signatureHeader, $rawBody);

            if (! $validation['valid']) {
                $this->auditService->warning('webhook_signature_invalid', [
                    'result' => 'rejected',
                    'ip' => $request->ip(),
                    'has_signature' => $signatureHeader !== '',
                    'reason' => $validation['reason'],
                    'signature_preview' => $this->maskSignature($signatureHeader),
                    'expected_preview' => $this->maskSignature((string) ($validation['expected'] ?? '')),
                    'secret_config_source' => $validation['secret_source'] ?? null,
                ]);

                return response()->json([
                    'error' => 'Forbidden.',
                    'message' => 'Invalid webhook signature.',
                ], 403);
            }
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload)) {
            WaLog::warning('[Receive] Empty payload received', [
                'ip' => $request->ip(),
                'content_length' => strlen($rawBody),
            ]);

            return response()->json([
                'error' => 'Empty payload.',
            ], 400);
        }

        $entryCount = count($payload['entry'] ?? []);
        $messageCount = 0;
        $statusCount = 0;
        $callCount = 0;
        $eventTypes = [];
        $statusDetails = [];
        $callDetails = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = $change['value'] ?? [];

                $messages = $value['messages'] ?? [];
                $statuses = $value['statuses'] ?? [];
                $calls = [];

                if ($field === 'calls' && is_array($value['calls'] ?? null)) {
                    $calls = $value['calls'];
                } elseif ($field === 'calls') {
                    $calls = [$value];
                } elseif (is_array($value['calls'] ?? null)) {
                    $calls = $value['calls'];
                }

                if (is_array($messages)) {
                    $messageCount += count($messages);

                    foreach ($messages as $message) {
                        $eventTypes[] = 'message:' . ($message['type'] ?? 'unknown');
                    }
                }

                if (is_array($statuses)) {
                    $statusCount += count($statuses);

                    foreach ($statuses as $status) {
                        $eventTypes[] = 'status:' . ($status['status'] ?? 'unknown');

                        $statusDetails[] = [
                            'id' => $status['id'] ?? null,
                            'status' => $status['status'] ?? null,
                            'recipient_id' => WaLog::maskPhone((string) ($status['recipient_id'] ?? '')),
                            'timestamp' => $status['timestamp'] ?? null,
                            'conversation' => $status['conversation'] ?? null,
                            'pricing' => $status['pricing'] ?? null,
                            'errors' => $status['errors'] ?? null,
                        ];
                    }
                }

                if (is_array($calls)) {
                    $callCount += count($calls);

                    foreach ($calls as $call) {
                        if (! is_array($call)) {
                            continue;
                        }

                        $event = $call['event']
                            ?? $call['status']
                            ?? $call['call_status']
                            ?? $call['state']
                            ?? null;

                        $eventTypes[] = 'call:' . ($event ?? 'unknown');

                        if (count($callDetails) < 20) {
                            $callDetails[] = [
                                'id' => $call['id'] ?? $call['call_id'] ?? null,
                                'event' => $event,
                                'direction' => $call['direction'] ?? $call['call_direction'] ?? null,
                                'from' => WaLog::maskPhone((string) ($call['from'] ?? '')),
                                'to' => WaLog::maskPhone((string) ($call['to'] ?? '')),
                                'timestamp' => $call['timestamp'] ?? null,
                                'termination_reason' => $call['termination_reason'] ?? $call['reason'] ?? null,
                            ];
                        }
                    }
                }
            }
        }

        WaLog::info('[Receive] Payload accepted', [
            'object' => $payload['object'] ?? null,
            'entry_count' => $entryCount,
            'message_count' => $messageCount,
            'status_count' => $statusCount,
            'call_count' => $callCount,
            'event_types' => array_values(array_unique($eventTypes)),
            'status_details' => $statusDetails,
            'call_details' => $callDetails,
            'ip' => $request->ip(),
        ]);

        $summary = [
            'accepted' => true,
            'message_count' => $messageCount,
            'status_count' => $statusCount,
            'call_count' => $callCount,
        ];

        try {
            $summary = $this->webhookService->handle($payload);

            WaLog::info('[Receive] Webhook processing summary', [
                'summary' => $summary,
                'ip' => $request->ip(),
            ]);
        } catch (Throwable $e) {
            WaLog::error('[Receive] Unhandled exception in webhookService->handle()', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
                'object' => $payload['object'] ?? null,
                'entry_count' => $entryCount,
                'message_count' => $messageCount,
                'status_count' => $statusCount,
                'call_count' => $callCount,
                'event_types' => array_values(array_unique($eventTypes)),
                'status_details' => $statusDetails,
                'call_details' => $callDetails,
            ]);
        }

        return response()->json([
            'status' => 'ok',
            'summary' => [
                'message_count' => (int) ($summary['message_count'] ?? $messageCount),
                'status_count' => (int) ($summary['status_count'] ?? $statusCount),
                'call_count' => (int) ($summary['call_count'] ?? $callCount),
                'processed_calls' => (int) ($summary['processed_calls'] ?? 0),
                'ignored_calls' => (int) ($summary['ignored_calls'] ?? 0),
                'errored_calls' => (int) ($summary['errored_calls'] ?? 0),
            ],
        ], 200);
    }

    private function acceptedVerifyTokens(): array
    {
        $primary = trim((string) config('services.whatsapp.verify_token', ''));
        $fallbacks = config('services.whatsapp.verify_token_fallbacks', []);

        if (! is_array($fallbacks)) {
            $fallbacks = [];
        }

        return array_values(array_filter(array_unique(array_map(
            static fn (mixed $value): string => trim((string) $value),
            array_merge([$primary], $fallbacks),
        ))));
    }

    private function matchesVerifyToken(string $incomingToken, array $acceptedTokens): bool
    {
        foreach ($acceptedTokens as $acceptedToken) {
            if ($acceptedToken !== '' && hash_equals($acceptedToken, $incomingToken)) {
                return true;
            }
        }

        return false;
    }

    private function isSignatureValidationEnabled(): bool
    {
        return (bool) config('chatbot.whatsapp.calling.webhook_signature_enabled', false);
    }

    /**
     * @return array{
     *   valid: bool,
     *   reason: string,
     *   expected?: string,
     *   secret_source?: string
     * }
     */
    private function validateSignature(string $signatureHeader, string $rawBody): array
    {
        if ($signatureHeader === '') {
            return [
                'valid' => false,
                'reason' => 'missing_signature_header',
            ];
        }

        $secret = $this->resolveWebhookSecret();

        if ($secret['value'] === '') {
            return [
                'valid' => false,
                'reason' => 'missing_app_secret_config',
                'secret_source' => $secret['source'],
            ];
        }

        if (! str_starts_with($signatureHeader, 'sha256=')) {
            return [
                'valid' => false,
                'reason' => 'invalid_signature_format',
                'secret_source' => $secret['source'],
            ];
        }

        $expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret['value']);

        if (! hash_equals($expected, $signatureHeader)) {
            return [
                'valid' => false,
                'reason' => 'signature_mismatch',
                'expected' => $expected,
                'secret_source' => $secret['source'],
            ];
        }

        return [
            'valid' => true,
            'reason' => 'ok',
            'secret_source' => $secret['source'],
        ];
    }

    /**
     * @return array{value: string, source: string}
     */
    private function resolveWebhookSecret(): array
    {
        $servicesSecret = trim((string) config('services.whatsapp.app_secret', ''));
        if ($servicesSecret !== '') {
            return [
                'value' => $servicesSecret,
                'source' => 'services.whatsapp.app_secret',
            ];
        }

        $legacyMetaSecret = trim((string) env('META_APP_SECRET', ''));
        if ($legacyMetaSecret !== '') {
            return [
                'value' => $legacyMetaSecret,
                'source' => 'env:META_APP_SECRET',
            ];
        }

        $legacyWebhookSecret = trim((string) config('chatbot.whatsapp.webhook_secret', ''));
        if ($legacyWebhookSecret !== '') {
            return [
                'value' => $legacyWebhookSecret,
                'source' => 'chatbot.whatsapp.webhook_secret',
            ];
        }

        return [
            'value' => '',
            'source' => 'none',
        ];
    }

    private function maskSignature(string $signature): string
    {
        if ($signature === '') {
            return '';
        }

        $normalized = trim($signature);

        if (strlen($normalized) <= 20) {
            return '[MASKED]';
        }

        return substr($normalized, 0, 12) . '***' . substr($normalized, -8);
    }
}