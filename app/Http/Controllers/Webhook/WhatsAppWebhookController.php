<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppWebhookService;
use App\Support\WaLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebhookService $webhookService,
    ) {}

    // ─── GET /webhook/whatsapp — Meta hub verification ────────────────────────

    public function verify(Request $request): Response|JsonResponse
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        WaLog::info('[Verify] Parameters received', [
            'hub_mode'      => $mode,
            'has_challenge' => ! empty($challenge),
            'token_preview' => WaLog::maskToken((string) $token),
            'ip'            => $request->ip(),
        ]);

        if ($mode !== 'subscribe') {
            WaLog::warning('[Verify] Rejected — unexpected hub_mode', [
                'hub_mode' => $mode,
                'ip'       => $request->ip(),
            ]);
            return response()->json(['error' => 'Invalid hub_mode.'], 400);
        }

        $expectedToken = config('services.whatsapp.verify_token');

        if (! hash_equals((string) $expectedToken, (string) $token)) {
            WaLog::warning('[Verify] Rejected — token mismatch', [
                'received_preview' => WaLog::maskToken((string) $token),
                'expected_preview' => WaLog::maskToken((string) $expectedToken),
                'ip'               => $request->ip(),
            ]);
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        WaLog::info('[Verify] SUCCESS — challenge echoed back', [
            'challenge' => $challenge,
            'ip'        => $request->ip(),
        ]);

        // Meta expects the challenge as plain text with HTTP 200.
        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    // ─── POST /webhook/whatsapp — Incoming messages from Meta ────────────────

    public function receive(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            WaLog::warning('[Receive] Empty payload received', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Empty payload.'], 400);
        }

        // Count entries and messages for the log
        $entryCount   = count($payload['entry'] ?? []);
        $messageCount = 0;
        $eventTypes   = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $messages = $change['value']['messages'] ?? [];
                $statuses = $change['value']['statuses'] ?? [];
                $messageCount += count($messages);
                foreach ($messages as $m) {
                    $eventTypes[] = 'message:' . ($m['type'] ?? 'unknown');
                }
                foreach ($statuses as $s) {
                    $eventTypes[] = 'status:' . ($s['status'] ?? 'unknown');
                }
            }
        }

        WaLog::info('[Receive] Payload accepted', [
            'object'        => $payload['object'] ?? null,
            'entry_count'   => $entryCount,
            'message_count' => $messageCount,
            'event_types'   => array_unique($eventTypes),
            'ip'            => $request->ip(),
        ]);

        try {
            $this->webhookService->handle($payload);
        } catch (\Throwable $e) {
            WaLog::error('[Receive] Unhandled exception in webhookService->handle()', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Always return 200 to Meta — prevents infinite retries.
        return response()->json(['status' => 'ok'], 200);
    }
}
