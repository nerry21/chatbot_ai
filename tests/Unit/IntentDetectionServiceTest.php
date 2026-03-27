<?php

namespace Tests\Unit;

use App\Enums\IntentType;
use App\Services\Chatbot\IntentDetectionService;
use Tests\TestCase;

class IntentDetectionServiceTest extends TestCase
{
    public function test_it_maps_islamic_greeting_only_to_salam_islam_intent(): void
    {
        $service = app(IntentDetectionService::class);

        $intent = $service->detect(
            rawIntentResult: ['intent' => 'greeting', 'confidence' => 0.60],
            messageText: 'Assalamualaikum',
            signals: [
                'close_intent' => false,
                'greeting_only' => true,
                'salam_type' => 'islamic',
                'today_schedule_keyword' => false,
                'affirmation' => false,
                'rejection' => false,
                'change_request' => false,
                'price_keyword' => false,
                'route_keyword' => false,
                'schedule_keyword' => false,
                'booking_keyword' => false,
            ],
            slots: [],
        );

        $this->assertSame(IntentType::SalamIslam->value, $intent['intent']);
    }

    public function test_it_maps_review_confirmation_to_konfirmasi_booking_intent(): void
    {
        $service = app(IntentDetectionService::class);

        $intent = $service->detect(
            rawIntentResult: ['intent' => 'confirmation', 'confidence' => 0.60],
            messageText: 'benar',
            signals: [
                'close_intent' => false,
                'greeting_only' => false,
                'salam_type' => null,
                'today_schedule_keyword' => false,
                'affirmation' => true,
                'rejection' => false,
                'change_request' => false,
                'price_keyword' => false,
                'route_keyword' => false,
                'schedule_keyword' => false,
                'booking_keyword' => false,
            ],
            slots: ['review_sent' => true],
        );

        $this->assertSame(IntentType::KonfirmasiBooking->value, $intent['intent']);
    }

    public function test_it_maps_unknown_fallback_to_pertanyaan_tidak_terjawab(): void
    {
        $service = app(IntentDetectionService::class);

        $intent = $service->detect(
            rawIntentResult: ['intent' => 'unknown', 'confidence' => 0.10],
            messageText: 'bisa bantu invoice hotel?',
            signals: [
                'close_intent' => false,
                'greeting_only' => false,
                'salam_type' => null,
                'today_schedule_keyword' => false,
                'affirmation' => false,
                'rejection' => false,
                'change_request' => false,
                'price_keyword' => false,
                'route_keyword' => false,
                'schedule_keyword' => false,
                'booking_keyword' => false,
            ],
            slots: [],
            updates: [],
            replyResult: ['is_fallback' => true],
        );

        $this->assertSame(IntentType::PertanyaanTidakTerjawab->value, $intent['intent']);
    }
}
