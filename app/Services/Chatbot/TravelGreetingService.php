<?php

namespace App\Services\Chatbot;

use App\Services\Booking\TimeGreetingService;
use Carbon\Carbon;

/**
 * TravelGreetingService
 *
 * Thin facade around the existing GreetingDetectorService + TimeGreetingService
 * that exposes a simple, stateless API for building opening greetings.
 *
 * All Islamic-greeting detection logic lives in GreetingDetectorService so that
 * it stays in sync with the main pipeline.  Time-based greeting logic lives in
 * TimeGreetingService (reads chatbot.jet.timezone).
 */
class TravelGreetingService
{
    public function __construct(
        private readonly GreetingDetectorService $detector,
        private readonly TimeGreetingService $timeGreeting,
    ) {}

    /**
     * Build a full opening greeting for the first inbound message.
     *
     * Returns e.g.:
     *   "Waalaikumsalam warahmatullahi wabarakatuh\n\nSelamat pagi Bapak/Ibu. Ada yang bisa kami bantu?"
     *
     * or without Islamic prefix:
     *   "Selamat sore Bapak/Ibu. Ada yang bisa kami bantu?"
     */
    public function buildOpeningGreeting(string $incomingText, ?Carbon $now = null): string
    {
        $hasIslamic = $this->detector->hasIslamicGreeting($incomingText);
        $time = $this->timeGreeting->resolve($now);

        $parts = [];

        if ($hasIslamic) {
            $parts[] = 'Waalaikumsalam warahmatullahi wabarakatuh';
        }

        $parts[] = $time['label'].'. Ada yang bisa kami bantu untuk perjalanannya, Bapak/Ibu?';

        return implode("\n\n", $parts);
    }

    /**
     * Whether the incoming text contains an Islamic greeting.
     * Delegates to GreetingDetectorService so detection stays consistent.
     */
    public function shouldReplyIslamicGreeting(string $incomingText): bool
    {
        return $this->detector->hasIslamicGreeting($incomingText);
    }

    /**
     * Return the time-based greeting label for the current moment.
     *
     * @return array{key: string, label: string}
     */
    public function getTimeGreeting(?Carbon $now = null): array
    {
        return $this->timeGreeting->resolve($now);
    }

    /**
     * Return just the label text, e.g. "Selamat pagi Bapak/Ibu".
     */
    public function getTimeBasedGreeting(?Carbon $now = null): string
    {
        return $this->timeGreeting->resolve($now)['label'];
    }

    /**
     * Return the greeting period key, e.g. "pagi" | "siang" | "sore" | "malam".
     */
    public function getGreetingLabel(?Carbon $now = null): string
    {
        return $this->timeGreeting->resolve($now)['key'];
    }
}
