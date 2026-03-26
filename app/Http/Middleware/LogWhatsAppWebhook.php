<?php

namespace App\Http\Middleware;

use App\Support\WaLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that logs EVERY request hitting the WhatsApp webhook routes
 * before any controller, service, or exception handler runs.
 *
 * Two-layer logging:
 *   1. Emergency raw file (file_put_contents) — works even if Laravel is broken.
 *   2. WaLog → whatsapp_stack channel → laravel.log + whatsapp.log.
 *
 * A fresh trace ID is generated here and stored in request attributes
 * so controllers, services, and jobs can all reference the same trace.
 */
class LogWhatsAppWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        // ── 1. Generate fresh trace ID ───────────────────────────────────────
        $traceId = WaLog::newTrace();

        // Share trace with downstream (controllers / services)
        $request->attributes->set('wa_trace_id', $traceId);

        $method    = $request->method();
        $ip        = $request->ip() ?? 'unknown';
        $userAgent = substr($request->userAgent() ?? 'unknown', 0, 120);

        // ── 2. Emergency raw file (layer 1 — bypasses all Laravel plumbing) ──
        //    This line runs as early as possible. Even a fatal in a service
        //    provider cannot prevent this trace from being written.
        WaLog::emergency("WEBHOOK {$method} [{$traceId}] {$ip}", [
            'method'     => $method,
            'ip'         => $ip,
            'path'       => $request->path(),
            'user_agent' => $userAgent,
            'query_keys' => array_keys($request->query()),
        ], 'INFO');

        // ── 3. Laravel channel log (layer 2) ────────────────────────────────
        WaLog::info("[Middleware] Webhook {$method} request received", [
            'ip'         => $ip,
            'path'       => $request->path(),
            'user_agent' => $userAgent,
            'query_keys' => array_keys($request->query()),
        ]);

        // ── 4. Execute request ───────────────────────────────────────────────
        $startMs  = (int) round(microtime(true) * 1000);
        $response = $next($request);
        $durationMs = (int) round(microtime(true) * 1000) - $startMs;

        // ── 5. Log response ──────────────────────────────────────────────────
        $status = $response->getStatusCode();

        $logLevel = match (true) {
            $status >= 500 => 'error',
            $status >= 400 => 'warning',
            default        => 'info',
        };

        WaLog::{$logLevel}("[Middleware] Webhook {$method} responded {$status}", [
            'duration_ms' => $durationMs,
        ]);

        return $response;
    }
}
