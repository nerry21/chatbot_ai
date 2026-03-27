<?php

namespace App\Services\Booking;

use Illuminate\Support\Carbon;

class TimeGreetingService
{
    /**
     * @return array{key: string, label: string, opening: string}
     */
    public function resolve(?Carbon $now = null): array
    {
        $moment = ($now ?? Carbon::now())->copy()->setTimezone($this->timezone());
        $minuteOfDay = ((int) $moment->format('H') * 60) + (int) $moment->format('i');

        if ($minuteOfDay >= 300 && $minuteOfDay <= 660) {
            return [
                'key'     => 'pagi',
                'label'   => 'Selamat pagi',
                'opening' => 'Selamat pagi Bapak/Ibu, semoga hari ini membawa berkah dan rahmat. Izin Bapak/Ibu, boleh saya bantu ada keperluan apa menghubungi JET (Jasa Executive Travel)?',
            ];
        }

        if ($minuteOfDay >= 661 && $minuteOfDay <= 900) {
            return [
                'key'     => 'siang',
                'label'   => 'Selamat siang',
                'opening' => 'Selamat siang Bapak/Ibu, semoga hari ini lancar dan penuh berkah. Izin Bapak/Ibu, ada yang bisa kami bantu terkait perjalanan bersama JET?',
            ];
        }

        if ($minuteOfDay >= 901 && $minuteOfDay <= 1080) {
            return [
                'key'     => 'sore',
                'label'   => 'Selamat sore',
                'opening' => 'Selamat sore Bapak/Ibu, semoga aktivitas hari ini berjalan baik. Izin Bapak/Ibu, ada keperluan apa yang bisa kami bantu dari JET?',
            ];
        }

        return [
            'key'     => 'malam',
            'label'   => 'Selamat malam',
            'opening' => 'Selamat malam Bapak/Ibu, semoga malam ini tetap diberi kesehatan dan kelancaran. Izin Bapak/Ibu, ada yang bisa kami bantu terkait perjalanan JET?',
        ];
    }

    public function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }
}
