<?php

namespace Tests\Unit;

use App\Services\Chatbot\TravelBookingRuleService;
use Tests\TestCase;

class TravelBookingRuleServiceTest extends TestCase
{
    public function test_it_accepts_extended_confirmation_phrases(): void
    {
        $service = app(TravelBookingRuleService::class);

        foreach ([
            'oke sudah benar',
            'iya sudah sesuai',
            'sip lanjut',
            'ya benar',
            'data sudah sesuai kak',
            'booking sudah benar admin',
            'oke lanjut',
        ] as $text) {
            $this->assertTrue($service->isConfirmationText($text), $text);
        }
    }

    public function test_it_rejects_non_confirmation_phrases(): void
    {
        $service = app(TravelBookingRuleService::class);

        foreach ([
            'tidak',
            'ubah data',
            'belum benar',
            'nanti dulu',
        ] as $text) {
            $this->assertFalse($service->isConfirmationText($text), $text);
        }
    }
}
