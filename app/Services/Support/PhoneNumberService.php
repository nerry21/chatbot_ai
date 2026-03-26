<?php

namespace App\Services\Support;

class PhoneNumberService
{
    /**
     * Normalize a phone number to E.164 format.
     *
     * Rules:
     * - 08123456789        -> +628123456789
     * - 628123456789       -> +628123456789
     * - +628123456789      -> +628123456789
     * - 00628123456789     -> +628123456789
     * - 628123456789@s.whatsapp.net -> +628123456789
     */
    public function toE164(string $raw, string $defaultCountryCode = '62'): string
    {
        $raw = trim($raw);

        if ($raw === '') {
            return '';
        }

        // Remove WhatsApp suffix if present
        $raw = $this->stripWaSuffix($raw);

        // Keep leading plus if present, remove all other non-digit chars
        $cleaned = preg_replace('/(?!^\+)\D+/', '', $raw) ?? '';

        if ($cleaned === '') {
            return '';
        }

        // Already E.164
        if (str_starts_with($cleaned, '+')) {
            $digits = preg_replace('/\D+/', '', substr($cleaned, 1)) ?? '';

            if ($digits === '') {
                return '';
            }

            return '+' . $digits;
        }

        // International prefix 00...
        if (str_starts_with($cleaned, '00')) {
            $digits = substr($cleaned, 2);

            if ($digits === '') {
                return '';
            }

            return '+' . $digits;
        }

        // Local Indonesian: 08xxxx -> +628xxxx
        if (str_starts_with($cleaned, '0')) {
            return '+' . $defaultCountryCode . substr($cleaned, 1);
        }

        // Already has country code: 62xxxx -> +62xxxx
        if (str_starts_with($cleaned, $defaultCountryCode)) {
            return '+' . $cleaned;
        }

        // Fallback: assume local number without leading 0, e.g. 8123... -> +628123...
        return '+' . $defaultCountryCode . $cleaned;
    }

    /**
     * Validate that a string looks like a plausible E.164 number.
     * Minimum 7 digits, maximum 15 digits.
     */
    public function isValidE164(string $phone): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{6,14}$/', $phone);
    }

    /**
     * Strip WhatsApp suffix from a phone number.
     * Example: 628123456789@s.whatsapp.net -> 628123456789
     */
    public function stripWaSuffix(string $phone): string
    {
        return preg_replace('/@.*$/', '', trim($phone)) ?? '';
    }

    /**
     * Extract and normalize phone from a WhatsApp sender identifier.
     */
    public function normalizeWaSender(string $waSender): string
    {
        return $this->toE164($this->stripWaSuffix($waSender));
    }

    /**
     * Normalize to digits only without leading plus.
     * Example: +628123456789 -> 628123456789
     */
    public function toDigits(string $raw, string $defaultCountryCode = '62'): string
    {
        $e164 = $this->toE164($raw, $defaultCountryCode);

        return ltrim($e164, '+');
    }
}