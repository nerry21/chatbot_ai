<?php

namespace Tests\Unit;

use App\Services\Booking\TimeGreetingService;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class TimeGreetingServiceTest extends TestCase
{
    public function test_it_uses_wib_boundaries_for_time_greetings(): void
    {
        $service = app(TimeGreetingService::class);

        $this->assertSame('Selamat pagi Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 05:00:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat pagi Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 11:00:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat siang Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 11:01:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat siang Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 15:00:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat sore Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 15:01:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat sore Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 18:00:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat malam Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 18:01:00', 'Asia/Jakarta'))['label']);
        $this->assertSame('Selamat malam Bapak/Ibu', $service->resolve(Carbon::parse('2026-03-27 04:59:00', 'Asia/Jakarta'))['label']);
    }
}
