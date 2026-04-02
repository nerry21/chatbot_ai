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

    // ─── GET /webhook/whatsapp — Meta hub verification ────────────────────────

    public function verify(Request $request): Response|JsonResponse
    {
        $mode = (string) $request->query('hub_mode', '');
        $token = (string) $request->query('hub_verify_token', '');
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

        $expectedToken = (string) config('services.whatsapp.verify_token', '');

        if ($expectedToken === '') {
            WaLog::error('[Verify] Missing config services.whatsapp.verify_token', [
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Server misconfiguration.',
            ], 500);
        }

        if (! hash_equals($expectedToken, $token)) {
            WaLog::warning('[Verify] Rejected — token mismatch', [
                'received_preview' => WaLog::maskToken($token),
                'expected_preview' => WaLog::maskToken($expectedToken),
                'ip' => $request->ip(),
            ]);

            return response()->json([
                'error' => 'Forbidden.',
            ], 403);
        }

        WaLog::info('[Verify] SUCCESS — challenge echoed back', [
            'challenge' => $challenge,
            'ip' => $request->ip(),
        ]);

        return response($challenge, 200)
            ->header('Content-Type', 'text/plain; charset=UTF-8');
    }

    // ─── POST /webhook/whatsapp — Incoming messages from Meta ────────────────

    public function receive(Request $request): JsonResponse
    {
        $rawBody = (string) $request->getContent();
        $signatureHeader = trim((string) $request->header('X-Hub-Signature-256', ''));

        WaLog::info('[Receive] POST webhook reached', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'has_signature' => $signatureHeader !== '',
            'content_length' => mb_strlen($rawBody),
        ]);

        if ($this->isSignatureValidationEnabled() && ! $this->hasValidSignature($signatureHeader, $rawBody)) {
            $this->auditService->warning('webhook_signature_invalid', [
                'result' => 'rejected',
                'ip' => $request->ip(),
                'has_signature' => $signatureHeader !== '',
            ]);

            return response()->json([
                'error' => 'Forbidden.',
            ], 403);
        }

        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload)) {
            WaLog::warning('[Receive] Empty payload received', [
                'ip' => $request->ip(),
                'content_length' => mb_strlen($rawBody),
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

    private function isSignatureValidationEnabled(): bool
    {
        return (bool) config('chatbot.whatsapp.calling.webhook_signature_enabled', false);
    }

    private function hasValidSignature(string $signatureHeader, string $rawBody): bool
    {
        $secret = trim((string) config('chatbot.whatsapp.webhook_secret', ''));

        if ($secret === '' || $signatureHeader === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $rawBody, $secret);

        return hash_equals($expected, $signatureHeader);
    }
}
