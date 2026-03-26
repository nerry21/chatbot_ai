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
        $file = storage_path('logs/wa-webhook-hit.log');
        file_put_contents($file, now().' VERIFY HIT '.json_encode($request->query()).PHP_EOL, FILE_APPEND);

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

        return response((string) $challenge, 200)
            ->header('Content-Type', 'text/plain');
    }

    // -------------------------------------------------------------------------
    // POST /webhook/whatsapp  — Incoming messages from Meta
    // -------------------------------------------------------------------------

    public function receive(Request $request): JsonResponse
    {
        $file = storage_path('logs/wa-webhook-hit.log');
        file_put_contents($file, now().' RECEIVE HIT '.file_get_contents('php://input').PHP_EOL, FILE_APPEND);

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
            $this->log()->error('WhatsApp webhook handle exception', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile() . ':' . $e->getLine(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response()->json(['status' => 'ok'], 200);
    }
}