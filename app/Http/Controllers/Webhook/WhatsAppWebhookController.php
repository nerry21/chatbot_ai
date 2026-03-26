<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
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
        WaLog::info('[Receive] POST webhook reached', [
            'ip' => $request->ip(),
            'user_agent' => $request->userAgent(),
            'headers' => $request->headers->all(),
            'raw_body' => $request->getContent(),
        ]);

        $payload = $request->json()->all();

        if (! is_array($payload) || empty($payload)) {
            WaLog::warning('[Receive] Empty payload received', [
                'ip' => $request->ip(),
                'raw_body' => $request->getContent(),
            ]);

            return response()->json([
                'error' => 'Empty payload.',
            ], 400);
        }

        $entryCount = count($payload['entry'] ?? []);
        $messageCount = 0;
        $statusCount = 0;
        $eventTypes = [];
        $statusDetails = [];

        foreach (($payload['entry'] ?? []) as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $value = $change['value'] ?? [];

                $messages = $value['messages'] ?? [];
                $statuses = $value['statuses'] ?? [];

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
                            'recipient_id' => $status['recipient_id'] ?? null,
                            'timestamp' => $status['timestamp'] ?? null,
                            'conversation' => $status['conversation'] ?? null,
                            'pricing' => $status['pricing'] ?? null,
                            'errors' => $status['errors'] ?? null,
                        ];
                    }
                }
            }
        }

        WaLog::info('[Receive] Payload accepted', [
            'object' => $payload['object'] ?? null,
            'entry_count' => $entryCount,
            'message_count' => $messageCount,
            'status_count' => $statusCount,
            'event_types' => array_values(array_unique($eventTypes)),
            'status_details' => $statusDetails,
            'payload' => $payload,
            'ip' => $request->ip(),
        ]);

        try {
            $this->webhookService->handle($payload);
        } catch (Throwable $e) {
            WaLog::error('[Receive] Unhandled exception in webhookService->handle()', [
                'error' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
                'ip' => $request->ip(),
                'payload' => $payload,
            ]);
        }

        return response()->json([
            'status' => 'ok',
        ], 200);
    }
}