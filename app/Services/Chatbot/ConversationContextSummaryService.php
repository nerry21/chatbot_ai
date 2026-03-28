<?php

namespace App\Services\Chatbot;

use Illuminate\Support\Str;

class ConversationContextSummaryService
{
    /**
     * @param  array<string, mixed>  $resolvedContext
     */
    public function summarize(
        ?string $storedSummary,
        array $resolvedContext = [],
        int $omittedMessageCount = 0,
        bool $adminTakeover = false,
    ): ?string {
        $segments = [];
        $summary = $this->normalizeText($storedSummary);

        if ($summary !== null) {
            $segments[] = Str::limit($summary, 160, '...');
        }

        $route = $this->summarizeRoute($resolvedContext);
        if ($route !== null) {
            $segments[] = $route;
        }

        $tripTiming = $this->summarizeTripTiming($resolvedContext);
        if ($tripTiming !== null) {
            $segments[] = $tripTiming;
        }

        $activeIntent = $this->normalizeText($resolvedContext['active_intent'] ?? null);
        if ($activeIntent !== null) {
            $segments[] = 'Intent aktif: '.$activeIntent.'.';
        }

        $expectedInput = $this->normalizeText($resolvedContext['expected_input'] ?? null);
        if ($expectedInput !== null) {
            $segments[] = 'Data berikutnya: '.$expectedInput.'.';
        }

        if ($omittedMessageCount > 0) {
            $segments[] = 'Ada '.$omittedMessageCount.' pesan lama yang sudah diringkas.';
        }

        if ($adminTakeover) {
            $segments[] = 'Admin takeover aktif.';
        }

        $segments = array_values(array_unique(array_filter($segments)));

        if ($segments === []) {
            return null;
        }

        return Str::limit(implode(' ', $segments), 260, '...');
    }

    /**
     * @param  array<string, mixed>  $resolvedContext
     */
    private function summarizeRoute(array $resolvedContext): ?string
    {
        $origin = $this->normalizeText($resolvedContext['last_origin'] ?? null);
        $destination = $this->normalizeText($resolvedContext['last_destination'] ?? null);

        if ($origin !== null && $destination !== null) {
            return 'Rute terakhir: '.$origin.' ke '.$destination.'.';
        }

        if ($destination !== null) {
            return 'Tujuan terakhir: '.$destination.'.';
        }

        if ($origin !== null) {
            return 'Titik jemput terakhir: '.$origin.'.';
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $resolvedContext
     */
    private function summarizeTripTiming(array $resolvedContext): ?string
    {
        $parts = [];
        $date = $this->normalizeText($resolvedContext['last_travel_date'] ?? null);
        $time = $this->normalizeText($resolvedContext['last_departure_time'] ?? null);

        if ($date !== null) {
            $parts[] = 'tanggal '.$date;
        }

        if ($time !== null) {
            $parts[] = 'jam '.$time;
        }

        if ($parts === []) {
            return null;
        }

        return 'Konteks perjalanan: '.implode(', ', $parts).'.';
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}
