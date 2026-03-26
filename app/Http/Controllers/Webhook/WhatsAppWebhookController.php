<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Services\WhatsApp\WhatsAppWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(
        private readonly WhatsAppWebhookService $webhookService,
    ) {}

    // -------------------------------------------------------------------------
    // GET /webhook/whatsapp  — Meta webhook verification
    // -------------------------------------------------------------------------

    private function log(): \Illuminate\Log\LogManager|\Psr\Log\LoggerInterface
    {
        return Log::channel('whatsapp_stack');
    }

    public function verify(Request $request): Response|JsonResponse
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe') {
            $this->log()->warning('WhatsApp webhook verify: unexpected hub_mode', ['hub_mode' => $mode]);
            return response()->json(['error' => 'Invalid hub_mode.'], 400);
        }

        $expectedToken = config('services.whatsapp.verify_token');

        if (! hash_equals((string) $expectedToken, (string) $token)) {
            $this->log()->warning('WhatsApp webhook verify: token mismatch', [
                'ip' => $request->ip(),
            ]);
            return response()->json(['error' => 'Forbidden.'], 403);
        }

        $this->log()->info('WhatsApp webhook verified successfully', ['ip' => $request->ip()]);

        // Meta expects us to echo back the challenge as plain text with 200
        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    // -------------------------------------------------------------------------
    // POST /webhook/whatsapp  — Incoming messages from Meta
    // -------------------------------------------------------------------------

    public function receive(Request $request): JsonResponse
    {
        $payload = $request->json()->all();

        if (empty($payload)) {
            $this->log()->warning('WhatsApp webhook receive: empty payload', ['ip' => $request->ip()]);
            return response()->json(['error' => 'Empty payload.'], 400);
        }

        $this->log()->debug('WhatsApp webhook raw payload received', [
            'object'  => $payload['object'] ?? null,
            'entries' => count($payload['entry'] ?? []),
        ]);

        try {
            $this->webhookService->handle($payload);
        } catch (\Throwable $e) {
            // Always return 200 to Meta to prevent retries for unrecoverable errors.
            $this->log()->error('WhatsApp webhook handle exception', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        // Meta requires a 200 OK response regardless of processing outcome.
        return response()->json(['status' => 'ok'], 200);
    }
}
