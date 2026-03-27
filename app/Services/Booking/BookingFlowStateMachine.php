<?php

namespace App\Services\Booking;

use App\Enums\BookingStatus;
use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\Chatbot\GreetingService;
use App\Services\Chatbot\HumanEscalationService;

class BookingFlowStateMachine
{
    /**
     * @var array<string, string>
     */
    private const SLOT_LABELS = [
        'passenger_count'     => 'jumlah penumpang',
        'travel_date'         => 'tanggal keberangkatan',
        'travel_time'         => 'jam keberangkatan',
        'selected_seats'      => 'seat',
        'pickup_point'        => 'titik jemput',
        'pickup_full_address' => 'alamat jemput',
        'destination_point'   => 'tujuan antar',
        'passenger_names'     => 'nama penumpang',
        'contact_number'      => 'nomor kontak',
    ];

    public function __construct(
        private readonly BookingAssistantService $bookingAssistant,
        private readonly BookingConversationStateService $stateService,
        private readonly BookingSlotExtractorService $slotExtractor,
        private readonly RouteValidationService $routeValidator,
        private readonly FareCalculatorService $fareCalculator,
        private readonly SeatAvailabilityService $seatAvailability,
        private readonly BookingConfirmationService $confirmationService,
        private readonly TimeGreetingService $timeGreetingService,
        private readonly GreetingService $greetingService,
        private readonly HumanEscalationService $humanEscalationService,
    ) {
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyResult
     * @return array{
     *     handled: bool,
     *     booking: BookingRequest|null,
     *     booking_decision: array<string, mixed>|null,
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>
     * }
     */
    public function handle(
        Conversation $conversation,
        Customer $customer,
        ConversationMessage $message,
        array $intentResult,
        array $entityResult,
        array $replyResult,
    ): array {
        $existingDraft = $this->bookingAssistant->findExistingDraft($conversation);
        $slots = $this->stateService->load($conversation);
        $timeGreeting = $this->timeGreetingService->resolve();
        $expectedInput = $this->stateService->expectedInput($conversation);
        $messageText = trim((string) ($message->message_text ?? ''));

        $this->stateService->putMany($conversation, [
            'time_greeting' => $timeGreeting['label'],
            'admin_takeover' => $conversation->isAdminTakeover(),
        ]);

        $extracted = $this->slotExtractor->extract(
            messageText: $messageText,
            currentSlots: $slots,
            entityResult: $entityResult,
            expectedInput: $expectedInput,
            senderPhone: $customer->phone_e164 ?? '',
        );

        $updates = $extracted['updates'];
        $signals = $extracted['signals'];
        $correctionNotice = $this->buildCorrectionNotice($slots, $updates);

        if ($signals['greeting_detected']) {
            $updates['greeting_detected'] = true;
            $updates['salam_type'] = $signals['salam_type'];
        }

        $hasTravelSignals = $this->hasTravelSignals(
            conversation: $conversation,
            intentResult: $intentResult,
            signals: $signals,
            slots: $slots,
            updates: $updates,
            existingDraft: $existingDraft,
        );

        if (! $hasTravelSignals && $signals['close_intent']) {
            return $this->decision(
                booking: $existingDraft,
                action: 'close_conversation',
                reply: $this->reply(
                    text: $this->withSalamPrefix(
                        $signals,
                        'Baik Bapak/Ibu, terima kasih ya. Jika nanti ingin cek jadwal atau booking lagi, silakan hubungi kami kembali.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'close_conversation', 'close_conversation' => true],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Farewell),
            );
        }

        if (! $hasTravelSignals && $signals['greeting_only']) {
            $this->stateService->putMany($conversation, ['booking_intent_status' => 'idle']);
            $this->stateService->setExpectedInput($conversation, null);
            $openingGreeting = $this->greetingService->buildOpeningGreeting(
                conversation: $conversation,
                messageText: $messageText,
                activeStates: $slots,
            );

            if ($openingGreeting === null) {
                $openingGreeting = $this->buildOpeningGreeting($signals, $timeGreeting['opening'], $messageText);
            }

            return $this->decision(
                booking: $existingDraft,
                action: 'greeting',
                reply: $this->reply(
                    text: $openingGreeting,
                    meta: ['source' => 'jet_flow', 'action' => 'greeting'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Greeting),
            );
        }

        if ($signals['close_intent'] && $updates === [] && ($slots['review_sent'] ?? false) !== true) {
            $this->stateService->putMany($conversation, [
                'booking_intent_status' => 'idle',
                'review_sent' => false,
            ]);
            $this->stateService->setExpectedInput($conversation, null);

            return $this->decision(
                booking: $existingDraft,
                action: 'close_conversation',
                reply: $this->reply(
                    text: $this->withSalamPrefix(
                        $signals,
                        'Baik Bapak/Ibu, terima kasih ya. Jika nanti ingin cek jadwal atau booking lagi, silakan hubungi kami kembali.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'close_conversation', 'close_conversation' => true],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Farewell),
            );
        }

        if ($signals['human_keyword']) {
            $this->markEscalationState($conversation);
            $this->humanEscalationService->escalateQuestion(
                conversation: $conversation,
                customer: $customer,
                reason: 'Permintaan admin manusia dari customer.',
            );

            return $this->decision(
                booking: $existingDraft,
                action: 'human_handoff',
                reply: $this->reply(
                    text: $this->withSalamPrefix(
                        $signals,
                        'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Mohon izin, kami konsultasikan terlebih dahulu ya.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'human_handoff'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::HumanHandoff),
            );
        }

        if ($this->isRouteListInquiry($signals, $messageText) && $existingDraft === null) {
            return $this->decision(
                booking: null,
                action: 'route_list',
                reply: $this->reply(
                    text: $this->withSalamPrefix(
                        $signals,
                        "Berikut titik jemput dan tujuan yang tersedia saat ini:\n\n" . $this->locationMenuText() . "\n\nIzin Bapak/Ibu, jika ingin lanjut booking silakan kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.",
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'route_list'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::LocationInquiry),
            );
        }

        if (($signals['price_keyword'] || IntentType::tryFrom($intentResult['intent'] ?? '') === IntentType::PriceInquiry) && $existingDraft === null) {
            if ($updates !== []) {
                $this->stateService->putMany($conversation, $this->applyDerivedStateUpdates($slots, $updates));
            }
            $priceReply = $this->handleDirectPriceInquiry($signals, $updates);

            if ($priceReply !== null) {
                return $this->decision(
                    booking: null,
                    action: $priceReply['meta']['action'] ?? 'price_inquiry',
                    reply: $priceReply,
                    intentResult: $this->overrideIntent($intentResult, IntentType::PriceInquiry),
                );
            }
        }

        if (($signals['schedule_keyword'] || IntentType::tryFrom($intentResult['intent'] ?? '') === IntentType::ScheduleInquiry) && $existingDraft === null) {
            $continueBooking = $this->shouldStartBooking($signals, $updates);

            if ($updates !== []) {
                $this->stateService->putMany($conversation, array_merge(
                    $this->applyDerivedStateUpdates($slots, $updates),
                    $continueBooking ? ['booking_intent_status' => 'collecting'] : [],
                ));
            }

            if ($continueBooking) {
                $this->stateService->setExpectedInput($conversation, 'passenger_count');
            }

            return $this->decision(
                booking: null,
                action: 'schedule_inquiry',
                reply: $this->buildScheduleReply($signals, $continueBooking),
                intentResult: $this->overrideIntent($intentResult, IntentType::ScheduleInquiry),
            );
        }

        if (! $hasTravelSignals) {
            $this->markEscalationState($conversation);
            $this->humanEscalationService->escalateQuestion(
                conversation: $conversation,
                customer: $customer,
                reason: 'Pertanyaan di luar cakupan flow travel JET.',
            );

            return $this->decision(
                booking: $existingDraft,
                action: 'human_escalation',
                reply: $this->reply(
                    text: $this->withSalamPrefix(
                        $signals,
                        'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Mohon izin, kami konsultasikan terlebih dahulu ya.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'human_escalation'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Support),
            );
        }

        $booking = $existingDraft ?? $this->bookingAssistant->findOrCreateDraft($conversation);

        return $this->handleBookingFlow(
            conversation: $conversation,
            customer: $customer,
            booking: $booking,
            intentResult: $intentResult,
            signals: $signals,
            slots: $slots,
            updates: $updates,
            correctionNotice: $correctionNotice,
            timeGreeting: $timeGreeting,
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @param  array<string, string> $timeGreeting
     * @return array<string, mixed>
     */
    private function handleBookingFlow(
        Conversation $conversation,
        Customer $customer,
        BookingRequest $booking,
        array $intentResult,
        array $signals,
        array $slots,
        array $updates,
        string $correctionNotice,
        array $timeGreeting,
    ): array {
        $updates = $this->applyDerivedStateUpdates($slots, $updates);
        $seatSensitiveChanged = $this->hasAnyKey($updates, ['passenger_count', 'travel_date', 'travel_time']);

        if ($seatSensitiveChanged) {
            $this->seatAvailability->releaseSeats($booking);
        }

        $this->stateService->putMany($conversation, array_merge($updates, [
            'booking_intent_status' => 'collecting',
            'time_greeting' => $timeGreeting['label'],
        ]));
        $slots = array_replace($slots, $updates, [
            'booking_intent_status' => 'collecting',
            'time_greeting' => $timeGreeting['label'],
        ]);
        $booking = $this->stateService->syncBooking($booking, $slots, $customer->phone_e164 ?? '');

        $prefixLines = array_filter([$correctionNotice, $this->manualPassengerNotice($slots)]);

        if (($slots['review_sent'] ?? false) === true && ($signals['affirmation'] ?? false) === true) {
            $this->confirmationService->confirm($booking);
            $this->seatAvailability->confirmSeats($booking);
            $this->stateService->putMany($conversation, [
                'booking_intent_status' => 'confirmed',
                'booking_confirmed' => true,
                'needs_human_escalation' => false,
            ]);
            $this->stateService->setExpectedInput($conversation, null);
            $this->humanEscalationService->forwardBooking($conversation, $customer, $booking);

            return $this->decision(
                booking: $booking->fresh(),
                action: 'confirmed',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        'Baik Bapak/Ibu, data perjalanan sudah kami terima. Kami akan kembali menghubungi Bapak/Ibu melalui kanal WhatsApp ini atau dari Admin Utama.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'confirmed'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::BookingConfirm),
            );
        }

        if (($slots['review_sent'] ?? false) === true && ($signals['rejection'] ?? false) === true && $updates === []) {
            $this->stateService->putMany($conversation, [
                'review_sent' => false,
                'booking_confirmed' => false,
            ]);
            $this->stateService->setExpectedInput($conversation, null);

            return $this->decision(
                booking: $booking,
                action: 'ask_correction',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        'Baik Bapak/Ibu, silakan kirim bagian data yang ingin diubah ya. Saya bantu update satu per satu.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_correction'],
                ),
                intentResult: $intentResult,
            );
        }

        if ($slots['passenger_count'] === null) {
            $this->stateService->setExpectedInput($conversation, 'passenger_count');

            return $this->decision(
                booking: $booking,
                action: 'ask_passenger_count',
                reply: $this->reply(
                    text: $this->prependNotes($prefixLines, 'Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya ya?'),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_passenger_count'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if ((int) $slots['passenger_count'] > (int) config('chatbot.jet.passenger.manual_confirm_max', 6)) {
            $this->markEscalationState($conversation);
            $this->humanEscalationService->escalateQuestion(
                conversation: $conversation,
                customer: $customer,
                reason: 'Permintaan booking lebih dari 6 penumpang.',
            );

            return $this->decision(
                booking: $booking,
                action: 'human_escalation',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        'Izin Bapak/Ibu, untuk jumlah penumpang lebih dari 6 orang perlu kami bantu konfirmasi manual terlebih dahulu ya.',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'human_escalation'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::HumanHandoff),
            );
        }

        if ($slots['travel_date'] === null || $slots['travel_time'] === null) {
            $this->stateService->setExpectedInput($conversation, 'travel_time');

            return $this->decision(
                booking: $booking,
                action: 'ask_travel_datetime',
                reply: $this->buildTravelDateTimeReply($signals, $prefixLines, $slots),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        $availableSeats = $this->seatAvailability->availableSeats(
            travelDate: (string) $slots['travel_date'],
            travelTime: (string) $slots['travel_time'],
            excludeBookingId: $booking->id,
        );
        $this->stateService->putMany($conversation, ['seat_choices_available' => $availableSeats]);
        $slots['seat_choices_available'] = $availableSeats;

        if (count($availableSeats) < (int) $slots['passenger_count']) {
            $this->stateService->setExpectedInput($conversation, 'travel_time');

            return $this->decision(
                booking: $booking,
                action: 'unavailable',
                reply: $this->reply(
                    text: $this->prependNotes($prefixLines, $this->unavailableSeatReply($slots, (int) $slots['passenger_count'])),
                    meta: ['source' => 'booking_engine', 'action' => 'unavailable', 'has_booking_update' => $updates !== []],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::ScheduleInquiry),
            );
        }

        if (! $this->hasValidSeatSelection($slots)) {
            $this->stateService->setExpectedInput($conversation, 'selected_seats');

            return $this->decision(
                booking: $booking,
                action: 'ask_seats',
                reply: $this->reply(
                    text: $this->prependNotes($prefixLines, $this->seatSelectionPrompt($slots)),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_seats'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        try {
            $reservedSeats = $this->seatAvailability->reserveSeats($booking, $slots['selected_seats']);
            $this->stateService->putMany($conversation, ['selected_seats' => $reservedSeats]);
            $slots['selected_seats'] = $reservedSeats;
            $booking = $this->stateService->syncBooking($booking, $slots, $customer->phone_e164 ?? '');
        } catch (\RuntimeException) {
            $freshAvailability = $this->seatAvailability->availableSeats(
                travelDate: (string) $slots['travel_date'],
                travelTime: (string) $slots['travel_time'],
                excludeBookingId: $booking->id,
            );
            $this->stateService->putMany($conversation, [
                'selected_seats' => [],
                'seat_choices_available' => $freshAvailability,
            ]);
            $this->stateService->setExpectedInput($conversation, 'selected_seats');

            return $this->decision(
                booking: $booking,
                action: 'ask_seats',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        'Mohon maaf ya Bapak/Ibu, seat yang dipilih baru saja terambil. Silakan pilih seat lain dari daftar berikut ya:' . "\n\n" . $this->seatMenuText($freshAvailability),
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_seats'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if ($slots['pickup_point'] === null) {
            $this->stateService->setExpectedInput($conversation, 'pickup_point');

            return $this->decision(
                booking: $booking,
                action: 'ask_pickup_point',
                reply: $this->reply(
                    text: $this->prependNotes($prefixLines, "Izin Bapak/Ibu, untuk penjemputannya di mana ya?\n\n" . $this->locationMenuText()),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_pickup_point'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if ($slots['pickup_full_address'] === null) {
            $this->stateService->setExpectedInput($conversation, 'pickup_full_address');

            return $this->decision(
                booking: $booking,
                action: 'ask_pickup_full_address',
                reply: $this->reply(
                    text: $this->prependNotes($prefixLines, 'Izin Bapak/Ibu, boleh dibantu alamat lengkap penjemputannya ya?'),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_pickup_full_address'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if ($slots['destination_point'] === null) {
            $this->stateService->setExpectedInput($conversation, 'destination_point');

            return $this->decision(
                booking: $booking,
                action: 'ask_destination',
                reply: $this->reply(
                    text: $this->prependNotes($prefixLines, "Baik Bapak/Ibu, lalu untuk pengantarannya ke mana ya?\n\n" . $this->locationMenuText()),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_destination'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        $fareAmount = $this->fareCalculator->calculate(
            pickup: $slots['pickup_point'],
            destination: $slots['destination_point'],
            passengerCount: (int) $slots['passenger_count'],
        );

        if ($fareAmount === null) {
            $this->stateService->putMany($conversation, [
                'route_status' => 'unsupported',
                'fare_amount' => null,
                'review_sent' => false,
                'booking_confirmed' => false,
            ]);
            $this->stateService->setExpectedInput($conversation, null);

            return $this->decision(
                booking: $booking,
                action: 'unsupported_route',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        'Mohon maaf ya Bapak/Ibu, untuk rute tersebut kami belum memiliki tarif yang tersedia. Jika berkenan, silakan kirim rute lain atau kami bantu konsultasikan ke admin.',
                    ),
                    meta: ['source' => 'booking_engine', 'action' => 'unsupported_route', 'has_booking_update' => $updates !== []],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::PriceInquiry),
            );
        }

        $this->stateService->putMany($conversation, ['route_status' => 'supported', 'fare_amount' => $fareAmount]);
        $slots['route_status'] = 'supported';
        $slots['fare_amount'] = $fareAmount;
        $booking = $this->stateService->syncBooking($booking, $slots, $customer->phone_e164 ?? '');

        if (! $this->hasValidPassengerNames($slots)) {
            $this->stateService->setExpectedInput($conversation, 'passenger_names');

            return $this->decision(
                booking: $booking,
                action: 'ask_passenger_names',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        (int) $slots['passenger_count'] > 1
                            ? 'Izin Bapak/Ibu, boleh dibantu nama-nama penumpangnya ya?'
                            : 'Izin Bapak/Ibu, boleh dibantu nama penumpangnya ya?',
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_passenger_names'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if (($slots['contact_number'] ?? null) === null) {
            $this->stateService->setExpectedInput($conversation, 'contact_number');

            return $this->decision(
                booking: $booking,
                action: 'ask_contact_number',
                reply: $this->reply(
                    text: $this->prependNotes(
                        $prefixLines,
                        "Izin Bapak/Ibu, apakah nomor kontak penumpangnya sama dengan nomor yang sedang menghubungi ini atau berbeda? Jika berbeda, boleh dibantu nomor HP-nya ya. Jika sama, cukup ketik 'sama'.",
                    ),
                    meta: ['source' => 'jet_flow', 'action' => 'ask_contact_number'],
                ),
                intentResult: $this->overrideIntent($intentResult, IntentType::Booking),
            );
        }

        if (($slots['review_sent'] ?? false) !== true) {
            $this->confirmationService->requestConfirmation($booking);
            $this->stateService->putMany($conversation, [
                'review_sent' => true,
                'booking_confirmed' => false,
                'booking_intent_status' => 'awaiting_confirmation',
            ]);
            $this->stateService->setExpectedInput($conversation, 'final_confirmation');

            return $this->decision(
                booking: $booking->fresh(),
                action: 'ask_confirmation',
                reply: $this->buildReviewReply($booking->fresh(), $prefixLines),
                intentResult: $this->overrideIntent($intentResult, IntentType::BookingConfirm),
            );
        }

        return $this->decision(
            booking: $booking,
            action: 'ask_confirmation',
            reply: $this->buildReviewReply($booking, $prefixLines),
            intentResult: $this->overrideIntent($intentResult, IntentType::BookingConfirm),
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $updates
     */
    private function handleDirectPriceInquiry(array $signals, array $updates): ?array
    {
        $pickup = $updates['pickup_point'] ?? null;
        $destination = $updates['destination_point'] ?? null;

        if ($pickup === null || $destination === null) {
            return $this->reply(
                text: $this->withSalamPrefix(
                    $signals,
                    'Izin Bapak/Ibu, agar saya bisa cek ongkosnya dengan tepat, mohon dibantu titik jemput dan tujuan perjalanannya ya.',
                ),
                meta: ['source' => 'jet_flow', 'action' => 'ask_route_for_price'],
            );
        }

        $fare = $this->fareCalculator->unitFare($pickup, $destination);

        if ($fare === null) {
            return $this->reply(
                text: $this->withSalamPrefix(
                    $signals,
                    'Mohon maaf ya Bapak/Ibu, untuk rute tersebut kami belum memiliki tarif yang tersedia. Jika berkenan, silakan kirim rute lain atau kami bantu konsultasikan ke admin.',
                ),
                meta: ['source' => 'booking_engine', 'action' => 'unsupported_route', 'has_booking_update' => $updates !== []],
            );
        }

        return $this->reply(
            text: $this->withSalamPrefix(
                $signals,
                'Untuk rute ' . $pickup . ' ke ' . $destination . ', ongkosnya ' . $this->fareCalculator->formatRupiah($fare) . ' per penumpang ya Bapak/Ibu. Jika ingin lanjut booking, saya bantu lanjutkan sekarang.',
            ),
            meta: ['source' => 'jet_flow', 'action' => 'price_inquiry'],
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function buildScheduleReply(array $signals, bool $continueBooking = false): array
    {
        $body = 'Untuk jadwal reguler JET, keberangkatan tersedia di 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB ya Bapak/Ibu. Jika ingin, saya bisa bantu cek seat yang masih tersedia sesuai tanggal dan jam pilihan Bapak/Ibu.';

        if ($continueBooking) {
            $body .= ' Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya ya?';
        }

        if (! $this->interactiveEnabled()) {
            return $this->reply(
                text: $this->withSalamPrefix($signals, $body . "\n\n" . $this->departureSlotMenuText()),
                meta: ['source' => 'jet_flow', 'action' => 'schedule_inquiry'],
            );
        }

        return $this->reply(
            text: $this->withSalamPrefix($signals, $body),
            meta: ['source' => 'jet_flow', 'action' => 'schedule_inquiry'],
            messageType: 'interactive',
            outboundPayload: [
                'type' => 'interactive',
                'interactive' => $this->departureTimeInteractivePayload('Silakan pilih jam keberangkatan yang ingin dicek ya.'),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $updates
     */
    private function hasTravelSignals(Conversation $conversation, array $intentResult, array $signals, array $slots, array $updates, ?BookingRequest $existingDraft): bool
    {
        $intent = IntentType::tryFrom($intentResult['intent'] ?? '');

        if ($existingDraft !== null) {
            return true;
        }

        if (filled($conversation->summary)) {
            return true;
        }

        if (
            filled($conversation->current_intent)
            && ! in_array($conversation->current_intent, ['greeting', 'farewell', 'unknown'], true)
        ) {
            return true;
        }

        if (($slots['booking_intent_status'] ?? 'idle') !== 'idle') {
            return true;
        }

        if (
            ($slots['pickup_point'] ?? null) !== null
            || ($slots['destination_point'] ?? null) !== null
            || ($slots['travel_date'] ?? null) !== null
            || ($slots['travel_time'] ?? null) !== null
        ) {
            return true;
        }

        if ($intent !== null && in_array($intent, [
            IntentType::Greeting,
            IntentType::Booking,
            IntentType::BookingConfirm,
            IntentType::BookingCancel,
            IntentType::ScheduleInquiry,
            IntentType::PriceInquiry,
            IntentType::LocationInquiry,
            IntentType::Confirmation,
            IntentType::Rejection,
        ], true)) {
            return true;
        }

        if ($signals['greeting_only'] ?? false) {
            return false;
        }

        if ($signals['booking_keyword'] || $signals['schedule_keyword'] || $signals['price_keyword'] || $signals['route_keyword']) {
            return true;
        }

        return $updates !== [];
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<string, mixed>  $updates
     */
    private function shouldStartBooking(array $signals, array $updates): bool
    {
        return $signals['booking_keyword']
            || ($updates['passenger_count'] ?? null) !== null
            || ($updates['travel_date'] ?? null) !== null
            || ($updates['travel_time'] ?? null) !== null
            || ($updates['pickup_point'] ?? null) !== null
            || ($updates['destination_point'] ?? null) !== null;
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function isRouteListInquiry(array $signals, string $messageText): bool
    {
        if (! $signals['route_keyword']) {
            return false;
        }

        return (bool) preg_match('/\b(lokasi jemput|titik jemput|rute|trayek|tujuan tersedia|antar ke mana)\b/u', mb_strtolower($messageText, 'UTF-8'));
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     */
    private function applyDerivedStateUpdates(array $slots, array $updates): array
    {
        if ($this->hasAnyKey($updates, ['passenger_count', 'travel_date', 'travel_time'])) {
            $updates['selected_seats'] = [];
            $updates['seat_choices_available'] = [];
        }

        if ($this->hasAnyKey($updates, ['pickup_point', 'destination_point'])) {
            $updates['route_status'] = null;
            $updates['fare_amount'] = null;
        }

        if ($this->hasAnyKey($updates, array_keys(self::SLOT_LABELS))) {
            $updates['review_sent'] = false;
            $updates['booking_confirmed'] = false;
        }

        if (($updates['contact_same_as_sender'] ?? null) === true && ($updates['contact_number'] ?? null) === null) {
            $updates['contact_number'] = $slots['contact_number'] ?? null;
        }

        return $updates;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function hasValidSeatSelection(array $slots): bool
    {
        if (! is_array($slots['selected_seats'] ?? null)) {
            return false;
        }

        return count($slots['selected_seats']) === (int) ($slots['passenger_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function hasValidPassengerNames(array $slots): bool
    {
        if (! is_array($slots['passenger_names'] ?? null)) {
            return false;
        }

        return count($slots['passenger_names']) === (int) ($slots['passenger_count'] ?? 0);
    }

    /**
     * @param  array<string, mixed>  $signals
     * @param  array<int, string>    $notes
     * @param  array<string, mixed>  $slots
     */
    private function buildTravelDateTimeReply(array $signals, array $notes, array $slots): array
    {
        $message = 'Izin Bapak/Ibu, kalau boleh tahu untuk keberangkatannya di tanggal berapa dan jam berapa ya?';

        if (($signals['time_ambiguous'] ?? false) === true && $slots['travel_date'] !== null) {
            $message = 'Baik Bapak/Ibu, tanggalnya sudah saya catat. Untuk pilihan jam paginya, boleh pilih salah satu slot berikut ya.';
        }

        if (! $this->interactiveEnabled()) {
            return $this->reply(
                text: $this->prependNotes($notes, $message . "\n\n" . $this->departureSlotMenuText()),
                meta: ['source' => 'jet_flow', 'action' => 'ask_travel_datetime'],
            );
        }

        return $this->reply(
            text: $this->prependNotes($notes, $message),
            meta: ['source' => 'jet_flow', 'action' => 'ask_travel_datetime'],
            messageType: 'interactive',
            outboundPayload: [
                'type' => 'interactive',
                'interactive' => $this->departureTimeInteractivePayload(
                    'Silakan pilih jam keberangkatan. Tanggalnya bisa langsung dibalas bersamaan atau sesudah pilih jam ya.',
                ),
            ],
        );
    }

    /**
     * @param  array<int, string>  $notes
     */
    private function buildReviewReply(BookingRequest $booking, array $notes): array
    {
        $summary = $this->confirmationService->buildSummary($booking);

        if (! $this->interactiveEnabled()) {
            return $this->reply(
                text: $this->prependNotes($notes, $summary),
                meta: ['source' => 'jet_flow', 'action' => 'ask_confirmation'],
            );
        }

        return $this->reply(
            text: $this->prependNotes($notes, $summary),
            meta: ['source' => 'jet_flow', 'action' => 'ask_confirmation'],
            messageType: 'interactive',
            outboundPayload: [
                'type' => 'interactive',
                'interactive' => [
                    'type' => 'button',
                    'body' => ['text' => 'Apakah data perjalanan ini sudah benar ya Bapak/Ibu?'],
                    'action' => [
                        'buttons' => [
                            ['type' => 'reply', 'reply' => ['id' => 'jet_confirm_yes', 'title' => 'Benar']],
                            ['type' => 'reply', 'reply' => ['id' => 'jet_confirm_fix', 'title' => 'Ubah Data']],
                        ],
                    ],
                ],
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function decision(?BookingRequest $booking, string $action, array $reply, array $intentResult): array
    {
        return [
            'handled' => true,
            'booking' => $booking,
            'booking_decision' => [
                'action' => $action,
                'booking_status' => $booking?->booking_status?->value ?? BookingStatus::Draft->value,
            ],
            'reply' => $reply,
            'intent_result' => $intentResult,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $outboundPayload
     * @return array<string, mixed>
     */
    private function reply(
        string $text,
        array $meta,
        bool $isFallback = false,
        string $messageType = 'text',
        array $outboundPayload = [],
    ): array {
        return [
            'text' => $text,
            'is_fallback' => $isFallback,
            'message_type' => $messageType,
            'outbound_payload' => $outboundPayload,
            'meta' => $meta,
        ];
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function withSalamPrefix(array $signals, string $text, string $messageText = ''): string
    {
        if ($messageText !== '') {
            return $this->greetingService->prependIslamicGreeting($messageText, $text);
        }

        if (($signals['salam_type'] ?? null) !== 'islamic') {
            return $text;
        }

        return $this->greetingService->prependIslamicGreeting('assalamualaikum', $text);
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function buildOpeningGreeting(array $signals, string $openingText, string $messageText = ''): string
    {
        return $this->withSalamPrefix($signals, $openingText, $messageText);
    }

    /**
     * @param  array<int, string>  $notes
     */
    private function prependNotes(array $notes, string $text): string
    {
        $lines = array_filter(array_merge($notes, [$text]));

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function manualPassengerNotice(array $slots): ?string
    {
        return (int) ($slots['passenger_count'] ?? 0) === (int) config('chatbot.jet.passenger.manual_confirm_max', 6)
            ? 'Untuk 6 penumpang, izin Bapak/Ibu, perlu kami konfirmasikan terlebih dahulu ya.'
            : null;
    }

    /**
     * @param  array<string, mixed>  $currentSlots
     * @param  array<string, mixed>  $updates
     */
    private function buildCorrectionNotice(array $currentSlots, array $updates): string
    {
        $messages = [];

        foreach (self::SLOT_LABELS as $key => $label) {
            if (! array_key_exists($key, $updates)) {
                continue;
            }

            $old = $currentSlots[$key] ?? null;
            $new = $updates[$key];

            if ($old === null || $old === [] || json_encode($old) === json_encode($new)) {
                continue;
            }

            $messages[] = 'Baik Bapak/Ibu, saya update ' . $label . ' menjadi ' . $this->stringifyValue($new) . ' ya.';
        }

        return implode("\n", $messages);
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_map(fn (mixed $item) => (string) $item, $value));
        }

        if (is_bool($value)) {
            return $value ? 'ya' : 'tidak';
        }

        return (string) $value;
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function seatSelectionPrompt(array $slots): string
    {
        return 'Izin Bapak/Ibu, untuk keberangkatan jam ' . $slots['travel_time'] . ' WIB, seat yang masih tersedia saat ini adalah: ' . implode(', ', $slots['seat_choices_available']) . ".\n\nSilakan pilih " . $slots['passenger_count'] . ' seat ya. Jika ingin pilih berdasarkan nomor daftar, balas dengan nomor seat dipisahkan koma.' . "\n\n" . $this->seatMenuText($slots['seat_choices_available']);
    }

    /**
     * @param  array<string, mixed>  $slots
     */
    private function unavailableSeatReply(array $slots, int $passengerCount): string
    {
        $alternatives = [];

        foreach (config('chatbot.jet.departure_slots', []) as $slot) {
            $time = $slot['time'] ?? null;

            if (! is_string($time) || $time === (string) $slots['travel_time']) {
                continue;
            }

            $available = $this->seatAvailability->availableSeats((string) $slots['travel_date'], $time);

            if (count($available) >= $passengerCount) {
                $alternatives[] = $time . ' WIB';
            }
        }

        $text = 'Mohon maaf ya Bapak/Ibu, untuk keberangkatan ' . $slots['travel_time'] . ' WIB seat yang tersedia saat ini belum mencukupi untuk ' . $passengerCount . ' penumpang.';

        if ($alternatives !== []) {
            $text .= ' Pilihan jam lain yang masih memungkinkan: ' . implode(', ', $alternatives) . '.';
        }

        $text .= ' Jika berkenan, silakan pilih jam lain atau kami bantu konsultasikan ke admin.';

        return $text;
    }

    private function locationMenuText(): string
    {
        $lines = ['Pilihan titik jemput / tujuan:'];

        foreach ($this->routeValidator->menuLocations() as $index => $label) {
            $lines[] = ($index + 1) . '. ' . $label;
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<int, string>  $seats
     */
    private function seatMenuText(array $seats): string
    {
        $lines = ['Pilihan seat:'];

        foreach ($seats as $index => $seat) {
            $lines[] = ($index + 1) . '. ' . $seat;
        }

        return implode("\n", $lines);
    }

    private function departureSlotMenuText(): string
    {
        $lines = ['Pilihan jam keberangkatan:'];

        foreach (config('chatbot.jet.departure_slots', []) as $slot) {
            $lines[] = ($slot['order'] ?? '?') . '. ' . ($slot['label'] ?? '');
        }

        return implode("\n", $lines);
    }

    /**
     * @return array<string, mixed>
     */
    private function departureTimeInteractivePayload(string $bodyText): array
    {
        $rows = [];

        foreach (config('chatbot.jet.departure_slots', []) as $slot) {
            $rows[] = [
                'id' => 'jet_time_' . ($slot['time'] ?? ''),
                'title' => (string) ($slot['label'] ?? ''),
                'description' => 'Pilih slot keberangkatan ini',
            ];
        }

        return [
            'type' => 'list',
            'header' => ['type' => 'text', 'text' => 'Jadwal JET'],
            'body' => ['text' => $bodyText],
            'footer' => ['text' => 'JET (Jasa Executive Travel)'],
            'action' => [
                'button' => 'Pilih Jam',
                'sections' => [
                    [
                        'title' => 'Jam Keberangkatan',
                        'rows' => $rows,
                    ],
                ],
            ],
        ];
    }

    private function interactiveEnabled(): bool
    {
        return (bool) config('chatbot.whatsapp.interactive_enabled', true);
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function overrideIntent(array $intentResult, IntentType $intent): array
    {
        $intentResult['intent'] = $intent->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0), 0.95);

        return $intentResult;
    }

    private function markEscalationState(Conversation $conversation): void
    {
        $this->stateService->putMany($conversation, [
            'admin_takeover' => true,
            'needs_human_escalation' => true,
            'booking_intent_status' => 'needs_human',
        ]);
        $this->stateService->setExpectedInput($conversation, null);
    }

    /**
     * @param  array<string, mixed>  $updates
     * @param  array<int, string>    $keys
     */
    private function hasAnyKey(array $updates, array $keys): bool
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $updates)) {
                return true;
            }
        }

        return false;
    }
}
