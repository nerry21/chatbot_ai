<?php

namespace Tests\Unit;

use App\Enums\BookingFlowState;
use App\Models\BookingRequest;
use App\Services\Booking\BookingReplyNaturalizerService;
use Tests\TestCase;

class BookingReplyNaturalizerServiceTest extends TestCase
{
    public function test_it_uses_natural_admin_tone_when_asking_basic_details(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->askBasicDetails(
            ['pickup_location', 'passenger_name', 'passenger_count'],
            ['destination' => 'Pekanbaru'],
        );

        $this->assertMatchesRegularExpression('/^(Baik|Siap|Oke),/u', $text);
        $this->assertStringContainsString('titik jemput', $text);
        $this->assertStringContainsString('nama penumpang', $text);
        $this->assertStringContainsString('jumlah penumpang', $text);
        $this->assertStringNotContainsString('Bapak/Ibu', $text);
    }

    public function test_it_keeps_pending_acknowledgement_natural_and_not_repetitive(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $pendingPrompt = $service->askBasicDetails(
            ['pickup_location', 'passenger_name'],
            ['destination' => 'Pekanbaru'],
        );

        $text = $service->inProgressAcknowledgement($pendingPrompt);

        $this->assertStringContainsString('kalau sudah siap', mb_strtolower($text, 'UTF-8'));
        $this->assertStringNotContainsString('saya bantu ya. Supaya', $text);
        $this->assertStringContainsString('titik jemput', $text);
    }

    public function test_it_builds_a_warm_review_summary_without_formal_wording(): void
    {
        $service = app(BookingReplyNaturalizerService::class);
        $booking = BookingRequest::make([
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'payment_method' => 'transfer',
            'price_estimate' => 300000,
        ]);

        $text = $service->reviewSummary($booking);

        $this->assertStringContainsString('data perjalanannya', $text);
        $this->assertStringContainsString('balas YA atau BENAR', $text);
        $this->assertStringContainsString('kirim', $text);
        $this->assertStringNotContainsString('Bapak/Ibu', $text);
    }

    public function test_it_naturalizes_rule_reply_into_a_compact_follow_up_message(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->naturalizeRuleReply(
            capturedUpdates: [
                'pickup_location' => 'Pasir Pengaraian',
                'passenger_name' => 'Nerry',
                'passenger_count' => 2,
            ],
            correctionLines: [],
            prompt: 'Boleh kirim tanggal keberangkatannya ya?',
            routeLine: 'Rute Pasir Pengaraian ke Pekanbaru tersedia ya.',
        );

        $this->assertStringContainsString('Pasir Pengaraian', $text);
        $this->assertStringContainsString('Nerry', $text);
        $this->assertStringContainsString('2 orang', $text);
        $this->assertStringContainsString('tersedia ya', $text);
        $this->assertStringContainsString('Tinggal mohon kirim tanggal keberangkatannya ya.', $text);
        $this->assertStringNotContainsString('- titik jemput:', $text);
    }

    public function test_it_naturalizes_unsupported_route_reply_without_losing_the_rule_result(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->naturalizeUnsupportedRuleReply(
            capturedUpdates: ['pickup_location' => 'Panam'],
            correctionLines: [],
            unsupportedReply: 'Mohon maaf ya, untuk penjemputan dari Panam ke Pekanbaru saat ini belum tersedia. Kalau mau lanjut, silakan kirim titik jemput lain yang tersedia ya. Nanti saya bantu lanjutkan.',
        );

        $this->assertStringContainsString('Panam', $text);
        $this->assertStringContainsString('belum tersedia', $text);
        $this->assertStringContainsString('titik jemput lain', $text);
        $this->assertStringContainsString('saya catat', $text);
    }

    public function test_it_builds_a_state_based_fallback_for_collecting_schedule(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->fallbackForState(
            state: BookingFlowState::CollectingSchedule->value,
            slots: [
                'pickup_location' => 'Pasir Pengaraian',
                'destination' => 'Pekanbaru',
            ],
            pendingPrompt: 'Boleh kirim tanggal keberangkatannya ya?',
        );

        $this->assertStringContainsString('belum menangkap detailnya dengan jelas', mb_strtolower($text, 'UTF-8'));
        $this->assertStringContainsString('tanggal keberangkatan', $text);
    }

    public function test_it_builds_a_state_based_fallback_for_route_unavailable(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->fallbackForState(
            state: BookingFlowState::RouteUnavailable->value,
            routeIssue: 'pickup_location',
            routeSuggestions: ['Pasir Pengaraian', 'Kabun'],
        );

        $this->assertStringContainsString('titik jemput lain yang tersedia', $text);
        $this->assertStringContainsString('Pasir Pengaraian', $text);
    }

    public function test_it_builds_a_general_fallback_based_on_user_signal(): void
    {
        $service = app(BookingReplyNaturalizerService::class);

        $text = $service->generalFallback(['price_keyword' => true]);

        $this->assertStringContainsString('cek harga', mb_strtolower($text, 'UTF-8'));
        $this->assertStringContainsString('titik jemput', $text);
        $this->assertStringContainsString('tujuan', $text);
    }
}
