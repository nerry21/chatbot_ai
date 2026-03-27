<?php

namespace App\Services\Booking;

use Illuminate\Support\Carbon;

class TimeGreetingService
{
    /**
     * @return array{key: string, label: string}
     */
    public function resolve(?Carbon $now = null): array
    {
        $moment = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone());
        $minuteOfDay = ((int) $moment->format('H') * 60) + (int) $moment->format('i');

        if ($minuteOfDay >= 300 && $minuteOfDay <= 660) {
            return $this->payload('pagi', 'Selamat pagi Bapak/Ibu');
        }

        if ($minuteOfDay >= 661 && $minuteOfDay <= 900) {
            return $this->payload('siang', 'Selamat siang Bapak/Ibu');
        }

        if ($minuteOfDay >= 901 && $minuteOfDay <= 1080) {
            return $this->payload('sore', 'Selamat sore Bapak/Ibu');
        }

        return $this->payload('malam', 'Selamat malam Bapak/Ibu');
    }

    public function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }

    /**
     * @return array{key: string, label: string}
     */
    private function payload(string $key, string $label): array
    {
        return [
            'key' => $key,
            'label' => $label,
        ];
    }
}
