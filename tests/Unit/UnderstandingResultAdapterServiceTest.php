<?php

namespace Tests\Unit;

use App\Data\AI\LlmUnderstandingEntities;
use App\Data\AI\LlmUnderstandingResult;
use App\Enums\IntentType;
use App\Services\AI\UnderstandingResultAdapterService;
use Tests\TestCase;

class UnderstandingResultAdapterServiceTest extends TestCase
{
    public function test_it_maps_understanding_result_to_legacy_intent_and_entity_payloads(): void
    {
        $service = app(UnderstandingResultAdapterService::class);
        $result = new LlmUnderstandingResult(
            intent: IntentType::Booking->value,
            subIntent: 'collect_trip_details',
            confidence: 0.92,
            usesPreviousContext: true,
            entities: new LlmUnderstandingEntities(
                origin: 'Pasir Pengaraian',
                destination: 'Pekanbaru',
                travelDate: '2026-03-29',
                departureTime: '10:00',
                passengerCount: 2,
                passengerName: 'Andi',
                seatNumber: 'CC, BS',
                paymentMethod: 'transfer',
            ),
            needsClarification: true,
            clarificationQuestion: 'Untuk alamat jemput lengkapnya di mana ya?',
            handoffRecommended: false,
            reasoningSummary: 'User ingin booking dan sebagian data sudah tersedia.',
        );

        $adapted = $service->adapt($result);

        $this->assertSame(IntentType::Booking->value, $adapted['intent_result']['intent']);
        $this->assertSame('collect_trip_details', $adapted['intent_result']['sub_intent']);
        $this->assertTrue($adapted['intent_result']['needs_clarification']);
        $this->assertSame('Andi', $adapted['entity_result']['customer_name']);
        $this->assertSame('Pasir Pengaraian', $adapted['entity_result']['pickup_location']);
        $this->assertSame('Pekanbaru', $adapted['entity_result']['destination']);
        $this->assertSame(['CC', 'BS'], $adapted['entity_result']['selected_seats']);
        $this->assertSame('transfer', $adapted['entity_result']['payment_method']);
    }

    public function test_it_uses_legacy_fallback_only_when_understanding_failed_totally(): void
    {
        $service = app(UnderstandingResultAdapterService::class);
        $fallback = LlmUnderstandingResult::fallback();

        $this->assertTrue($service->needsLegacyFallback($fallback));

        $adapted = $service->adapt(
            understanding: $fallback,
            legacyIntentResult: [
                'intent' => IntentType::TanyaHarga->value,
                'confidence' => 0.73,
                'reasoning_short' => 'Legacy fallback menangkap pertanyaan harga.',
            ],
            legacyEntityResult: [
                'pickup_location' => 'Pasir Pengaraian',
                'destination' => 'Pekanbaru',
                'missing_fields' => ['departure_date'],
            ],
        );

        $this->assertSame(IntentType::TanyaHarga->value, $adapted['intent_result']['intent']);
        $this->assertSame(0.73, $adapted['intent_result']['confidence']);
        $this->assertSame('Pasir Pengaraian', $adapted['entity_result']['pickup_location']);
        $this->assertTrue($adapted['meta']['used_legacy_intent_fallback']);
        $this->assertTrue($adapted['meta']['used_legacy_entity_fallback']);
    }
}
