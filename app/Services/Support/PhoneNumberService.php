<?php

namespace App\Services\Support;

class PhoneNumberService
{
    /**
     * Normalize a phone number to E.164 format.
     *
     * Assumes numbers without a leading '+' are Indonesian (ID) by default.
     * Extend this method for multi-country support in later stages.
     */
    public function toE164(string $raw, string $defaultCountryCode = '62'): string
    {
        // Strip all non-digit characters except leading '+'
        $cleaned = preg_replace('/[^\d+]/', '', trim($raw));

        // Already in E.164 with '+'
        if (str_starts_with($cleaned, '+')) {
            return $cleaned;
        }

        // Leading '00' international prefix
        if (str_starts_with($cleaned, '00')) {
            return '+' . substr($cleaned, 2);
        }

        // Local Indonesian number starting with '08...'
        if (str_starts_with($cleaned, '0')) {
            return '+' . $defaultCountryCode . substr($cleaned, 1);
        }

        // Assume already numeric without country code
        return '+' . $defaultCountryCode . $cleaned;
    }

    /**
     * Validate that a string looks like a plausible E.164 number.
     * Minimum 7 digits, maximum 15 digits (ITU-T E.164 standard).
     */
    public function isValidE164(string $phone): bool
    {
        return (bool) preg_match('/^\+[1-9]\d{6,14}$/', $phone);
    }

    /**
     * Strip WhatsApp suffix from a phone number.
     * WhatsApp sometimes sends numbers as "628123456789@s.whatsapp.net".
     */
    public function stripWaSuffix(string $phone): string
    {
        return preg_replace('/@.*$/', '', $phone);
    }

    /**
     * Extract and normalize phone from a WhatsApp sender identifier.
     */
    public function normalizeWaSender(string $waSender): string
    {
        $stripped = $this->stripWaSuffix($waSender);
        return $this->toE164($stripped);
    }
}
