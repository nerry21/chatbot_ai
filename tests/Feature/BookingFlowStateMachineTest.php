<?php

namespace Tests\Feature;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Booking\BookingConversationStateService;
use App\Services\Booking\BookingFlowStateMachine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookingFlowStateMachineTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_context_and_only_asks_missing_slots_after_route_is_completed(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $firstReply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru apakah tersedia?'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('titik jemput', $firstReply['reply']['text']);
        $this->assertStringContainsString('nama penumpang', $firstReply['reply']['text']);
        $this->assertStringContainsString('jumlah penumpang', $firstReply['reply']['text']);
        $firstSlots = $stateService->load($conversation->fresh());
        $this->assertSame('Pekanbaru', $firstSlots['destination']);
        $this->assertSame(BookingFlowState::CollectingRoute->value, $firstSlots['booking_intent_status']);
        $this->assertSame('pickup_location', $stateService->expectedInput($conversation->fresh()));

        $secondReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di pasir pengaraian, nama Nerry, jumlah 2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $freshSlots = $stateService->load($conversation->fresh());

        $this->assertStringContainsString('catat', $secondReply['reply']['text']);
        $this->assertStringContainsString('Pasir Pengaraian ke Pekanbaru', $secondReply['reply']['text']);
        $this->assertStringContainsString('tersedia', $secondReply['reply']['text']);
        $this->assertStringContainsString('tanggal keberangkatan', $secondReply['reply']['text']);
        $this->assertSame('Pasir Pengaraian', $freshSlots['pickup_location']);
        $this->assertSame('Nerry', $freshSlots['passenger_name']);
        $this->assertSame(2, $freshSlots['passenger_count']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $freshSlots['booking_intent_status']);
        $this->assertSame('travel_date', $stateService->expectedInput($conversation->fresh()));
    }

    public function test_it_accepts_route_correction_without_restarting_booking(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru apakah tersedia?'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $unsupportedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'nama Nerry, jumlah 2, titik jemput di panam'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('Panam ke Pekanbaru saat ini belum tersedia', $unsupportedReply['reply']['text']);
        $unsupportedSlots = app(BookingConversationStateService::class)->load($conversation->fresh());
        $this->assertSame(BookingFlowState::RouteUnavailable->value, $unsupportedSlots['booking_intent_status']);
        $this->assertSame('pickup_location', app(BookingConversationStateService::class)->expectedInput($conversation->fresh()));

        $correctedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di pasir pengaraian'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('titik jemput', $correctedReply['reply']['text']);
        $this->assertStringContainsString('Pasir Pengaraian', $correctedReply['reply']['text']);
        $this->assertStringContainsString('tersedia', $correctedReply['reply']['text']);
        $this->assertStringContainsString('tanggal keberangkatan', $correctedReply['reply']['text']);
        $correctedSlots = app(BookingConversationStateService::class)->load($conversation->fresh());
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $correctedSlots['booking_intent_status']);
    }

    public function test_it_hydrates_slot_memory_from_existing_booking_draft_and_only_asks_next_missing_slot(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'destination' => 'Pekanbaru',
            'passenger_name' => 'Nerry',
            'passenger_count' => 2,
            'booking_status' => BookingStatus::Draft,
        ]);

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'lanjut'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame('Pasir Pengaraian', $slots['pickup_location']);
        $this->assertSame('Pekanbaru', $slots['destination']);
        $this->assertSame('Nerry', $slots['passenger_name']);
        $this->assertSame(2, $slots['passenger_count']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $slots['booking_intent_status']);
        $this->assertStringContainsString('tanggal keberangkatan', $reply['reply']['text']);
        $this->assertStringNotContainsString('nama penumpang', mb_strtolower($reply['reply']['text'], 'UTF-8'));
    }

    public function test_it_overwrites_multiple_slots_and_continues_with_latest_values(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru apakah tersedia?'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di panam, nama nerry, jumlah 2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $correctedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di pasir pengaraian, nama andi, jumlah 3'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame('Pasir Pengaraian', $slots['pickup_location']);
        $this->assertSame('Andi', $slots['passenger_name']);
        $this->assertSame(3, $slots['passenger_count']);
        $this->assertSame('supported', $slots['route_status']);
        $this->assertSame(BookingFlowState::CollectingSchedule->value, $slots['booking_intent_status']);
        $this->assertStringContainsString('Pasir Pengaraian', $correctedReply['reply']['text']);
        $this->assertStringContainsString('Andi', $correctedReply['reply']['text']);
        $this->assertStringContainsString('3 orang', $correctedReply['reply']['text']);
        $this->assertStringContainsString('tanggal keberangkatan', $correctedReply['reply']['text']);
        $this->assertStringNotContainsString('Panam', $correctedReply['reply']['text']);
    }

    public function test_it_transitions_to_ready_to_confirm_then_confirmed_when_booking_is_completed(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'jemput di pasir pengaraian, tujuan pekanbaru, nama nerry, 2 orang, besok, jam 08.00, transfer'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $readySlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::ReadyToConfirm->value, $readySlots['booking_intent_status']);
        $this->assertTrue($readySlots['review_sent']);
        $this->assertNull($stateService->expectedInput($conversation->fresh()));

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'ya'),
            intentResult: ['intent' => 'booking_confirm', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $confirmedSlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::Confirmed->value, $confirmedSlots['booking_intent_status']);
        $this->assertTrue($confirmedSlots['booking_confirmed']);
    }

    public function test_it_closes_booking_state_and_can_resume_from_the_correct_step(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'jemput di pasir pengaraian, tujuan pekanbaru, nama nerry, 2 orang'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'terima kasih'),
            intentResult: ['intent' => 'farewell', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $closedSlots = $stateService->load($conversation->fresh());
        $this->assertSame(BookingFlowState::Closed->value, $closedSlots['booking_intent_status']);

        $resumeReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'lanjut'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $resumedSlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::CollectingSchedule->value, $resumedSlots['booking_intent_status']);
        $this->assertStringContainsString('tanggal keberangkatan', $resumeReply['reply']['text']);
    }

    public function test_it_uses_state_based_fallback_while_collecting_schedule(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'jemput di pasir pengaraian, tujuan pekanbaru, nama nerry, 2 orang'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $fallbackReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'kurang paham'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertMatchesRegularExpression(
            '/(belum menangkap detailnya dengan jelas|supaya tidak salah|biar tidak keliru)/u',
            mb_strtolower($fallbackReply['reply']['text'], 'UTF-8'),
        );
        $this->assertStringContainsString('tanggal keberangkatan', $fallbackReply['reply']['text']);
    }

    public function test_it_uses_short_general_fallback_without_booking_context(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'tolong bantu dong'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('saya bantu', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertMatchesRegularExpression(
            '/(rute|jadwal|detail)/u',
            mb_strtolower($reply['reply']['text'], 'UTF-8'),
        );
    }

    public function test_it_uses_route_unavailable_fallback_when_customer_does_not_send_a_new_route(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau ke pekanbaru'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'titik jemput di panam, nama nerry, jumlah 2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $fallbackReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'bagaimana ya'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('titik jemput lain yang tersedia', $fallbackReply['reply']['text']);
        $this->assertStringNotContainsString('Panam ke Pekanbaru saat ini belum tersedia', $fallbackReply['reply']['text']);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$customer, $conversation];
    }

    private function inboundMessage(Conversation $conversation, string $text): ConversationMessage
    {
        return ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => $text,
            'raw_payload' => [],
            'is_fallback' => false,
            'sent_at' => now(),
        ]);
    }
}
