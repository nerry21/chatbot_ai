<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;

/**
 * Centralized logging helper for the WhatsApp pipeline.
 *
 * Features:
 *  - Trace ID per request / job for end-to-end correlation
 *  - Phone number and token masking
 *  - Safe context (strips sensitive keys, truncates large values)
 *  - Source caller auto-detection (ClassName::method) in every log entry
 *  - Emergency fallback: writes to a raw file even if Laravel's logger is broken
 *
 * Emergency log format:
 *   YYYY-MM-DD HH:MM:SS LEVEL    [TRACEID] SourceClass::method | Message {"ctx":...}
 */
class WaLog
{
    private const CHANNEL        = 'whatsapp_stack';
    private const EMERGENCY_FILE = 'whatsapp-emergency.log';

    /** Shared trace ID for the current request or job lifecycle. */
    private static ?string $traceId = null;

    // ─── Trace ID ─────────────────────────────────────────────────────────────

    /**
     * Start a brand-new trace (call at the start of each webhook request or job).
     * Returns the new trace ID.
     */
    public static function newTrace(): string
    {
        self::$traceId = strtoupper(substr(md5(uniqid('wa_', true)), 0, 10));
        return self::$traceId;
    }

    /** Return the current trace ID, generating one if none exists. */
    public static function traceId(): string
    {
        if (self::$traceId === null) {
            self::newTrace();
        }
        return self::$traceId;
    }

    /**
     * Inject an existing trace ID (e.g. passed from webhook → job).
     * Allows the same trace to span across queue boundaries.
     */
    public static function setTrace(string $id): void
    {
        self::$traceId = $id;
    }

    // ─── Masking ──────────────────────────────────────────────────────────────

    /**
     * Mask a phone number for safe logging.
     * 628123456789 → 6281*****89
     */
    public static function maskPhone(?string $phone): string
    {
        if (empty($phone)) {
            return '[no-phone]';
        }
        $digits = preg_replace('/\D/', '', $phone);
        $len    = strlen($digits);
        if ($len <= 6) {
            return str_repeat('*', $len);
        }
        return substr($digits, 0, 4) . str_repeat('*', $len - 6) . substr($digits, -2);
    }

    /**
     * Mask a token / secret for safe logging.
     * EAAVKMnrPf2AB… → EAAVKMnr***
     */
    public static function maskToken(?string $token): string
    {
        if (empty($token)) {
            return '[empty]';
        }
        $len = strlen($token);
        if ($len <= 8) {
            return str_repeat('*', $len);
        }
        return substr($token, 0, 8) . '***';
    }

    // ─── Log methods ──────────────────────────────────────────────────────────

    public static function debug(string $message, array $context = []): void
    {
        self::write('debug', $message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        self::write('info', $message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        self::write('warning', $message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        self::write('error', $message, $context);
        // Errors always get an emergency copy too
        self::emergency($message, $context, 'ERROR');
    }

    public static function critical(string $message, array $context = []): void
    {
        self::write('critical', $message, $context);
        self::emergency($message, $context, 'CRITICAL');
    }

    // ─── Emergency fallback ───────────────────────────────────────────────────

    /**
     * Write directly to a raw file — bypasses ALL Laravel plumbing.
     * Guaranteed to produce a trace even if the app is partially broken.
     *
     * Format:
     *   YYYY-MM-DD HH:MM:SS LEVEL    [TRACEID] Source::method | Message {"ctx":...}
     *
     * @param  string|null  $source  Optional caller hint. Auto-detected if null.
     */
    public static function emergency(
        string $message,
        array $context = [],
        string $level = 'EMERGENCY',
        ?string $source = null,
    ): void {
        try {
            $source      = $source ?? self::detectCaller();
            $safeCtx     = self::safeContext($context, 400);
            $contextPart = empty($safeCtx)
                ? ''
                : ' ' . json_encode($safeCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $line = sprintf(
                '%s %-8s [%s] %s | %s%s',
                now()->format('Y-m-d H:i:s'),
                strtoupper($level),
                self::traceId(),
                $source,
                $message,
                $contextPart,
            );

            file_put_contents(
                storage_path('logs/' . self::EMERGENCY_FILE),
                $line . PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        } catch (\Throwable) {
            // Truly nothing we can do if the filesystem is unavailable.
        }
    }

    // ─── Safe context ─────────────────────────────────────────────────────────

    /**
     * Sanitize a context array before passing it to the logger.
     * - Redacts keys that look like tokens / secrets.
     * - Truncates long strings to $maxLen characters.
     * - Stack traces get a higher limit (3 000 chars).
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public static function safeContext(array $context, int $maxLen = 500): array
    {
        static $sensitiveKeys = [
            'token', 'secret', 'password', 'access_token',
            'api_key', 'authorization', 'webhook_secret',
        ];

        $result = [];

        foreach ($context as $k => $v) {
            $lk = strtolower((string) $k);

            // Redact sensitive keys
            foreach ($sensitiveKeys as $sensitive) {
                if (str_contains($lk, $sensitive)) {
                    $result[$k] = '[MASKED]';
                    continue 2;
                }
            }

            // Stack traces: keep up to 3 000 chars
            if (in_array($lk, ['trace', 'stack_trace', 'stacktrace'], true)) {
                $result[$k] = is_string($v) ? substr($v, 0, 3000) : $v;
                continue;
            }

            // Recursive for nested arrays
            if (is_array($v)) {
                $result[$k] = self::safeContext($v, $maxLen);
                continue;
            }

            // Truncate long strings
            if (is_string($v) && strlen($v) > $maxLen) {
                $result[$k] = substr($v, 0, $maxLen) . '…[truncated]';
                continue;
            }

            $result[$k] = $v;
        }

        return $result;
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private static function write(string $level, string $message, array $context): void
    {
        $context['_trace']  = self::traceId();
        $context['_source'] = self::detectCaller();

        try {
            Log::channel(self::CHANNEL)->{$level}($message, self::safeContext($context));
        } catch (\Throwable $e) {
            // Laravel logging failed — write directly to emergency file.
            self::emergency(
                $message,
                $context + ['_logger_error' => $e->getMessage()],
                strtoupper($level),
            );
        }
    }

    /**
     * Walk back the call stack to find the first frame outside WaLog itself.
     * Returns a short "ClassName::method" string for log context and emergency file.
     *
     * Example results:
     *   "WhatsAppWebhookController::verify"
     *   "LogWhatsAppWebhook::handle"
     *   "ProcessIncomingWhatsAppMessage::handle"
     *   "routes/web.php:123"  (for closures)
     */
    private static function detectCaller(): string
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);

        foreach ($frames as $frame) {
            $class = $frame['class'] ?? '';
            if ($class !== '' && $class !== self::class) {
                $method     = $frame['function'] ?? '?';
                $shortClass = class_basename($class);
                return "{$shortClass}::{$method}";
            }
        }

        // Fall back to file:line for closures / procedural code
        foreach ($frames as $frame) {
            if (isset($frame['file']) && $frame['file'] !== __FILE__) {
                $file = basename($frame['file']);
                $line = $frame['line'] ?? '?';
                return "{$file}:{$line}";
            }
        }

        return 'unknown';
    }
}
