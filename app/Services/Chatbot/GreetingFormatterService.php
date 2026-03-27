<?php

namespace App\Services\Chatbot;

class GreetingFormatterService
{
    private const ISLAMIC_GREETING = 'Waalaikumsalam warahmatullahi wabarakatuh';

    public function __construct(
        private readonly ResponseVariationService $variation,
    ) {}

    public function opening(string $timeGreetingLabel, bool $includeIslamicGreeting = false, ?string $seed = null): string
    {
        $text = $this->variation->pick([
            $timeGreetingLabel.'. Semoga hari ini membawa berkah dan rahmat. Izin Bapak/Ibu, kalau boleh tahu ada keperluan apa menghubungi JET (Jasa Executive Travel)?',
            $timeGreetingLabel.'. Semoga urusannya lancar hari ini. Izin Bapak/Ibu, ada yang bisa kami bantu untuk perjalanannya?',
            $timeGreetingLabel.'. Semoga harinya baik dan penuh berkah. Izin Bapak/Ibu, keperluannya apa ya, biar kami bantu cek?',
        ], $seed);

        return $includeIslamicGreeting
            ? self::ISLAMIC_GREETING."\n\n".$text
            : $text;
    }

    public function followUp(bool $includeIslamicGreeting = false, ?string $seed = null): string
    {
        $text = $this->variation->pick([
            'Ada yang bisa kami bantu, Bapak/Ibu?',
            'Silakan, ada yang ingin dibantu untuk perjalanannya, Bapak/Ibu?',
            'Baik Bapak/Ibu, ada yang bisa kami bantu lagi?',
        ], $seed);

        return $includeIslamicGreeting
            ? self::ISLAMIC_GREETING."\n\n".$text
            : $text;
    }

    public function prependIslamicGreeting(string $replyText): string
    {
        $replyText = trim($replyText);

        if ($replyText === '') {
            return self::ISLAMIC_GREETING;
        }

        if (str_starts_with($this->normalize($replyText), $this->normalize(self::ISLAMIC_GREETING))) {
            return $replyText;
        }

        return self::ISLAMIC_GREETING."\n\n".$replyText;
    }

    private function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }
}
