<?php

namespace App\Services\WhatsApp;

use App\Support\WaLog;

class WhatsAppCallAuditService
{
    private readonly bool $verbose;

    public function __construct()
    {
        $this->verbose = (bool) config('chatbot.whatsapp.calling.log_verbose', false);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function info(string $event, array $context = []): void
    {
        $this->write('info', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function warning(string $event, array $context = []): void
    {
        $this->write('warning', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function error(string $event, array $context = []): void
    {
        $this->write('error', $event, $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function write(string $level, string $event, array $context): void
    {
        $payload = array_merge([
            'trace_id' => WaLog::traceId(),
            'audit_event' => $event,
        ], $this->sanitizeContext($context));

        WaLog::{$level}('[CallAudit] '.$event, $payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function sanitizeContext(array $context): array
    {
        $sanitized = [];

        foreach ($context as $key => $value) {
            $lowerKey = strtolower((string) $key);

            if (is_array($value)) {
                if (! $this->verbose && in_array($lowerKey, ['raw', 'payload', 'headers'], true)) {
                    $sanitized[$key] = [
                        'truncated' => true,
                        'keys' => array_values(array_slice(array_keys($value), 0, 15)),
                    ];

                    continue;
                }

                $sanitized[$key] = $this->sanitizeContext($value);

                continue;
            }

            if ($this->shouldMaskValue($lowerKey, $value)) {
                $sanitized[$key] = WaLog::maskPhone((string) $value);
                continue;
            }

            if (is_string($value) && ! $this->verbose && mb_strlen($value) > 400) {
                $sanitized[$key] = mb_substr($value, 0, 400).'...[truncated]';
                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    private function shouldMaskValue(string $lowerKey, mixed $value): bool
    {
        if (! is_scalar($value)) {
            return false;
        }

        if (str_contains($lowerKey, 'token') || str_contains($lowerKey, 'secret') || str_contains($lowerKey, 'authorization')) {
            return false;
        }

        foreach ([
            'customer_identifier',
            'customer_phone',
            'customer_wa_id',
            'wa_id',
            'phone',
            'from',
            'to',
        ] as $phoneLikeKey) {
            if (str_contains($lowerKey, $phoneLikeKey)) {
                return true;
            }
        }

        return false;
    }
}
