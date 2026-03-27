<?php

namespace App\Services\Chatbot;

class GreetingDetectorService
{
    /**
     * @return array{
     *     has_islamic_greeting: bool,
     *     has_general_greeting: bool,
     *     greeting_only: bool
     * }
     */
    public function inspect(string $messageText): array
    {
        $normalized = $this->normalize($messageText);
        $hasIslamicGreeting = $this->hasIslamicGreeting($messageText);
        $hasGeneralGreeting = $hasIslamicGreeting || $this->hasGeneralGreeting($messageText);

        return [
            'has_islamic_greeting' => $hasIslamicGreeting,
            'has_general_greeting' => $hasGeneralGreeting,
            'greeting_only' => $hasGeneralGreeting
                && ! preg_match('/\b(harga|ongkos|jadwal|pesan|booking|berangkat|keberangkatan|jemput|antar|seat|kursi|rute|mobil|travel|tujuan)\b/u', $normalized),
        ];
    }

    public function hasIslamicGreeting(string $messageText): bool
    {
        $normalized = $this->normalize($messageText);
        $compact = str_replace(' ', '', $normalized);

        foreach ([
            'assalamualaikum',
            'asalamualaikum',
            'assalamuallaikum',
            'salamualaikum',
        ] as $phrase) {
            if (str_starts_with($compact, $phrase)) {
                return true;
            }
        }

        return (bool) preg_match('/^(?:ass|as)\s*wr\s*wb\b/u', $normalized)
            || (bool) preg_match('/^(?:ass|as)\s*w\s*r\s*w\s*b\b/u', $normalized)
            || (bool) preg_match('/^(?:ass|as)\s*wrwb\b/u', $normalized);
    }

    public function hasGeneralGreeting(string $messageText): bool
    {
        $normalized = $this->normalize($messageText);

        foreach ([
            'halo',
            'hai',
            'hello',
            'hi',
            'salam',
            'selamat pagi',
            'selamat siang',
            'selamat sore',
            'selamat malam',
            'pagi',
            'siang',
            'sore',
            'malam',
        ] as $phrase) {
            if ($normalized === $phrase || str_starts_with($normalized, $phrase.' ')) {
                return true;
            }
        }

        return false;
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $normalized = str_replace(["\u{2019}", "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s.]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }
}
