<?php

namespace Tests\Feature;

use App\Enums\BookingFlowState;
use App\Enums\ConversationStatus;
use App\Enums\IntentType;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\BookingRequest;
use App\Models\BookingSeatReservation;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Booking\BookingConversationStateService;
use App\Services\Booking\BookingFlowStateMachine;
use App\Services\Booking\SeatAvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BookingFlowStateMachineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_it_asks_passenger_count_first_when_customer_starts_booking(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking travel'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertStringContainsString('untuk keberangkatan ini ada berapa orang penumpangnya', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertSame(BookingFlowState::AskingPassengerCount->value, $slots['booking_intent_status']);
        $this->assertSame('passenger_count', $stateService->expectedInput($conversation->fresh()));
        $this->assertSame('interactive', $reply['reply']['message_type']);
    }

    public function test_it_asks_for_departure_date_and_time_together_after_passenger_count(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertSame(BookingFlowState::AskingDepartureDate->value, $stateService->load($conversation->fresh())['booking_intent_status']);
        $this->assertSame('travel_date', $stateService->expectedInput($conversation->fresh()));
        $this->assertContainsAny(
            mb_strtolower($reply['reply']['text'], 'UTF-8'),
            [
                'tanggal berapa dan jam berapa',
                'tanggal berapa dan pilih jam yang mana',
            ],
        );
        $this->assertStringContainsString('Subuh (05.00 WIB)', $reply['reply']['text']);
        $this->assertSame('interactive', $reply['reply']['message_type']);
    }

    public function test_it_skips_reasking_date_and_time_when_customer_sends_them_in_one_message(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '2 orang besok jam 08.00'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('ketersediaan seat', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertSame(BookingFlowState::ShowingAvailableSeats->value, $stateService->load($conversation->fresh())['booking_intent_status']);
        $this->assertSame('selected_seats', $stateService->expectedInput($conversation->fresh()));
    }

    public function test_it_moves_through_the_new_jet_booking_sequence_until_review_and_confirmation(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'besok'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $timeReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '08.00'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('ketersediaan seat', mb_strtolower($timeReply['reply']['text'], 'UTF-8'));
        $this->assertSame('selected_seats', $stateService->expectedInput($conversation->fresh()));
        $this->assertSame(BookingFlowState::ShowingAvailableSeats->value, $stateService->load($conversation->fresh())['booking_intent_status']);

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'CC, BS'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Pasir Pengaraian'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Jl Sudirman No 1'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $destinationReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Pekanbaru'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('ongkos rute Pasir Pengaraian ke Pekanbaru', $destinationReply['reply']['text']);
        $this->assertSame('destination_full_address', $stateService->expectedInput($conversation->fresh()));
        $booking = BookingRequest::query()->where('conversation_id', $conversation->id)->latest()->first();
        $this->assertSame('pasir pengaraian__pekanbaru', $booking?->trip_key);
        $this->assertSame(['pasir pengaraian__pekanbaru', 'pasir pengaraian__pekanbaru'], $booking?->seatReservations()->orderBy('seat_code')->pluck('trip_key')->all());

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Jl Tuanku Tambusai No 5'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertSame('passenger_name', $stateService->expectedInput($conversation->fresh()));

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Andi, Budi'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $contactReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'sama'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $readySlots = $stateService->load($conversation->fresh());

        $this->assertTrue($readySlots['review_sent']);
        $this->assertSame(BookingFlowState::AwaitingFinalConfirmation->value, $readySlots['booking_intent_status']);
        $this->assertSame('final_confirmation', $stateService->expectedInput($conversation->fresh()));
        $this->assertSame('+6281234567890', $readySlots['contact_number']);
        $this->assertSame('Jl Tuanku Tambusai No 5', $readySlots['destination_full_address']);
        $this->assertNotNull($readySlots['review_hash']);
        $this->assertContainsAny(
            mb_strtolower($contactReply['reply']['text'], 'UTF-8'),
            [
                'review booking perjalanannya',
                'saya rangkum dulu data perjalanannya',
                'berikut ringkasan bookingnya',
            ],
        );
        $this->assertStringContainsString('No HP', $contactReply['reply']['text']);
        $this->assertStringContainsString('Total ongkos', $contactReply['reply']['text']);
        $this->assertStringContainsString('Alamat tujuan antar', $contactReply['reply']['text']);
        $this->assertStringContainsString('Jl Tuanku Tambusai No 5', $contactReply['reply']['text']);
        $this->assertSame('interactive', $contactReply['reply']['message_type']);

        $confirmedReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'benar'),
            intentResult: ['intent' => 'booking_confirm', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $confirmedSlots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::Completed->value, $confirmedSlots['booking_intent_status']);
        $this->assertTrue($confirmedSlots['booking_confirmed']);
        $this->assertTrue($confirmedSlots['final_confirmation_received']);
        $this->assertNull($stateService->expectedInput($conversation->fresh()));
        $this->assertStringContainsString('data sudah kami terima', mb_strtolower($confirmedReply['reply']['text'], 'UTF-8'));
    }

    public function test_it_uses_waalaikumsalam_for_islamic_greeting_only(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'Assalamualaikum'),
            intentResult: ['intent' => 'greeting', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringStartsWith('Waalaikumsalam warahmatullahi wabarakatuh', $reply['reply']['text']);
        $this->assertStringContainsString('Selamat pagi Bapak/Ibu.', $reply['reply']['text']);
        $this->assertContainsAny($reply['reply']['text'], [
            'Semoga hari ini membawa berkah dan rahmat.',
            'Semoga urusannya lancar hari ini.',
            'Semoga harinya baik dan penuh berkah.',
        ]);
        $this->assertContainsAny($reply['reply']['text'], [
            'kalau boleh tahu ada keperluan apa menghubungi JET (Jasa Executive Travel)?',
            'ada yang bisa kami bantu untuk perjalanannya?',
            'keperluannya apa ya, biar kami bantu cek?',
        ]);
    }

    public function test_it_does_not_repeat_full_review_while_waiting_for_final_confirmation(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $stateService->putMany($conversation, [
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Sudirman No 1',
            'destination' => 'Pekanbaru',
            'destination_full_address' => 'Jl Tuanku Tambusai No 5',
            'passenger_name' => 'Andi',
            'passenger_names' => ['Andi'],
            'passenger_count' => 1,
            'travel_date' => '2026-03-28',
            'travel_time' => '08:00',
            'selected_seats' => ['CC'],
            'contact_number' => '+6281234567890',
            'route_status' => 'supported',
            'fare_amount' => 150000,
            'review_sent' => true,
            'review_hash' => 'hash-review-1',
        ], 'test_final_confirmation_pending');
        $stateService->transitionFlowState($conversation, BookingFlowState::AwaitingFinalConfirmation, 'final_confirmation', 'test_final_confirmation_pending');

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'masih bingung'),
            intentResult: ['intent' => 'confirmation', 'confidence' => 0.90],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertSame('await_final_confirmation', $reply['booking_decision']['action']);
        $this->assertStringContainsString('pilih benar atau ubah data', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertStringNotContainsString('Tanggal keberangkatan', $reply['reply']['text']);
    }

    public function test_it_does_not_copy_destination_address_into_pickup_address(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $stateService->putMany($conversation, [
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Sudirman No 1',
            'destination' => 'Pekanbaru',
            'passenger_count' => 1,
            'travel_date' => '2026-03-28',
            'travel_time' => '08:00',
            'selected_seats' => ['CC'],
            'route_status' => 'supported',
            'fare_amount' => 150000,
        ], 'test_destination_address_mapping');
        $stateService->transitionFlowState(
            $conversation,
            BookingFlowState::AskingDropoffPoint,
            'destination_full_address',
            'test_destination_address_mapping',
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Alamat tujuan antar: Jl Tuanku Tambusai No 5'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame('Jl Sudirman No 1', $slots['pickup_full_address']);
        $this->assertSame('Jl Tuanku Tambusai No 5', $slots['destination_full_address']);
    }

    public function test_it_does_not_repeat_the_full_opening_greeting_in_the_same_session(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'Assalamualaikum'),
            intentResult: ['intent' => 'greeting', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Assalamualaikum'),
            intentResult: ['intent' => 'greeting', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringStartsWith('Waalaikumsalam warahmatullahi wabarakatuh', $reply['reply']['text']);
        $this->assertContainsAny($reply['reply']['text'], [
            'Ada yang bisa kami bantu, Bapak/Ibu?',
            'Silakan, ada yang ingin dibantu untuk perjalanannya, Bapak/Ibu?',
            'Baik Bapak/Ibu, ada yang bisa kami bantu lagi?',
        ]);
        $this->assertStringNotContainsString('Semoga', $reply['reply']['text']);
    }

    public function test_it_keeps_specific_islamic_questions_short_without_repeating_the_full_opening_script(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, "Assalamu'alaikum jadwal hari ini ada?"),
            intentResult: ['intent' => 'schedule_inquiry', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringStartsWith('Waalaikumsalam warahmatullahi wabarakatuh', $reply['reply']['text']);
        $this->assertStringContainsString('keberangkatan', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertStringNotContainsString('Semoga hari ini membawa berkah dan rahmat.', $reply['reply']['text']);
        $this->assertStringNotContainsString('kalau boleh tahu ada keperluan apa', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertSame(BookingFlowState::Idle->value, $stateService->load($conversation->fresh())['booking_intent_status']);
    }

    public function test_it_keeps_today_departure_questions_as_information_instead_of_starting_booking(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'Keberangkatan hari ini ada jam berapa?'),
            intentResult: ['intent' => 'schedule_inquiry', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertStringContainsString('keberangkatan', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertSame(IntentType::TanyaKeberangkatanHariIni->value, $reply['intent_result']['intent']);
        $this->assertSame(BookingFlowState::Idle->value, $stateService->load($conversation->fresh())['booking_intent_status']);
    }

    public function test_it_offers_another_departure_time_when_remaining_seats_are_not_enough(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-27 08:00:00', 'Asia/Jakarta'));

        [$customer, $conversation] = $this->makeConversation();
        [$otherCustomer, $otherConversation] = $this->makeConversation('+6281234567891');
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $blockingBooking = BookingRequest::create([
            'conversation_id' => $otherConversation->id,
            'customer_id' => $otherCustomer->id,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'passenger_count' => 5,
            'booking_status' => 'draft',
        ]);

        foreach (['CC', 'BS', 'Tengah', 'Belakang Kiri', 'Belakang Kanan'] as $seatCode) {
            BookingSeatReservation::create([
                'booking_request_id' => $blockingBooking->id,
                'departure_date' => '2026-03-28',
                'departure_time' => '08:00',
                'trip_key' => SeatAvailabilityService::GLOBAL_TRIP_KEY,
                'seat_code' => $seatCode,
                'expires_at' => now()->addMinutes(30),
            ]);
        }

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '2'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'besok'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '08.00'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertStringContainsString('seat yang tersedia saat ini belum cukup', mb_strtolower($reply['reply']['text'], 'UTF-8'));
        $this->assertStringContainsString('Pagi (10.00 WIB)', $reply['reply']['text']);
        $this->assertSame(BookingFlowState::AskingDepartureTime->value, $slots['booking_intent_status']);
        $this->assertSame('travel_time', $stateService->expectedInput($conversation->fresh()));
    }

    public function test_it_escalates_unknown_questions_to_admin_takeover_state(): void
    {
        Queue::fake();
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'bisa bantu soal invoice hotel?'),
            intentResult: ['intent' => 'unknown', 'confidence' => 0.20],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame(
            'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Izin kami konsultasikan dahulu ya.',
            $reply['reply']['text'],
        );
        $this->assertTrue($slots['waiting_admin_takeover']);
        $this->assertTrue($slots['needs_human_escalation']);
    }

    public function test_it_can_choose_ai_compose_action_without_prebuilt_reply(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);

        $reply = $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'apakah tersedia untuk saya?'),
            intentResult: [
                'intent' => IntentType::ScheduleInquiry->value,
                'confidence' => 0.88,
                'reasoning_short' => 'Customer menanyakan ketersediaan secara umum.',
                'needs_clarification' => false,
                'handoff_recommended' => false,
            ],
            entityResult: [],
            replyResult: [],
        );

        $this->assertSame('compose_ai_reply', $reply['booking_decision']['action']);
        $this->assertSame('ai_reply', $reply['reply']['meta']['source']);
        $this->assertTrue($reply['reply']['meta']['requires_composition']);
        $this->assertSame('', $reply['reply']['text']);
    }

    public function test_acknowledgement_does_not_close_an_active_booking_step(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'oke'),
            intentResult: ['intent' => 'confirmation', 'confidence' => 0.70],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::AskingPassengerCount->value, $slots['booking_intent_status']);
        $this->assertSame('passenger_count', $stateService->expectedInput($conversation->fresh()));
        $this->assertContainsAny(
            mb_strtolower($reply['reply']['text'], 'UTF-8'),
            [
                'kami tunggu jumlah penumpangnya',
                'tinggal dibantu jumlah penumpangnya',
            ],
        );
        $this->assertStringNotContainsString('untuk keberangkatan ini ada berapa orang penumpangnya', mb_strtolower($reply['reply']['text'], 'UTF-8'));
    }

    public function test_gratitude_can_close_an_active_booking_step_without_repeating_the_prompt(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'makasih'),
            intentResult: ['intent' => 'close_intent', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertSame(BookingFlowState::AskingPassengerCount->value, $slots['booking_intent_status']);
        $this->assertTrue($reply['reply']['meta']['close_conversation'] ?? false);
        $this->assertContainsAny(
            mb_strtolower($reply['reply']['text'], 'UTF-8'),
            [
                'terima kasih',
                'tinggal chat kami kembali',
                'kami siap bantu di wa ini',
            ],
        );
        $this->assertStringNotContainsString('untuk keberangkatan ini ada berapa orang penumpangnya', mb_strtolower($reply['reply']['text'], 'UTF-8'));
    }

    public function test_it_marks_six_passengers_as_manual_confirmation_case(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $reply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '6'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $slots = $stateService->load($conversation->fresh());

        $this->assertTrue($slots['needs_manual_confirmation']);
        $this->assertStringContainsString('6 penumpang perlu kami bantu konfirmasi dahulu ke admin', mb_strtolower($reply['reply']['text'], 'UTF-8'));
    }

    public function test_it_does_not_create_a_new_booking_when_customer_repeats_confirmation_after_completion(): void
    {
        [$customer, $conversation] = $this->makeConversation();
        $flow = app(BookingFlowStateMachine::class);
        $stateService = app(BookingConversationStateService::class);

        $flow->handle(
            conversation: $conversation,
            customer: $customer,
            message: $this->inboundMessage($conversation, 'saya mau booking'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), '2 orang besok jam 08.00'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'CC, BS'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Pasir Pengaraian'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Jl Sudirman No 1'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Pekanbaru'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Jl Tuanku Tambusai No 5'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'Andi, Budi'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'sama'),
            intentResult: ['intent' => 'booking', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'benar'),
            intentResult: ['intent' => 'booking_confirm', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertSame(1, BookingRequest::query()->count());
        $this->assertSame(1, BookingRequest::query()->where('booking_status', 'confirmed')->count());
        $this->assertSame(BookingFlowState::Completed->value, $stateService->load($conversation->fresh())['booking_intent_status']);

        $repeatReply = $flow->handle(
            conversation: $conversation->fresh(),
            customer: $customer->fresh(),
            message: $this->inboundMessage($conversation->fresh(), 'benar'),
            intentResult: ['intent' => 'booking_confirm', 'confidence' => 0.95],
            entityResult: [],
            replyResult: ['text' => '', 'is_fallback' => true],
        );

        $this->assertSame(1, BookingRequest::query()->count());
        $this->assertSame(1, BookingRequest::query()->where('booking_status', 'confirmed')->count());
        $this->assertStringContainsString('terima kasih', mb_strtolower($repeatReply['reply']['text'], 'UTF-8'));
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(string $phone = '+6281234567890'): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => $phone,
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

    /**
     * @param  array<int, string>  $needles
     */
    private function assertContainsAny(string $haystack, array $needles): void
    {
        $this->assertTrue(
            collect($needles)->contains(fn (string $needle): bool => str_contains($haystack, $needle)),
            'Failed asserting that the string contains any expected fragment. Haystack: '.$haystack,
        );
    }
}
