<?php

namespace Tests\Unit;

use App\Enums\IntentType;
use App\Services\AI\UnderstandingOutputParserService;
use Tests\TestCase;

class UnderstandingOutputParserServiceTest extends TestCase
{
    public function test_it_parses_and_normalizes_structured_understanding_payload(): void
    {
        $parser = app(UnderstandingOutputParserService::class);

        $result = $parser->parse([
            'intent' => 'booking',
            'sub_intent' => 'new booking',
            'confidence' => '85%',
            'uses_previous_context' => 'true',
            'entities' => [
                'origin' => 'Pasir Pengaraian',
                'destination' => 'Pekanbaru',
                'travel_date' => '2026-03-29',
                'departure_time' => '8.00',
                'passenger_count' => '2',
                'passenger_name' => 'Nerry',
                'seat_number' => ['CC', 'BS'],
                'payment_method' => 'transfer',
            ],
            'needs_clarification' => false,
            'clarification_question' => '',
            'handoff_recommended' => 'false',
            'reasoning_summary' => 'User ingin booking baru dan sudah menyebut sebagian data.',
        ], [IntentType::Booking->value, IntentType::Unknown->value]);

        $this->assertSame(IntentType::Booking->value, $result->intent);
        $this->assertSame('new_booking', $result->subIntent);
        $this->assertSame(0.85, $result->confidence);
        $this->assertTrue($result->usesPreviousContext);
        $this->assertSame('08:00', $result->entities->departureTime);
        $this->assertSame(2, $result->entities->passengerCount);
        $this->assertSame('CC, BS', $result->entities->seatNumber);
        $this->assertFalse($result->needsClarification);
        $this->assertNull($result->clarificationQuestion);
    }

    public function test_it_uses_safe_fallback_when_payload_is_invalid(): void
    {
        $parser = app(UnderstandingOutputParserService::class);

        $result = $parser->parse('bukan json valid sama sekali', [IntentType::Booking->value, IntentType::Unknown->value]);

        $this->assertSame(IntentType::Unknown->value, $result->intent);
        $this->assertSame(0.0, $result->confidence);
        $this->assertTrue($result->needsClarification);
        $this->assertSame('Boleh dijelaskan lagi kebutuhan perjalanannya?', $result->clarificationQuestion);
        $this->assertNull($result->entities->origin);
    }
}
