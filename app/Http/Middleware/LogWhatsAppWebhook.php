<?php

namespace App\Http\Middleware;

use App\Support\WaLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogWhatsAppWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $traceId = WaLog::newTrace();

        $request->attributes->set('wa_trace_id', $traceId);

        $method = $request->method();
        $ip = $request->ip() ?? 'unknown';
        $userAgent = substr($request->userAgent() ?? 'unknown', 0, 120);
        $hasSignature = trim((string) $request->header('X-Hub-Signature-256', '')) !== '';

        WaLog::emergency("WEBHOOK {$method} [{$traceId}] {$ip}", [
            'method' => $method,
            'ip' => $ip,
            'path' => $request->path(),
            'user_agent' => $userAgent,
            'query_keys' => array_keys($request->query()),
            'has_signature' => $hasSignature,
        ], 'INFO');

        WaLog::info("[Middleware] Webhook {$method} request received", [
            'ip' => $ip,
            'path' => $request->path(),
            'user_agent' => $userAgent,
            'query_keys' => array_keys($request->query()),
            'has_signature' => $hasSignature,
        ]);

        $startMs = (int) round(microtime(true) * 1000);
        $response = $next($request);
        $durationMs = (int) round(microtime(true) * 1000) - $startMs;

        $status = $response->getStatusCode();

        $logLevel = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default => 'info',
        };

        WaLog::{$logLevel}("[Middleware] Webhook {$method} responded {$status}", [
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }
}
