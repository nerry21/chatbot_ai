<?php

namespace App\Services\Chatbot;

use Carbon\Carbon;

/**
 * TravelMessageRouterService
 *
 * Rule-based router that stitches together the four Travel* services into a
 * single, stateless request/response cycle.  All conversation state is passed
 * in via $payload['state'] and returned as $result['new_state'] — this service
 * never reads from or writes to the database directly.
 *
 * This is intentionally a lightweight alternative to the full LLM pipeline
 * (BookingFlowStateMachine + ReplyOrchestratorService).  Use it for simple
 * deterministic flows where the LLM overhead is not needed, or as a fallback
 * layer before invoking the LLM.
 *
 * Input contract:
 * [
 *   'text'  => string,
 *   'phone' => string,
 *   'state' => [
 *     'status'                      => 'idle|booking|booking_confirmed|schedule_change',
 *     'current_step'                => null|string,
 *     'booking_data'                => array,
 *     'schedule_change_data'        => array,
 *     'last_admin_notification_key' => null|string,
 *     'last_completed_booking_at'   => null|string,
 *     'departure_datetime'          => null|string,
 *     'first_follow_up_sent_at'     => null|string,
 *   ],
 *   'now'  => Carbon|string|null,
 * ]
 *
 * Output contract:
 * [
 *   'reply_text' => string,
 *   'intent'     => string,
 *   'actions'    => [
 *     ['type' => 'notify_admin', 'channel' => 'main_admin', 'message' => string],
 *     ['type' => 'save_state'],
 *   ],
 *   'new_state'  => array,
 *   'meta'       => array,
 * ]
 */
class TravelMessageRouterService
{
    public function __construct(
        private readonly TravelGreetingService $greetingService,
        private readonly TravelFareService $fareService,
        private readonly TravelBookingRuleService $bookingRuleService,
        private readonly TravelFaqMatcherService $faqMatcherService,
    ) {}

    // ─── Entry point ───────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function route(array $payload): array
    {
        $text  = trim((string) ($payload['text'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $state = $this->normalizeState((array) ($payload['state'] ?? []));
        $now   = $this->resolveNow($payload['now'] ?? null);

        if ($text === '') {
            return $this->buildResult(
                replyText: $this->faqMatcherService->buildFallbackCustomerMessage(),
                intent: 'empty_message',
                state: $state,
                actions: [],
                meta: ['reason' => 'empty_text'],
            );
        }

        // 1. Pure greeting — ALWAYS reset and show service menu, regardless of current state.
        //    If customer sends "Hallo selamat pagi" they want to start fresh.
        if ($this->isGreetingOnly($text)) {
            $state['status']               = 'idle';
            $state['current_step']         = null;
            $state['booking_data']         = [];
            $state['schedule_change_data'] = [];
            $state['booking_edit_mode']    = false;

            return $this->handleGreetingOnly($text, $state, $now);
        }

        // 2. New booking request while already in booking → reset and start fresh.
        if ($state['status'] === 'booking' && $this->isBookingStartMessage($text)) {
            return $this->handleBookingStartWithGreeting($text, $state, $now);
        }

        // 2b. Even during booking, if customer clearly asks about fare, answer it first
        if ($state['status'] === 'booking' && $this->looksLikeFareQuestion($text)) {
            $fareResponse = $this->tryHandleFareQuestion($text, $state);
            if ($fareResponse !== null) {
                return $fareResponse;
            }
        }

        // 3. Booking continuation
        if ($state['status'] === 'booking') {
            return $this->handleBookingFlow($text, $phone, $state, $now);
        }

        // 4. Schedule change continuation
        if ($state['status'] === 'schedule_change') {
            return $this->handleScheduleChangeFlow($text, $phone, $state, $now);
        }

        // 5. Dropping / Rental / Paket → forward to admin
        if ($this->isDroppingOrRentalOrPaketRequest($text)) {
            return $this->handleNonRegularService($phone, $state, $text);
        }

        // 6. Booking start trigger (reguler / booking / pesan / boking / pemesanan)
        if ($this->isBookingStartMessage($text)) {
            return $this->handleBookingStartWithGreeting($text, $state, $now);
        }

        // 7. Schedule change start trigger
        if ($this->isScheduleChangeMessage($text)) {
            return $this->handleScheduleChangeStart($text, $phone, $state, $now);
        }

        // 8. After booking confirmed, acknowledge close intent gracefully
        if (($state['status'] ?? 'idle') === 'booking_confirmed' && $this->isPostBookingCloseIntent($text)) {
            return $this->buildResult(
                replyText: '🙏',
                intent: 'close_after_booking',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: ['step' => 'post_booking_close'],
            );
        }

        // 9. Offer repeat booking / schedule change after a completed booking
        if ($this->shouldOfferRepeatBookingOrScheduleChange($state, $text)) {
            return $this->handleRepeatBookingOrScheduleChangeQuestion($state);
        }

        // 9. Fare question (check before schedule to avoid false matches on "sore/pagi/siang/malam")
        if ($this->looksLikeFareQuestion($text)) {
            $fareResponse = $this->tryHandleFareQuestion($text, $state);
            if ($fareResponse !== null) {
                return $fareResponse;
            }
        }

        // 10. Pertanyaan jadwal sederhana (ONLY when not a booking request)
        if ($state['status'] === 'idle' && $this->looksLikeSimpleScheduleQuestion($text)) {
            return $this->handleSimpleScheduleQuestion($text, $state);
        }

        // 11. Knowledge base / FAQ
        $faqMatch = $this->faqMatcherService->match($text);
        if ($faqMatch !== null && $faqMatch['score'] >= TravelFaqMatcherService::FALLBACK_SCORE_THRESHOLD) {
            return $this->buildResult(
                replyText: $faqMatch['answer'],
                intent: 'faq_match',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: ['faq_title' => $faqMatch['title'], 'faq_score' => $faqMatch['score']],
            );
        }

        // 12. Defer to LLM+CRM pipeline for natural conversation
        return $this->buildResult(
            replyText: '',
            intent: 'defer_to_llm',
            state: $state,
            actions: [],
            meta: ['defer_to_llm' => true, 'original_text' => $text],
        );
    }

    // ─── Greeting ──────────────────────────────────────────────────────────────

    private function handleGreetingOnly(string $text, array $state, Carbon $now): array
    {
        return $this->buildResult(
            replyText: $this->greetingService->buildOpeningGreeting($text, $now),
            intent: $this->greetingService->shouldReplyIslamicGreeting($text)
                ? 'greeting_islamic'
                : 'greeting_general',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'greeting_key' => $this->greetingService->getGreetingLabel($now),
                'interactive_type' => 'list',
                'interactive_list' => $this->buildServiceMenuInteractiveList(),
            ],
        );
    }

    // ─── Service menu (Reguler / Dropping / Rental / Paket) ────────────────────

    private function isDroppingOrRentalOrPaketRequest(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        foreach (['dropping', 'rental', 'sewa mobil', 'paket', 'kirim paket', 'pengiriman paket'] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function handleNonRegularService(string $phone, array $state, string $text): array
    {
        $notifKey = 'non_regular:'.md5($phone.'|'.$this->normalizeText($text));
        $actions  = [['type' => 'save_state']];

        if (($state['last_admin_notification_key'] ?? null) !== $notifKey) {
            $actions[] = [
                'type'    => 'notify_admin',
                'channel' => 'main_admin',
                'message' => "Bos, ada pertanyaan layanan dari No {$phone}:\n\"{$text}\"\n\nBisa bantu jawab, Bos?",
            ];
            $state['last_admin_notification_key'] = $notifKey;
        }

        return $this->buildResult(
            replyText: 'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Izin kami konsultasikan dahulu ya. Admin kami akan segera menghubungi Bapak/Ibu.',
            intent: 'non_regular_service',
            state: $state,
            actions: $actions,
            meta: ['service_type' => 'non_regular'],
        );
    }

    private function buildServiceMenuInteractiveList(): array
    {
        return [
            'button'   => 'Pilih Layanan',
            'header'   => 'Layanan JET Travel',
            'body'     => 'Silakan pilih layanan yang diinginkan.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => [
                [
                    'title' => 'Pilihan Layanan',
                    'rows'  => [
                        ['id' => 'service:reguler',  'title' => 'Reguler',           'description' => 'Antar-jemput standar mulai Rp 150.000'],
                        ['id' => 'service:dropping', 'title' => 'Dropping',          'description' => '1 mobil langsung ke tujuan'],
                        ['id' => 'service:rental',   'title' => 'Rental',            'description' => 'Sewa mobil min. 2 hari'],
                        ['id' => 'service:paket',    'title' => 'Pengiriman Paket',  'description' => 'Kirim barang antar kota'],
                    ],
                ],
            ],
        ];
    }

    // ─── Booking start with greeting ───────────────────────────────────────────

    private function handleBookingStartWithGreeting(string $text, array $state, Carbon $now): array
    {
        $hasIslamic = $this->greetingService->shouldReplyIslamicGreeting($text);
        $prefix = '';

        if ($hasIslamic) {
            $prefix = "Waalaikumsalam warahmatullahi wabarakatuh\n\n";
        }

        // Detect if text contains a greeting word
        $hasGreeting = $this->containsGreeting($text);
        if ($hasGreeting && !$hasIslamic) {
            $timeLabel = $this->greetingService->getTimeBasedGreeting($now);
            $prefix = $timeLabel.".\n\n";
        }

        $state['status']       = 'booking';
        $state['current_step'] = 'ask_passenger_count';
        $state['booking_data'] = [
            'departure_date'       => null,
            'departure_date_label' => null,
            'departure_time'       => null,
            'departure_time_label' => null,
            'passenger_count'      => null,
            'seat'                 => null,
            'pickup_point'         => null,
            'pickup_address'       => null,
            'dropoff_point'        => null,
            'dropoff_address'      => null,
            'passenger_names'      => [],
            'contact_number'       => null,
        ];

        return $this->buildResult(
            replyText: $prefix."Baik, saya bantu bookingnya ya.\n\nUntuk keberangkatan ini ada berapa orang penumpangnya, Bapak/Ibu?",
            intent: 'start_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'booking_started'  => true,
                'step'             => 'ask_passenger_count',
                'interactive_type' => 'list',
                'interactive_list' => $this->buildPassengerCountInteractiveList(),
            ],
        );
    }

    private function containsGreeting(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        foreach (['halo', 'hallo', 'hai', 'hi', 'hello', 'pagi', 'siang', 'sore', 'malam', 'selamat'] as $word) {
            if (str_contains($normalized, $word)) {
                return true;
            }
        }

        return false;
    }

    // ─── Booking flow ──────────────────────────────────────────────────────────

    private function handleBookingStart(array $state): array
    {
        $state['status']       = 'booking';
        $state['current_step'] = 'ask_passenger_count';
        $state['booking_data'] = [
            'departure_date'       => null,
            'departure_date_label' => null,
            'departure_time'       => null,
            'departure_time_label' => null,
            'passenger_count'      => null,
            'seat'                 => null,
            'pickup_point'         => null,
            'pickup_address'       => null,
            'dropoff_point'        => null,
            'dropoff_address'      => null,
            'passenger_names'      => [],
            'contact_number'       => null,
        ];

        return $this->buildResult(
            replyText: "Baik, saya bantu bookingnya ya.\n\nUntuk keberangkatan ini ada berapa orang penumpangnya, Bapak/Ibu?",
            intent: 'start_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'booking_started'  => true,
                'step'             => 'ask_passenger_count',
                'interactive_type' => 'list',
                'interactive_list' => $this->buildPassengerCountInteractiveList(),
            ],
        );
    }

    private function handleBookingFlow(string $text, string $phone, array $state, Carbon $now): array
    {
        $step = $state['current_step'] ?? 'ask_passenger_count';

        return match ($step) {
            'ask_departure_date'              => $this->handleDepartureDateStep($text, $state),
            'ask_departure_time'              => $this->handleDepartureTimeStep($text, $state),
            'ask_departure_time_and_date'     => $this->handleDepartureDateStep($text, $state),
            'ask_passenger_count'             => $this->handlePassengerCountStep($text, $state),
            'ask_seat'                        => $this->handleSeatStep($text, $state),
            'wait_admin_seat_confirmation'    => $this->handleAdminSeatConfirmationResponse($text, $state),
            'seat_rejected_choose_action'     => $this->handleSeatRejectedAction($text, $state),
            'ask_pickup_point'                => $this->handlePickupPointStep($text, $state),
            'ask_pickup_address'              => $this->handlePickupAddressStep($text, $state),
            'ask_dropoff_point'               => $this->handleDropoffPointStep($text, $state),
            'ask_dropoff_address'             => $this->handleDropoffAddressStep($text, $state),
            'ask_passenger_name'              => $this->handlePassengerNameStep($text, $state),
            'ask_contact_number'              => $this->handleContactStep($text, $state, $phone),
            'ask_review_confirmation'         => $this->handleReviewConfirmationStep($text, $phone, $state, $now),
            'ask_which_field_to_change'       => $this->handleWhichFieldToChangeStep($text, $state),
            default                           => $this->handleBookingStart($state),
        };
    }

    private function handlePassengerCountStep(string $text, array $state): array
    {
        $count = $this->extractPassengerCount($text);

        if ($count === null) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon pilih jumlah penumpangnya.',
                intent: 'passenger_count',
                state: $state,
                actions: [],
                meta: [
                    'step' => 'ask_passenger_count',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildPassengerCountInteractiveList(),
                ],
            );
        }

        $validation = $this->bookingRuleService->validatePassengerCount($count);

        if (! $validation['valid']) {
            return $this->buildResult(
                replyText: $validation['message'],
                intent: 'passenger_count_invalid',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_passenger_count'],
            );
        }

        $state['booking_data']['passenger_count']                = $count;
        $state['booking_data']['requires_passenger_confirmation'] = $validation['requires_confirmation'];
        $state['current_step']                                   = 'ask_passenger_name';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        $reply = $validation['requires_confirmation']
            ? $validation['message']."\n\n"
            : '';

        if ($count <= 1) {
            $reply .= "Baik, {$count} orang penumpang sudah saya catat.\n\nIzin Bapak/Ibu, boleh kami minta nama penumpangnya?";
        } else {
            $reply .= "Baik, {$count} orang penumpang sudah saya catat.\n\nIzin Bapak/Ibu, boleh dibantu nama-nama penumpangnya? Bisa dikirim sekaligus dipisah koma.";
        }

        return $this->buildResult(
            replyText: trim($reply),
            intent: 'passenger_count',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step' => 'ask_passenger_name',
            ],
        );
    }

    private function handleDepartureStep(string $text, array $state): array
    {
        $state['current_step'] = 'ask_departure_date';

        return $this->handleDepartureDateStep($text, $state);
    }

    private function handleDepartureDateStep(string $text, array $state): array
    {
        $bookingData = $state['booking_data'] ?? [];

        $selectedDate = $this->extractSelectedDepartureDateFromInteractive($text)
            ?? $this->extractSelectableDepartureDate($text);

        if ($selectedDate === null) {
            return $this->buildResult(
                replyText: "Izin Bapak/Ibu, silakan pilih tanggal keberangkatannya terlebih dahulu ya.\n\n"
                    .$this->buildDepartureDateMenuText(),
                intent: 'departure_date',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step'                       => 'ask_departure_date',
                    'show_departure_date_picker' => true,
                    'departure_date_options'     => $this->buildDepartureDateOptions(),
                    'interactive_type'           => 'list',
                    'interactive_list'           => $this->buildDepartureDateInteractiveList(),
                ],
            );
        }

        $bookingData['departure_date']       = $selectedDate['value'];
        $bookingData['departure_date_label'] = $selectedDate['label'];

        $state['booking_data'] = $bookingData;
        $state['current_step'] = 'ask_departure_time';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        return $this->buildResult(
            replyText: "Baik, tanggal keberangkatan sudah saya catat: {$selectedDate['label']}.\n\nSekarang silakan pilih jam keberangkatannya ya.\n\n"
                .$this->buildDepartureMenuText(),
            intent: 'departure_date',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step'                       => 'ask_departure_time',
                'show_departure_time_picker' => true,
                'interactive_type'           => 'list',
                'interactive_list'           => $this->buildDepartureTimeInteractiveList(),
            ],
        );
    }

    private function handleDepartureTimeStep(string $text, array $state): array
    {
        $bookingData = $state['booking_data'] ?? [];

        $normalizedText = mb_strtolower(trim($text), 'UTF-8');
        $normalizedText = str_replace(['.', ' wib', 'pukul '], [':', '', ''], $normalizedText);

        $slot = $this->extractSelectedDepartureTimeFromInteractive($text)
            ?? $this->bookingRuleService->findDepartureTime($normalizedText);

        if ($slot === null) {
            return $this->buildResult(
                replyText: "Izin Bapak/Ibu, silakan pilih jam keberangkatannya ya.\n\n"
                    .$this->buildDepartureMenuText(),
                intent: 'departure_time',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step'                       => 'ask_departure_time',
                    'show_departure_time_picker' => true,
                    'interactive_type'           => 'list',
                    'interactive_list'           => $this->buildDepartureTimeInteractiveList(),
                ],
            );
        }

        $bookingData['departure_time']       = substr((string) ($slot['time'] ?? ''), 0, 5);
        $bookingData['departure_time_label'] = (string) ($slot['label'] ?? $bookingData['departure_time']);

        $state['booking_data'] = $bookingData;
        $state['current_step'] = 'ask_seat';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        // Initialize selected_seats array
        $state['booking_data']['selected_seats'] = [];

        $passengerCount = (int) ($state['booking_data']['passenger_count'] ?? 1);
        $passengerNames = is_array($state['booking_data']['passenger_names'] ?? null) ? $state['booking_data']['passenger_names'] : [];
        $firstName = $passengerNames[0] ?? 'penumpang pertama';

        $seatPrompt = 'Baik, jam keberangkatan sudah saya catat: '
            .$bookingData['departure_time'].' WIB.'
            ."\n\nIzin Bapak/Ibu, untuk ketersediaan seat tempat duduk di jam ".$bookingData['departure_time'].' WIB'
            .' berdasarkan '.$passengerCount.' orang penumpang, ';

        if ($passengerCount > 1) {
            $seatPrompt .= 'silakan pilih seat untuk '.$firstName.'. Setelah ini sistem akan mengunci seat yang dipilih sehingga penumpang berikutnya tidak bisa memilih seat yang sama. Ini berlaku juga untuk pemesanan berikutnya yang memesan di tanggal dan di jam yang sama.';
        } else {
            $seatPrompt .= 'silakan pilih seat untuk '.$firstName.'.';
        }

        return $this->buildResult(
            replyText: $seatPrompt,
            intent: 'departure_time',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step'             => 'ask_seat',
                'departure_slot'   => $slot,
                'interactive_type' => 'list',
                'interactive_list' => $this->buildSeatInteractiveList([], $firstName),
            ],
        );
    }

    private function handleSeatStep(string $text, array $state): array
    {
        $seat = $this->extractSelectedSeatFromInteractive($text) ?? trim($text);
        $passengerCount = (int) ($state['booking_data']['passenger_count'] ?? 1);
        $passengerNames = is_array($state['booking_data']['passenger_names'] ?? null) ? $state['booking_data']['passenger_names'] : [];

        // Initialize selected_seats array if not exists
        if (!isset($state['booking_data']['selected_seats']) || !is_array($state['booking_data']['selected_seats'])) {
            $state['booking_data']['selected_seats'] = [];
        }

        if ($seat === '') {
            $selectedCount = count($state['booking_data']['selected_seats']);
            $currentName = $passengerNames[$selectedCount] ?? 'penumpang ke-'.($selectedCount + 1);
            $firstName = $passengerNames[0] ?? 'penumpang pertama';

            $promptText = $passengerCount > 1 && $selectedCount === 0
                ? 'Izin Bapak/Ibu, silakan pilih seat untuk '.$firstName.'. Setelah ini sistem akan mengunci seat yang dipilih sehingga penumpang berikutnya tidak bisa memilih seat yang sama. Ini berlaku juga untuk pemesanan berikutnya yang memesan di tanggal dan di jam yang sama.'
                : ($selectedCount > 0
                    ? 'Silakan pilih seat untuk '.$currentName.'.'
                    : 'Izin Bapak/Ibu, mohon pilih seat untuk '.$firstName.'.');

            return $this->buildResult(
                replyText: $promptText,
                intent: 'seat',
                state: $state,
                actions: [],
                meta: [
                    'step' => 'ask_seat',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildSeatInteractiveList($state['booking_data']['selected_seats'], $selectedCount === 0 ? $firstName : $currentName),
                ],
            );
        }

        // Check if seat already selected
        if (in_array($seat, $state['booking_data']['selected_seats'], true)) {
            $selectedCount = count($state['booking_data']['selected_seats']);
            $currentName = $passengerNames[$selectedCount] ?? 'penumpang ke-'.($selectedCount + 1);
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, seat '.$seat.' sudah dipilih sebelumnya. Silakan pilih seat lain untuk '.$currentName.'.',
                intent: 'seat',
                state: $state,
                actions: [],
                meta: [
                    'step' => 'ask_seat',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildSeatInteractiveList($state['booking_data']['selected_seats'], $currentName),
                ],
            );
        }

        $state['booking_data']['selected_seats'][] = $seat;
        // Keep backward compatibility
        $state['booking_data']['seat'] = implode(', ', $state['booking_data']['selected_seats']);

        // Check if seat requires admin confirmation (e.g., BS Tengah)
        $requiresAdminConfirmation = in_array(
            $seat,
            (array) config('chatbot.jet.seat_requires_admin_confirmation', ['BS Tengah']),
            true,
        );

        if ($requiresAdminConfirmation) {
            $state['current_step'] = 'wait_admin_seat_confirmation';

            $phone = $state['booking_data']['contact_number'] ?? '-';
            $departureTime = $state['booking_data']['departure_time'] ?? '-';
            $departureDate = $state['booking_data']['departure_date_label'] ?? $state['booking_data']['departure_date'] ?? '-';

            $adminMessage = "Konfirmasi Seat BS Tengah\n"
                ."Tanggal: {$departureDate}\n"
                ."Jam: {$departureTime} WIB\n"
                ."Jumlah penumpang: {$passengerCount} orang\n\n"
                ."Apakah seat BS Tengah diizinkan, Bos?\n"
                ."Balas: IZINKAN atau TOLAK";

            return $this->buildResult(
                replyText: "Izin Bapak/Ibu, untuk seat *BS Tengah* memerlukan konfirmasi dari Admin terlebih dahulu.\n\nMohon ditunggu sebentar ya, kami sedang mengkonfirmasi ke Admin.",
                intent: 'seat_admin_confirmation',
                state: $state,
                actions: [
                    ['type' => 'notify_admin', 'channel' => 'main_admin', 'message' => $adminMessage],
                    ['type' => 'save_state'],
                ],
                meta: ['step' => 'wait_admin_seat_confirmation', 'seat_requires_confirmation' => true],
            );
        }

        // Check if more seats needed
        $selectedCount = count($state['booking_data']['selected_seats']);
        if ($selectedCount < $passengerCount) {
            $nextName = $passengerNames[$selectedCount] ?? 'penumpang ke-'.($selectedCount + 1);
            $currentName = $passengerNames[$selectedCount - 1] ?? 'penumpang';
            $state['current_step'] = 'ask_seat';

            return $this->buildResult(
                replyText: 'Baik, seat '.$seat.' untuk '.$currentName.' sudah dicatat. Silakan pilih seat untuk '.$nextName.'.',
                intent: 'seat',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step' => 'ask_seat',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildSeatInteractiveList($state['booking_data']['selected_seats'], $nextName),
                ],
            );
        }

        $state['current_step'] = 'ask_pickup_point';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        return $this->buildResult(
            replyText: 'Izin Bapak/Ibu, silakan pilih titik penjemputannya.',
            intent: 'seat',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step' => 'ask_pickup_point',
                'interactive_type' => 'list',
                'interactive_list' => $this->buildPickupPointInteractiveList(),
            ],
        );
    }

    private function handleAdminSeatConfirmationResponse(string $text, array $state): array
    {
        $normalized = $this->normalizeText($text);

        // Admin approves
        if (str_contains($normalized, 'izinkan') || str_contains($normalized, 'approve')
            || str_contains($normalized, 'boleh') || str_contains($normalized, 'oke')
            || str_contains($normalized, 'setuju') || str_contains($normalized, 'ya')) {

            $state['current_step'] = 'ask_pickup_point';

            return $this->buildResult(
                replyText: "Baik Bapak/Ibu, seat *BS Tengah* sudah dikonfirmasi oleh Admin.\n\nSekarang, izin Bapak/Ibu silakan pilih titik penjemputannya.",
                intent: 'seat_confirmed',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step' => 'ask_pickup_point',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildPickupPointInteractiveList(),
                ],
            );
        }

        // Admin rejects
        if (str_contains($normalized, 'tolak') || str_contains($normalized, 'tidak')
            || str_contains($normalized, 'reject') || str_contains($normalized, 'jangan')) {

            $state['current_step'] = 'seat_rejected_choose_action';
            $state['booking_data']['seat'] = null;

            return $this->buildResult(
                replyText: "Mohon maaf Bapak/Ibu, seat *BS Tengah* tidak tersedia saat ini.\n\nSilakan pilih:\n1. Ganti seat\n2. Ganti jam keberangkatan\n3. Ganti tanggal keberangkatan",
                intent: 'seat_rejected',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: ['step' => 'seat_rejected_choose_action'],
            );
        }

        // Unrecognized — still waiting
        return $this->buildResult(
            replyText: 'Mohon ditunggu ya Bapak/Ibu, kami masih menunggu konfirmasi dari Admin untuk seat BS Tengah.',
            intent: 'seat_waiting',
            state: $state,
            actions: [],
            meta: ['step' => 'wait_admin_seat_confirmation'],
        );
    }

    private function handleSeatRejectedAction(string $text, array $state): array
    {
        $normalized = $this->normalizeText($text);

        if (str_contains($normalized, 'ganti seat') || str_contains($normalized, 'seat') || $normalized === '1') {
            $state['current_step'] = 'ask_seat';
            $state['booking_data']['selected_seats'] = [];
            $state['booking_data']['seat'] = null;

            return $this->buildResult(
                replyText: 'Baik, silakan pilih seat lain yang diinginkan, Bapak/Ibu.',
                intent: 'seat_change',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step' => 'ask_seat',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildSeatInteractiveList(),
                ],
            );
        }

        if (str_contains($normalized, 'ganti jam') || str_contains($normalized, 'jam') || $normalized === '2') {
            $state['current_step'] = 'ask_departure_time';
            $state['booking_data']['departure_time'] = null;
            $state['booking_data']['departure_time_label'] = null;
            $state['booking_data']['seat'] = null;

            return $this->buildResult(
                replyText: "Baik, silakan pilih jam keberangkatan yang baru.",
                intent: 'change_time',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step' => 'ask_departure_time',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildDepartureTimeInteractiveList(),
                ],
            );
        }

        if (str_contains($normalized, 'ganti tanggal') || str_contains($normalized, 'tanggal') || $normalized === '3') {
            $state['current_step'] = 'ask_departure_date';
            $state['booking_data']['departure_date'] = null;
            $state['booking_data']['departure_date_label'] = null;
            $state['booking_data']['departure_time'] = null;
            $state['booking_data']['departure_time_label'] = null;
            $state['booking_data']['seat'] = null;

            return $this->buildResult(
                replyText: "Baik, silakan pilih tanggal keberangkatan yang baru.",
                intent: 'change_date',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step' => 'ask_departure_date',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildDepartureDateInteractiveList(),
                ],
            );
        }

        return $this->buildResult(
            replyText: "Izin Bapak/Ibu, silakan pilih:\n1. Ganti seat\n2. Ganti jam keberangkatan\n3. Ganti tanggal keberangkatan",
            intent: 'seat_rejected_retry',
            state: $state,
            actions: [],
            meta: ['step' => 'seat_rejected_choose_action'],
        );
    }

    private function handlePickupPointStep(string $text, array $state): array
    {
        $location = $this->extractSelectedPickupLocationFromInteractive($text)
            ?? $this->extractLocation($text);

        if ($location === null) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon pilih titik penjemputannya.',
                intent: 'pickup_location',
                state: $state,
                actions: [],
                meta: [
                    'step' => 'ask_pickup_point',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildPickupPointInteractiveList(),
                ],
            );
        }

        $state['booking_data']['pickup_point'] = $location;
        $state['current_step'] = 'ask_pickup_address';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        return $this->buildResult(
            replyText: 'Boleh minta alamat lengkap penjemputannya, Bapak/Ibu?',
            intent: 'pickup_location',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_pickup_address'],
        );
    }

    private function handlePickupAddressStep(string $text, array $state): array
    {
        $address = trim($text);

        if ($address === '') {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu alamat lengkap penjemputannya.',
                intent: 'pickup_address',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_pickup_address'],
            );
        }

        $state['booking_data']['pickup_address'] = $address;
        $state['current_step'] = 'ask_dropoff_point';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        return $this->buildResult(
            replyText: 'Untuk tujuan pengantarannya ke mana, Bapak/Ibu? Silakan pilih lokasinya.',
            intent: 'pickup_address',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step' => 'ask_dropoff_point',
                'interactive_type' => 'list',
                'interactive_list' => $this->buildDropoffPointInteractiveList(),
            ],
        );
    }

    private function handleDropoffPointStep(string $text, array $state): array
    {
        $location = $this->extractSelectedDropoffLocationFromInteractive($text)
            ?? $this->extractLocation($text);

        if ($location === null) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon pilih tujuan pengantarannya.',
                intent: 'dropoff_location',
                state: $state,
                actions: [],
                meta: [
                    'step' => 'ask_dropoff_point',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildDropoffPointInteractiveList(),
                ],
            );
        }

        $state['booking_data']['dropoff_point'] = $location;
        $state['current_step'] = 'ask_dropoff_address';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        return $this->buildResult(
            replyText: 'Boleh minta alamat lengkap pengantarannya, Bapak/Ibu?',
            intent: 'dropoff_location',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_dropoff_address'],
        );
    }

    private function handleDropoffAddressStep(string $text, array $state): array
    {
        $address = trim($text);

        if ($address === '') {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu alamat lengkap pengantarannya.',
                intent: 'dropoff_address',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_dropoff_address'],
            );
        }

        $state['booking_data']['dropoff_address'] = $address;
        $state['current_step'] = 'ask_contact_number';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        return $this->buildResult(
            replyText: 'Untuk nomor kontak yang bisa dihubungi, boleh dikirimkan? Jika sama dengan nomor ini cukup ketik "sama".',
            intent: 'dropoff_address',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_contact_number'],
        );
    }

    private function handlePassengerNameStep(string $text, array $state): array
    {
        $names = $this->parsePassengerNames($text);

        if ($names === []) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu isi nama penumpangnya.',
                intent: 'passenger_name',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_passenger_name'],
            );
        }

        $state['booking_data']['passenger_names'] = $names;
        $state['current_step']                    = 'ask_departure_date';

        if (!empty($state['booking_edit_mode'])) {
            return $this->buildEditModeReturnToReview($state);
        }

        $namesDisplay = implode(', ', $names);

        return $this->buildResult(
            replyText: "Baik, nama penumpang sudah saya catat: {$namesDisplay}.\n\nIzin Bapak/Ibu, kalau boleh tahu untuk keberangkatan di tanggal berapa?",
            intent: 'passenger_name',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step'                       => 'ask_departure_date',
                'interactive_type'           => 'list',
                'interactive_list'           => $this->buildDepartureDateInteractiveList(),
            ],
        );
    }

    private function handleContactStep(string $text, array $state, string $phone): array
    {
        if ($this->normalizeText($text) === 'sama') {
            $state['booking_data']['contact_number'] = $phone;
        } else {
            $number = $this->extractPhoneNumber($text);

            if ($number === null) {
                return $this->buildResult(
                    replyText: 'Izin Bapak/Ibu, jika nomor kontaknya berbeda mohon kirimkan nomor HP-nya. Jika sama, cukup ketik "sama".',
                    intent: 'contact_confirmation',
                    state: $state,
                    actions: [],
                    meta: ['step' => 'ask_contact_number'],
                );
            }

            $state['booking_data']['contact_number'] = $number;
        }

        $state['current_step'] = 'ask_review_confirmation';

        return $this->buildResult(
            replyText: $this->buildBookingReviewText($state['booking_data']),
            intent: 'booking_review',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_review_confirmation'],
        );
    }

    private function handleReviewConfirmationStep(string $text, string $phone, array $state, Carbon $now): array
    {
        if ($this->isChangeDataRequest($text)) {
            $state['current_step'] = 'ask_which_field_to_change';

            return $this->buildResult(
                replyText: 'Silakan pilih bagian data yang ingin diubah, Bapak/Ibu.',
                intent: 'booking_review_change_request',
                state: $state,
                actions: [['type' => 'save_state']],
                meta: [
                    'step'             => 'ask_which_field_to_change',
                    'interactive_type' => 'list',
                    'interactive_list' => $this->buildChangeFieldInteractiveList(),
                ],
            );
        }

        if (! $this->bookingRuleService->isConfirmationText($text)) {
            return $this->buildResult(
                replyText: 'Baik Bapak/Ibu, silakan ketik "Benar" untuk konfirmasi, atau "Ubah Data" jika ada yang ingin diperbaiki.',
                intent: 'booking_review_unconfirmed',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_review_confirmation'],
            );
        }

        $adminMessage = "Booking Baru\nNomor Customer: {$phone}\n\n"
            .$this->buildBookingReviewText($state['booking_data']);

        $state['status']                     = 'booking_confirmed';
        $state['current_step']               = null;
        $state['booking_edit_mode']          = false;
        $state['last_completed_booking_at']  = $now->toDateTimeString();
        $state['departure_datetime']         = $this->combineDepartureDateTime(
            (string) ($state['booking_data']['departure_date'] ?? ''),
            (string) ($state['booking_data']['departure_time'] ?? ''),
        );

        return $this->buildResult(
            replyText: "Baik, data pemesanan Anda sudah lengkap dan telah kami terima 🙏🙂\nTerima kasih telah memilih JET (Jaya Executive Transport) sebagai partner perjalanan Anda 🙏🙂\nPemesanan Anda telah kami terima dan sedang kami siapkan dengan sebaik-baiknya.\nKami berharap Anda mendapatkan pengalaman perjalanan yang aman, nyaman, dan berkesan bersama JET.\nTerima kasih atas kepercayaan dan kesempatan yang Anda berikan kepada kami 🙏🙂\nKami akan kembali menghubungi Bapak/Ibu melalui kanal WA ini atau dari Admin Utama jika ada perubahan jadwal, informasi keberangkatan atau konfirmasi keberangkatan.",
            intent: 'booking_confirmed',
            state: $state,
            actions: [
                ['type' => 'notify_admin', 'channel' => 'main_admin', 'message' => $adminMessage],
                ['type' => 'save_state'],
            ],
            meta: ['admin_code' => 'Booking Baru'],
        );
    }

    private function handleWhichFieldToChangeStep(string $text, array $state): array
    {
        $fieldMap = [
            'change_field:departure_date'    => 'ask_departure_date',
            'change_field:departure_time'    => 'ask_departure_time',
            'change_field:passenger_count'   => 'ask_passenger_count',
            'change_field:seat'              => 'ask_seat',
            'change_field:pickup_point'      => 'ask_pickup_point',
            'change_field:pickup_address'    => 'ask_pickup_address',
            'change_field:dropoff_point'     => 'ask_dropoff_point',
            'change_field:dropoff_address'   => 'ask_dropoff_address',
            'change_field:passenger_names'   => 'ask_passenger_name',
            'change_field:contact_number'    => 'ask_contact_number',
        ];

        $normalized = trim($text);

        // Interactive list click
        if (isset($fieldMap[$normalized])) {
            $state['booking_edit_mode'] = true;
            $state['current_step']      = $fieldMap[$normalized];

            return $this->handleBookingFlow($text, '', $state, $this->resolveNow(null));
        }

        // Teks bebas → coba cocokkan kata kunci
        $keywordMap = [
            'tanggal'        => 'ask_departure_date',
            'jam'            => 'ask_departure_time',
            'penumpang'      => 'ask_passenger_count',
            'seat'           => 'ask_seat',
            'kursi'          => 'ask_seat',
            'titik jemput'   => 'ask_pickup_point',
            'jemput'         => 'ask_pickup_point',
            'alamat jemput'  => 'ask_pickup_address',
            'alamat antar'   => 'ask_dropoff_address',
            'tujuan'         => 'ask_dropoff_point',
            'nama'           => 'ask_passenger_name',
            'kontak'         => 'ask_contact_number',
            'hp'             => 'ask_contact_number',
            'nomor'          => 'ask_contact_number',
            '1'              => 'ask_departure_date',
            '2'              => 'ask_departure_time',
            '3'              => 'ask_passenger_count',
            '4'              => 'ask_seat',
            '5'              => 'ask_pickup_point',
            '6'              => 'ask_pickup_address',
            '7'              => 'ask_dropoff_point',
            '8'              => 'ask_dropoff_address',
            '9'              => 'ask_passenger_name',
            '10'             => 'ask_contact_number',
        ];

        $normalizedLower = $this->normalizeText($text);

        foreach ($keywordMap as $keyword => $step) {
            if (str_contains($normalizedLower, $keyword) || $normalizedLower === $keyword) {
                $state['booking_edit_mode'] = true;
                $state['current_step']      = $step;

                return $this->handleBookingFlow('', '', $state, $this->resolveNow(null));
            }
        }

        // Tidak dikenali → tampilkan menu lagi
        return $this->buildResult(
            replyText: 'Izin Bapak/Ibu, mohon pilih bagian yang ingin diubah.',
            intent: 'booking_review_change_request',
            state: $state,
            actions: [],
            meta: [
                'step'             => 'ask_which_field_to_change',
                'interactive_type' => 'list',
                'interactive_list' => $this->buildChangeFieldInteractiveList(),
            ],
        );
    }

    // ─── Schedule change flow ──────────────────────────────────────────────────

    private function handleScheduleChangeStart(string $text, string $phone, array $state, Carbon $now): array
    {
        $departureDateTime = $this->parseStateDepartureDatetime($state['departure_datetime'] ?? null);

        if ($departureDateTime !== null) {
            $check = $this->bookingRuleService->canChangeSchedule($departureDateTime, $now);

            if (! $check['allowed']) {
                return $this->buildResult(
                    replyText: $check['message'],
                    intent: 'change_schedule_blocked',
                    state: $state,
                    actions: [],
                    meta: ['schedule_change_check' => $check],
                );
            }
        }

        $state['status']               = 'schedule_change';
        $state['current_step']         = 'ask_change_time_or_date';
        $state['schedule_change_data'] = [];

        return $this->buildResult(
            replyText: 'Baik Bapak/Ibu, izin bantu proses perubahan jadwalnya.'
                ."\n\nBoleh tahu tanggal atau jam keberangkatan yang baru?",
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_time_or_date'],
        );
    }

    private function handleScheduleChangeFlow(string $text, string $phone, array $state, Carbon $now): array
    {
        $step = $state['current_step'] ?? 'ask_change_time_or_date';

        return match ($step) {
            'ask_change_time_or_date'           => $this->handleChangeTimeOrDateStep($text, $state),
            'ask_change_seat'                   => $this->handleChangeSeatStep($text, $state),
            'ask_change_pickup_address'         => $this->handleChangePickupAddressStep($text, $state),
            'ask_change_dropoff_point'          => $this->handleChangeDropoffPointStep($text, $state),
            'ask_schedule_change_confirmation'  => $this->handleScheduleChangeConfirmationStep($text, $phone, $state),
            default => $this->buildResult(
                replyText: 'Boleh tahu tanggal atau jam keberangkatan yang baru, Bapak/Ibu?',
                intent: 'change_schedule',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_change_time_or_date'],
            ),
        };
    }

    private function handleChangeTimeOrDateStep(string $text, array $state): array
    {
        $date = $this->extractDateText($text);
        $slot = $this->bookingRuleService->findDepartureTime($text);

        if ($date === null && $slot === null) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu perubahan tanggal atau jam keberangkatannya.',
                intent: 'change_schedule',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_change_time_or_date'],
            );
        }

        if ($date !== null) {
            $state['schedule_change_data']['departure_date'] = $date;
        }

        if ($slot !== null) {
            $state['schedule_change_data']['departure_time']       = substr((string) ($slot['time'] ?? ''), 0, 5);
            $state['schedule_change_data']['departure_time_label'] = $slot['label'] ?? '';
        }

        $state['current_step'] = 'ask_change_seat';

        return $this->buildResult(
            replyText: 'Untuk seat yang baru, pilihan tersedia: '
                .implode(', ', (array) config('chatbot.jet.seat_labels', ['CC', 'BS', 'Tengah', 'Belakang Kiri', 'Belakang Kanan', 'Belakang Sekali']))
                .'. Seat mana yang diinginkan?',
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_seat'],
        );
    }

    private function handleChangeSeatStep(string $text, array $state): array
    {
        $state['schedule_change_data']['seat'] = trim($text);
        $state['current_step']                 = 'ask_change_pickup_address';

        return $this->buildResult(
            replyText: 'Alamat penjemputan yang baru, Bapak/Ibu?',
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_pickup_address'],
        );
    }

    private function handleChangePickupAddressStep(string $text, array $state): array
    {
        $state['schedule_change_data']['pickup_address'] = trim($text);
        $state['current_step']                           = 'ask_change_dropoff_point';

        return $this->buildResult(
            replyText: "Tujuan pengantaran yang baru ke mana, Bapak/Ibu?\n\n"
                .$this->fareService->buildLocationListText(),
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_dropoff_point'],
        );
    }

    private function handleChangeDropoffPointStep(string $text, array $state): array
    {
        $location = $this->extractLocation($text) ?? trim($text);

        $state['schedule_change_data']['dropoff_point'] = $location;
        $state['current_step']                          = 'ask_schedule_change_confirmation';

        $mergedData = array_merge(
            (array) ($state['booking_data'] ?? []),
            (array) ($state['schedule_change_data'] ?? []),
        );

        return $this->buildResult(
            replyText: $this->bookingRuleService->buildScheduleChangeReview($mergedData)
                ."\n\nApakah perubahan jadwal ini sudah benar, Bapak/Ibu?",
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_schedule_change_confirmation'],
        );
    }

    private function handleScheduleChangeConfirmationStep(string $text, string $phone, array $state): array
    {
        if (! $this->bookingRuleService->isConfirmationText($text)) {
            return $this->buildResult(
                replyText: 'Baik Bapak/Ibu, silakan sampaikan bagian perubahan mana yang masih ingin diperbaiki.',
                intent: 'change_schedule_unconfirmed',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_schedule_change_confirmation'],
            );
        }

        $adminCode = 'Perubahan Jadwal';
        $mergedData = array_merge(
            (array) ($state['booking_data'] ?? []),
            (array) ($state['schedule_change_data'] ?? []),
        );
        $adminMessage = "{$adminCode}\nNomor Customer: {$phone}\n\n"
            .$this->bookingRuleService->buildScheduleChangeReview($mergedData);

        $notifKey = 'schedule_change:'.md5($phone.'|'.json_encode($state['schedule_change_data'] ?? []));
        $actions  = [['type' => 'save_state']];

        if (($state['last_admin_notification_key'] ?? null) !== $notifKey) {
            $actions[] = [
                'type'    => 'notify_admin',
                'channel' => 'main_admin',
                'message' => $adminMessage,
            ];
            $state['last_admin_notification_key'] = $notifKey;
        }

        $state['status']       = 'booking_confirmed';
        $state['current_step'] = null;

        return $this->buildResult(
            replyText: 'Baik Bapak/Ibu, perubahan jadwal sudah kami catat. Terima kasih.',
            intent: 'change_schedule_confirmed',
            state: $state,
            actions: $actions,
            meta: ['admin_code' => $adminCode],
        );
    }

    // ─── Post-booking repeat offer ─────────────────────────────────────────────

    private function handleRepeatBookingOrScheduleChangeQuestion(array $state): array
    {
        return $this->buildResult(
            replyText: 'Selamat datang kembali, Bapak/Ibu. Apakah ingin melakukan booking baru atau ada perubahan jadwal?',
            intent: 'repeat_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['offer_repeat_booking' => true],
        );
    }

    // ─── Fare question ─────────────────────────────────────────────────────────

    private function tryHandleFareQuestion(string $text, array $state): ?array
    {
        [$origin, $destination] = $this->extractOriginDestination($text);

        if ($origin === null || $destination === null) {
            // If only 1 location found, try to answer with available routes
            $singleLocation = $this->extractLocation($text);
            if ($singleLocation !== null && $this->looksLikeFareQuestion($text)) {
                $allLocations = $this->fareService->getAllLocations();
                $routes = [];
                foreach ($allLocations as $loc) {
                    if ($loc === $singleLocation) continue;
                    $fare = $this->fareService->findFare($singleLocation, $loc);
                    if ($fare !== null) {
                        $routes[] = '• '.$singleLocation.' ↔ '.$loc.': '.$fare['formatted_price'];
                    }
                }
                if ($routes !== []) {
                    $replyText = 'Izin Bapak/Ibu, berikut daftar ongkos dari/ke '.$singleLocation.":\n\n".implode("\n", $routes);
                    $replyText .= "\n\nJika ingin lanjut booking, silakan kabari ya 🙏";
                    return $this->buildResult(
                        replyText: $replyText,
                        intent: 'ask_fare',
                        state: $state,
                        actions: [['type' => 'save_state']],
                        meta: ['location' => $singleLocation, 'fare_list' => true],
                    );
                }
            }
            return null;
        }

        $fareText = $this->fareService->getFareText($origin, $destination);

        if ($fareText === null) {
            return null;
        }

        // Add natural greeting if message contains greeting
        $prefix = '';
        $normalized = $this->normalizeText($text);
        foreach (['halo', 'hallo', 'hai', 'hi', 'hello', 'assalamualaikum', 'min', 'kak', 'bang', 'pak', 'bu'] as $greeting) {
            if (str_contains($normalized, $greeting)) {
                $prefix = '';
                break;
            }
        }

        $suffix = "\n\nJika ingin lanjut booking, silakan kabari ya 🙏";

        return $this->buildResult(
            replyText: $fareText.$suffix,
            intent: 'ask_fare',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['origin' => $origin, 'destination' => $destination],
        );
    }

    // ─── Fallback to admin ─────────────────────────────────────────────────────

    private function handleFallbackToAdmin(string $phone, array $state, string $text): array
    {
        $notifKey = 'fallback:'.md5($phone.'|'.$this->normalizeText($text));
        $actions  = [['type' => 'save_state']];

        if (($state['last_admin_notification_key'] ?? null) !== $notifKey) {
            $actions[] = [
                'type'    => 'notify_admin',
                'channel' => 'main_admin',
                'message' => $this->faqMatcherService->buildFallbackAdminMessage($phone),
            ];
            $state['last_admin_notification_key'] = $notifKey;
        }

        return $this->buildResult(
            replyText: $this->faqMatcherService->buildFallbackCustomerMessage(),
            intent: 'fallback_admin',
            state: $state,
            actions: $actions,
            meta: ['fallback' => true],
        );
    }

    private function resetBookingToFirstQuestion(array $state): array
    {
        $state['status']       = 'booking';
        $state['current_step'] = 'ask_passenger_count';

        return $this->buildResult(
            replyText: 'Izin Bapak/Ibu, mohon dibantu isi jumlah penumpangnya terlebih dahulu.',
            intent: 'start_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['reset_booking' => true],
        );
    }

    // ─── Intent detection helpers ──────────────────────────────────────────────

    private function isPostBookingCloseIntent(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $closeWords = [
            'oke', 'ok', 'oke terima kasih', 'ok terima kasih',
            'baik', 'baik terima kasih', 'siap', 'sip',
            'terima kasih', 'makasih', 'thanks', 'thank you',
            'ya', 'iya', 'sudah', 'mantap',
            'oke makasih', 'ok makasih', 'baik makasih',
            'siap terima kasih', 'sip terima kasih',
            'oke siap', 'baik siap', 'amin',
            'ya sudah', 'yaudah', 'udah',
        ];

        return in_array($normalized, $closeWords, true);
    }

    private function isGreetingOnly(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $greetings = [
            'assalamualaikum', 'halo', 'hallo', 'hai', 'hi', 'hello', 'hey',
            'pagi', 'siang', 'sore', 'malam',
            'selamat pagi', 'selamat siang', 'selamat sore', 'selamat malam',
            'halo selamat pagi', 'halo selamat siang', 'halo selamat sore', 'halo selamat malam',
            'hallo selamat pagi', 'hallo selamat siang', 'hallo selamat sore', 'hallo selamat malam',
            'assalamualaikum selamat pagi', 'assalamualaikum selamat siang',
            'assalamualaikum selamat sore', 'assalamualaikum selamat malam',
        ];

        return in_array($normalized, $greetings, true);
    }

    private function isBookingStartMessage(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        foreach ([
            'mau booking',
            'mau boking',
            'mau pesan',
            'pesan travel',
            'pemesanan',
            'booking',
            'boking',
            'bookin',
            'reguler',
            'service:reguler',
            'reservasi',
            'pesan tiket',
            'order',
            'mau berangkat',
            'saya mau berangkat',
            'ingin berangkat',
            'ingin pesan',
            'lanjut booking',
            'lanjut boking',
            'lanjut pesan',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isScheduleChangeMessage(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        foreach ([
            'ubah jadwal',
            'ganti jadwal',
            'perubahan jadwal',
            'ubah tanggal',
            'ubah jam',
            'reschedule',
            'jadwalnya diubah',
            'saya mau ubah jadwal',
            'saya mau ganti jadwal',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function isChangeDataRequest(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        foreach ([
            'ubah data',
            'ganti data',
            'ada yang salah',
            'ada yang keliru',
            'perbaiki',
            'koreksi',
            'tidak benar',
            'belum benar',
            'salah',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return $normalized === '2';
    }

    private function looksLikeFareQuestion(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        foreach (['ongkos', 'harga', 'tarif', 'berapa'] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function shouldOfferRepeatBookingOrScheduleChange(array $state, string $text): bool
    {
        if (($state['status'] ?? 'idle') !== 'booking_confirmed') {
            return false;
        }

        $normalized = $this->normalizeText($text);

        if ($this->looksLikeSimpleScheduleQuestion($text)) {
            return false;
        }

        $lastCompletedAt = $this->parseStateDepartureDatetime($state['last_completed_booking_at'] ?? null);
        if ($lastCompletedAt === null) {
            return false;
        }

        if ($lastCompletedAt->diffInMinutes(now(config('chatbot.jet.timezone', 'Asia/Jakarta'))) > 90) {
            return false;
        }

        $triggers = [
            'halo',
            'hai',
            'pagi',
            'siang',
            'sore',
            'malam',
            'assalamualaikum',
            'assalamualaikum selamat pagi',
        ];

        return in_array($normalized, $triggers, true)
            || str_contains($normalized, 'pesan lagi')
            || str_contains($normalized, 'booking lagi');
    }

    private function looksLikeBusinessQuestion(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            'booking',
            'pesan',
            'jadwal',
            'keberangkatan',
            'berangkat',
            'jam 5',
            'jam 7',
            'jam 9',
            'jam 13',
            'jam 16',
            'jam 19',
            'ongkos',
            'tarif',
            'harga',
            'seat',
            'kursi',
            'jemput',
            'antar',
            'tujuan',
            'rute',
            'travel',
            'ubah jadwal',
            'ganti jadwal',
            'apakah ada',
            'ada keberangkatan',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    private function looksLikeSimpleScheduleQuestion(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        // Time words like pagi/siang/sore/malam should only match when combined with schedule context
        foreach ([
            'jadwal',
            'keberangkatan',
            'berangkat',
            'apakah ada',
            'ada keberangkatan',
        ] as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        // Only match "jam" followed by time-like content, not standalone
        if (preg_match('/\bjam\s+\d/', $normalized)) {
            return true;
        }

        // Time-of-day words only count as schedule question if they appear with schedule context
        $hasTimeWord = false;
        foreach (['pagi', 'siang', 'sore', 'malam'] as $timeWord) {
            if (str_contains($normalized, $timeWord)) {
                $hasTimeWord = true;
                break;
            }
        }

        if ($hasTimeWord) {
            // If it also has greeting words, it's likely a greeting not a schedule question
            $hasGreeting = false;
            foreach (['halo', 'hallo', 'hai', 'hi', 'hello', 'selamat', 'assalamualaikum'] as $greeting) {
                if (str_contains($normalized, $greeting)) {
                    $hasGreeting = true;
                    break;
                }
            }

            // If it has greeting + time word but also other content (fare, booking, etc), it's NOT a schedule question
            if ($hasGreeting) {
                return false;
            }

            // Pure time word without greeting - could be schedule question
            // But only if no other intent keywords present
            foreach (['ongkos', 'harga', 'tarif', 'berapa', 'booking', 'boking', 'pesan'] as $otherIntent) {
                if (str_contains($normalized, $otherIntent)) {
                    return false;
                }
            }

            return true;
        }

        if ($this->bookingRuleService->findDepartureTime($text) !== null) {
            return true;
        }

        return false;
    }

    private function handleSimpleScheduleQuestion(string $text, array $state): array
    {
        $slot = $this->bookingRuleService->findDepartureTime($text);

        if ($slot !== null) {
            $slotLabel = (string) ($slot['label'] ?? 'Jadwal');
            $slotTime  = substr((string) ($slot['time'] ?? ''), 0, 5);

            $reply = "Ada Bapak/Ibu, untuk jam {$slotTime} WIB tersedia pada jadwal {$slotLabel} 🙏"
                . "\n\nJadwal keberangkatan yang tersedia setiap hari:\n"
                . $this->buildDepartureMenuText()
                . "\n\nKalau mau langsung booking, tinggal kabari saja ya 🙏";
        } else {
            $reply = "Izin Bapak/Ibu, berikut jadwal keberangkatan yang tersedia setiap hari:\n\n"
                . $this->buildDepartureMenuText()
                . "\n\nKalau mau langsung booking, tinggal kabari saja ya 🙏";
        }

        return $this->buildResult(
            replyText: $reply,
            intent: 'ask_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'schedule_question' => true,
                'matched_slot' => $slot,
            ],
        );
    }

    // ─── Extraction helpers ────────────────────────────────────────────────────

    private function extractPassengerCount(string $text): ?int
    {
        $trimmed = trim($text);

        // Handle interactive selection: "passenger_count_2"
        if (preg_match('/^passenger_count_(\d+)$/i', $trimmed, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/\b([1-9][0-9]?)\b/u', $text, $matches)) {
            return (int) $matches[1];
        }

        $normalized = $this->normalizeText($text);
        $wordMap = ['satu' => 1, 'dua' => 2, 'tiga' => 3, 'empat' => 4, 'lima' => 5, 'enam' => 6];

        foreach ($wordMap as $word => $number) {
            if (str_contains($normalized, $word)) {
                return $number;
            }
        }

        return null;
    }

    private function extractDateText(string $text): ?string
    {
        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $text, $match)) {
            return $match[0];
        }

        if (preg_match('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $text, $match)) {
            return $match[0];
        }

        $normalized = $this->normalizeText($text);
        $tz         = config('chatbot.jet.timezone', 'Asia/Jakarta');

        if (str_contains($normalized, 'hari ini')) {
            return now($tz)->toDateString();
        }

        if (str_contains($normalized, 'besok')) {
            return now($tz)->addDay()->toDateString();
        }

        return null;
    }

    private function extractLocation(string $text): ?string
    {
        $normalized = $this->normalizeText($text);
        $allLocations = $this->fareService->getAllLocations();

        // Check if input is a number matching location index
        $trimmed = trim($text);
        if (preg_match('/^\d+$/', $trimmed)) {
            $index = (int) $trimmed - 1;
            if ($index >= 0 && $index < count($allLocations)) {
                return $allLocations[$index];
            }
        }

        foreach ($allLocations as $location) {
            $normalizedLocation = $this->normalizeText($location);

            if (
                str_contains(' '.$normalized.' ', ' '.$normalizedLocation.' ')
                || str_contains($normalized, $normalizedLocation)
            ) {
                return $location;
            }
        }

        return null;
    }

    /**
     * @return array{0: string|null, 1: string|null}
     */
    private function extractOriginDestination(string $text): array
    {
        $normalized = $this->normalizeText($text);
        $found      = [];

        foreach ($this->fareService->getAllLocations() as $location) {
            if (str_contains($normalized, $this->normalizeText($location))) {
                $found[] = $location;
            }
        }

        $found = array_values(array_unique($found));

        if (count($found) >= 2) {
            return [$found[0], $found[1]];
        }

        return [null, null];
    }

    /**
     * @return array<int, string>
     */
    private function parsePassengerNames(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $parts = preg_split('/,|;|\s+dan\s+/ui', $text) ?: [];
        $parts = array_map(fn ($item) => trim((string) $item), $parts);
        $parts = array_filter($parts, fn ($item) => $item !== '');

        return array_values($parts);
    }

    private function extractPhoneNumber(string $text): ?string
    {
        $compact = preg_replace('/\s+/', '', $text) ?? $text;

        if (preg_match('/\b(?:\+?62|0)8[0-9]{7,13}\b/', $compact, $match)) {
            return $match[0];
        }

        return null;
    }

    // ─── Misc helpers ──────────────────────────────────────────────────────────

    private function buildDepartureDateOptions(int $days = 7): array
    {
        $timezone = config('chatbot.jet.timezone', 'Asia/Jakarta');
        $start    = now($timezone)->startOfDay();

        $dayNames = [
            'Sunday'    => 'Minggu',
            'Monday'    => 'Senin',
            'Tuesday'   => 'Selasa',
            'Wednesday' => 'Rabu',
            'Thursday'  => 'Kamis',
            'Friday'    => 'Jumat',
            'Saturday'  => 'Sabtu',
        ];

        $monthNames = [
            1  => 'Januari',
            2  => 'Februari',
            3  => 'Maret',
            4  => 'April',
            5  => 'Mei',
            6  => 'Juni',
            7  => 'Juli',
            8  => 'Agustus',
            9  => 'September',
            10 => 'Oktober',
            11 => 'November',
            12 => 'Desember',
        ];

        $options = [];

        for ($i = 0; $i < $days; $i++) {
            $date       = $start->copy()->addDays($i);
            $englishDay = $date->format('l');
            $dayLabel   = $dayNames[$englishDay] ?? $englishDay;
            $monthLabel = $monthNames[(int) $date->format('n')] ?? $date->format('F');

            $label = sprintf(
                '%s, %02d %s %s',
                $dayLabel,
                (int) $date->format('d'),
                $monthLabel,
                $date->format('Y')
            );

            $options[] = [
                'value' => $date->format('Y-m-d'),
                'label' => $label,
            ];
        }

        return $options;
    }

    private function buildDepartureDateMenuText(int $days = 7): string
    {
        $options = $this->buildDepartureDateOptions($days);
        $lines   = ['Pilihan tanggal keberangkatan:'];

        foreach ($options as $index => $option) {
            $lines[] = ($index + 1).'. '.$option['label'];
        }

        $lines[] = '';
        $lines[] = 'Silakan balas dengan angka pilihan, tanggal lengkap, atau format YYYY-MM-DD.';

        return implode("\n", $lines);
    }

    private function buildDepartureDateInteractiveList(int $days = 7): array
    {
        $options = $this->buildDepartureDateOptions($days);
        $rows    = [];

        foreach ($options as $option) {
            $rows[] = [
                'id'          => 'departure_date:'.$option['value'],
                'title'       => mb_substr((string) $option['label'], 0, 24),
                'description' => (string) $option['value'],
            ];
        }

        // WhatsApp API limit: max 10 rows per section — 7 days fits in 1 section
        return [
            'button'   => 'Pilih Tanggal',
            'header'   => 'Tanggal Keberangkatan',
            'body'     => 'Silakan pilih tanggal keberangkatan yang diinginkan.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => [
                [
                    'title' => '7 Hari Ke Depan',
                    'rows'  => $rows,
                ],
            ],
        ];
    }

    private function buildDepartureTimeInteractiveList(): array
    {
        $rows = [];

        foreach ($this->bookingRuleService->getDepartureTimes() as $slot) {
            $time  = substr((string) ($slot['time'] ?? ''), 0, 5);
            $label = (string) ($slot['label'] ?? $time);
            $rows[] = [
                'id'          => 'departure_time:'.$time,
                'title'       => mb_substr($label, 0, 24),
                'description' => $time.' WIB',
            ];
        }

        return [
            'button'   => 'Pilih Jam',
            'header'   => 'Jam Keberangkatan',
            'body'     => 'Silakan pilih jam keberangkatan yang diinginkan.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => [
                [
                    'title' => 'Pilihan Jam',
                    'rows'  => $rows,
                ],
            ],
        ];
    }

    private function buildPassengerCountInteractiveList(): array
    {
        $rows = [];

        for ($i = 1; $i <= 6; $i++) {
            $desc = $i <= 5 ? "{$i} orang penumpang" : "{$i} orang (perlu konfirmasi admin)";
            $rows[] = [
                'id'          => 'passenger_count_'.$i,
                'title'       => $i.' Orang',
                'description' => $desc,
            ];
        }

        return [
            'button'   => 'Pilih Jumlah',
            'header'   => 'Jumlah Penumpang',
            'body'     => 'Silakan pilih jumlah penumpang untuk keberangkatan ini.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => [
                [
                    'title' => 'Jumlah Penumpang',
                    'rows'  => $rows,
                ],
            ],
        ];
    }

    private function buildSeatInteractiveList(array $excludeSeats = [], ?string $passengerName = null): array
    {
        $seatLabels = (array) config('chatbot.jet.seat_labels', ['CC', 'BS Kiri', 'BS Kanan', 'BS Tengah', 'Belakang Kiri', 'Belakang Kanan']);
        $requiresConfirmation = (array) config('chatbot.jet.seat_requires_admin_confirmation', ['BS Tengah']);
        $rows = [];

        foreach ($seatLabels as $seat) {
            // Skip already selected seats
            if (in_array($seat, $excludeSeats, true)) {
                continue;
            }

            $description = in_array($seat, $requiresConfirmation, true)
                ? 'Perlu konfirmasi Admin'
                : null;

            $rows[] = [
                'id'          => 'seat:'.$this->normalizeSelectionValue($seat),
                'title'       => mb_substr((string) $seat, 0, 24),
                'description' => $description,
            ];
        }

        $bodyText = $passengerName !== null
            ? 'Silakan pilih seat tempat duduk '.$passengerName.' yang tersedia.'
            : 'Silakan pilih seat tempat duduk yang diinginkan.';

        return [
            'button'   => 'Pilih Seat',
            'header'   => 'Pilihan Seat',
            'body'     => $bodyText,
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => [
                [
                    'title' => 'Seat Tersedia',
                    'rows'  => $rows,
                ],
            ],
        ];
    }

    /**
     * @param  array<int, string>  $allLocations
     * @param  string  $prefix
     * @param  string  $description
     * @return array<int, array{title: string, rows: array<int, array{id: string, title: string, description: string}>}>
     */
    private function buildLocationSections(array $allLocations, string $prefix, string $description): array
    {
        $section1Locations = array_slice($allLocations, 0, 9);
        $section2Locations = array_slice($allLocations, 9, 2);
        $section3Locations = array_slice($allLocations, 11);

        $buildRows = function (array $locations) use ($prefix, $description): array {
            $rows = [];

            foreach ($locations as $location) {
                $rows[] = [
                    'id'          => $prefix.':'.$this->normalizeSelectionValue($location),
                    'title'       => mb_substr((string) $location, 0, 24),
                    'description' => $description,
                ];
            }

            return $rows;
        };

        $sections = [];

        if ($section1Locations !== []) {
            $sections[] = [
                'title' => 'Rambah Area',
                'rows'  => $buildRows($section1Locations),
            ];
        }

        if ($section2Locations !== []) {
            $sections[] = [
                'title' => 'Ujung Batu',
                'rows'  => $buildRows($section2Locations),
            ];
        }

        if ($section3Locations !== []) {
            $sections[] = [
                'title' => 'Kampar/Pekanbaru',
                'rows'  => $buildRows($section3Locations),
            ];
        }

        return $sections;
    }

    private function buildPickupPointInteractiveList(): array
    {
        $allLocations = $this->fareService->getAllLocations();

        return [
            'button'   => 'Pilih Jemput',
            'header'   => 'Titik Penjemputan',
            'body'     => 'Izin Bapak/Ibu, silakan pilih titik penjemputannya.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => $this->buildLocationSections(
                allLocations: $allLocations,
                prefix: 'pickup_location',
                description: 'Titik jemput',
            ),
        ];
    }

    private function buildDropoffPointInteractiveList(): array
    {
        $allLocations = $this->fareService->getAllLocations();

        return [
            'button'   => 'Pilih Tujuan',
            'header'   => 'Tujuan Pengantaran',
            'body'     => 'Untuk pengantarannya ke mana, Bapak/Ibu? Silakan pilih lokasinya.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => $this->buildLocationSections(
                allLocations: $allLocations,
                prefix: 'dropoff_location',
                description: 'Tujuan antar',
            ),
        ];
    }

    private function buildChangeFieldInteractiveList(): array
    {
        $fields = [
            ['id' => 'change_field:departure_date',  'title' => '1. Tanggal Keberangkatan', 'description' => 'Ubah tanggal'],
            ['id' => 'change_field:departure_time',  'title' => '2. Jam Keberangkatan',     'description' => 'Ubah jam'],
            ['id' => 'change_field:passenger_count', 'title' => '3. Jumlah Penumpang',      'description' => 'Ubah jumlah penumpang'],
            ['id' => 'change_field:seat',            'title' => '4. Seat',                  'description' => 'Ubah pilihan seat'],
            ['id' => 'change_field:pickup_point',    'title' => '5. Titik Jemput',          'description' => 'Ubah titik penjemputan'],
            ['id' => 'change_field:pickup_address',  'title' => '6. Alamat Jemput',         'description' => 'Ubah alamat penjemputan'],
            ['id' => 'change_field:dropoff_point',   'title' => '7. Tujuan Antar',          'description' => 'Ubah tujuan pengantaran'],
            ['id' => 'change_field:dropoff_address', 'title' => '8. Alamat Antar',          'description' => 'Ubah alamat pengantaran'],
            ['id' => 'change_field:passenger_names', 'title' => '9. Nama Penumpang',        'description' => 'Ubah nama penumpang'],
            ['id' => 'change_field:contact_number',  'title' => '10. No HP',                'description' => 'Ubah nomor kontak'],
        ];

        $rows = array_map(static fn ($f) => [
            'id'          => $f['id'],
            'title'       => mb_substr($f['title'], 0, 24),
            'description' => $f['description'],
        ], $fields);

        return [
            'button'   => 'Pilih Bagian',
            'header'   => 'Ubah Data Booking',
            'body'     => 'Silakan pilih bagian data yang ingin diubah.',
            'footer'   => 'JET Travel Rokan Hulu',
            'sections' => [
                [
                    'title' => 'Data Booking',
                    'rows'  => $rows,
                ],
            ],
        ];
    }

    private function extractSelectableDepartureDate(string $text): ?array
    {
        $normalized = trim(mb_strtolower($text, 'UTF-8'));
        $options    = $this->buildDepartureDateOptions();

        foreach ($options as $index => $option) {
            $number = (string) ($index + 1);
            $label  = trim(mb_strtolower((string) $option['label'], 'UTF-8'));
            $value  = trim((string) $option['value']);

            if ($normalized === $number || $normalized === $label || $normalized === $value) {
                return $option;
            }

            if (str_contains($normalized, $value)) {
                return $option;
            }
        }

        if (preg_match('/\b(\d{4}-\d{2}-\d{2})\b/', $normalized, $matches) === 1) {
            $picked = $matches[1] ?? null;

            foreach ($options as $option) {
                if (($option['value'] ?? null) === $picked) {
                    return $option;
                }
            }
        }

        return null;
    }

    private function extractSelectedDepartureDateFromInteractive(string $text): ?array
    {
        $normalized = trim($text);

        if (! str_starts_with($normalized, 'departure_date:')) {
            return null;
        }

        $value = trim(substr($normalized, strlen('departure_date:')));
        if ($value === '') {
            return null;
        }

        foreach ($this->buildDepartureDateOptions() as $option) {
            if (($option['value'] ?? null) === $value) {
                return $option;
            }
        }

        return null;
    }

    private function extractSelectedDepartureTimeFromInteractive(string $text): ?array
    {
        $normalized = trim($text);

        if (! str_starts_with($normalized, 'departure_time:')) {
            return null;
        }

        $value = trim(substr($normalized, strlen('departure_time:')));
        if ($value === '') {
            return null;
        }

        return $this->bookingRuleService->findDepartureTime($value);
    }

    private function extractSelectedSeatFromInteractive(string $text): ?string
    {
        $normalized = trim($text);

        if (! str_starts_with($normalized, 'seat:')) {
            return null;
        }

        $value = trim(substr($normalized, strlen('seat:')));
        if ($value === '') {
            return null;
        }

        $seatLabels = (array) config('chatbot.jet.seat_labels', ['CC', 'BS', 'Tengah', 'Belakang Kiri', 'Belakang Kanan', 'Belakang Sekali']);

        foreach ($seatLabels as $seat) {
            if ($this->normalizeSelectionValue($seat) === $value) {
                return $seat;
            }
        }

        return null;
    }

    private function extractSelectedPickupLocationFromInteractive(string $text): ?string
    {
        $normalized = trim($text);

        if (! str_starts_with($normalized, 'pickup_location:')) {
            return null;
        }

        $value = trim(substr($normalized, strlen('pickup_location:')));
        if ($value === '') {
            return null;
        }

        foreach ($this->fareService->getAllLocations() as $location) {
            if ($this->normalizeSelectionValue($location) === $value) {
                return $location;
            }
        }

        return null;
    }

    private function extractSelectedDropoffLocationFromInteractive(string $text): ?string
    {
        $normalized = trim($text);

        if (! str_starts_with($normalized, 'dropoff_location:')) {
            return null;
        }

        $value = trim(substr($normalized, strlen('dropoff_location:')));
        if ($value === '') {
            return null;
        }

        foreach ($this->fareService->getAllLocations() as $location) {
            if ($this->normalizeSelectionValue($location) === $value) {
                return $location;
            }
        }

        return null;
    }

    private function normalizeSelectionValue(string $value): string
    {
        $normalized = mb_strtolower(trim($value), 'UTF-8');
        $normalized = preg_replace('/[^\p{L}\p{N}\s\-]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', '_', $normalized) ?? $normalized;

        return trim($normalized, '_');
    }

    private function buildDepartureMenuText(): string
    {
        $lines = [];

        foreach ($this->bookingRuleService->getDepartureTimes() as $index => $slot) {
            $number = $index + 1;
            $label  = $slot['label'] ?? 'Jadwal';
            $time   = substr((string) ($slot['time'] ?? ''), 0, 5);
            $lines[] = "{$number}. {$label} ({$time} WIB)";
        }

        return implode("\n", $lines);
    }

    private function buildEditModeReturnToReview(array $state): array
    {
        $state['booking_edit_mode'] = false;
        $state['current_step']      = 'ask_review_confirmation';

        return $this->buildResult(
            replyText: $this->buildBookingReviewText($state['booking_data']),
            intent: 'booking_review',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_review_confirmation', 'edit_mode_return' => true],
        );
    }

    private function buildBookingReviewText(array $bookingData): string
    {
        $departureDateValue = trim((string) ($bookingData['departure_date'] ?? ''));
        $departureDateLabel = trim((string) ($bookingData['departure_date_label'] ?? ''));
        $departureTimeValue = trim((string) ($bookingData['departure_time'] ?? ''));
        $departureTimeLabel = trim((string) ($bookingData['departure_time_label'] ?? ''));
        $passengerCount     = trim((string) ($bookingData['passenger_count'] ?? ''));
        $seat               = trim((string) ($bookingData['seat'] ?? ''));
        $pickupPoint        = trim((string) ($bookingData['pickup_point'] ?? ''));
        $pickupAddress      = trim((string) ($bookingData['pickup_address'] ?? ''));
        $dropoffPoint       = trim((string) ($bookingData['dropoff_point'] ?? ''));
        $dropoffAddress     = trim((string) ($bookingData['dropoff_address'] ?? ''));
        $contactNumber      = trim((string) ($bookingData['contact_number'] ?? ''));

        $passengerNamesRaw = $bookingData['passenger_names'] ?? [];
        $passengerNames = is_array($passengerNamesRaw)
            ? array_values(array_filter(array_map(
                static fn ($item) => trim((string) $item),
                $passengerNamesRaw
            )))
            : [];

        $departureDateDisplay = $departureDateLabel !== ''
            ? $departureDateLabel
            : ($departureDateValue !== '' ? $this->formatDepartureDateForReview($departureDateValue) : '-');

        $departureTimeDisplay = $departureTimeValue !== ''
            ? $departureTimeValue.' WIB'
            : ($departureTimeLabel !== '' ? $departureTimeLabel : '-');

        $passengerNamesDisplay = '-';
        if ($passengerNames !== []) {
            $selectedSeats = is_array($bookingData['selected_seats'] ?? null) ? $bookingData['selected_seats'] : [];
            $nameWithSeatParts = [];
            foreach ($passengerNames as $index => $name) {
                $seatForPassenger = $selectedSeats[$index] ?? '-';
                $nameWithSeatParts[] = ($index + 1).'. '.$name.' (Seat: '.$seatForPassenger.')';
            }
            $passengerNamesDisplay = "\n        ".implode("\n        ", $nameWithSeatParts);
        }

        // Calculate fare
        $fareDisplay = '-';
        if ($pickupPoint !== '' && $dropoffPoint !== '') {
            $fare = $this->fareService->findFare($pickupPoint, $dropoffPoint);
            if ($fare !== null) {
                $unitFare = $fare['unit_fare'];
                $count = max(1, (int) $passengerCount);
                $totalFare = $unitFare * $count;
                $fareDisplay = $this->fareService->formatRupiah($totalFare);
                if ($count > 1) {
                    $fareDisplay .= ' ('.$count.' x '.$this->fareService->formatRupiah($unitFare).')';
                }
            }
        }

        $lines = [
            'Baik Bapak/Ibu, berikut review booking perjalanannya:',
            '',
            'Tanggal keberangkatan : '.$departureDateDisplay,
            'Jam keberangkatan     : '.$departureTimeDisplay,
            'Jumlah penumpang      : '.($passengerCount !== '' ? $passengerCount.' orang' : '-'),
            'Seat terpilih         : '.($seat !== '' ? $seat : '-'),
            'Titik jemput          : '.($pickupPoint !== '' ? $pickupPoint : '-'),
            'Alamat jemput         : '.($pickupAddress !== '' ? $pickupAddress : '-'),
            'Tujuan antar          : '.($dropoffPoint !== '' ? $dropoffPoint : '-'),
            'Alamat antar          : '.($dropoffAddress !== '' ? $dropoffAddress : '-'),
            'Nama penumpang        : '.$passengerNamesDisplay,
            'No HP                 : '.($contactNumber !== '' ? $contactNumber : '-'),
            'Ongkos perjalanan     : '.$fareDisplay.' (Ongkos akan dikonfirmasi ulang Admin Utama, mengingat untuk menyesuaikan Lokasi Jemput dan Pengantaran)',
            '',
            'Izin konfirmasi Bapak/Ibu, apakah data perjalanan ini sudah tepat?',
            '',
            '1. Benar',
            '2. Ubah Data',
            '',
            'Pilih Benar jika datanya sudah sesuai, atau Ubah Data jika masih ada yang perlu diperbaiki.',
        ];

        return implode("\n", $lines);
    }

    private function formatDepartureDateForReview(string $date): string
    {
        if (trim($date) === '') {
            return '-';
        }

        try {
            $carbon = Carbon::parse($date, config('chatbot.jet.timezone', 'Asia/Jakarta'));

            $dayNames = [
                'Sunday'    => 'Minggu',
                'Monday'    => 'Senin',
                'Tuesday'   => 'Selasa',
                'Wednesday' => 'Rabu',
                'Thursday'  => 'Kamis',
                'Friday'    => 'Jumat',
                'Saturday'  => 'Sabtu',
            ];

            $monthNames = [
                1  => 'Januari',
                2  => 'Februari',
                3  => 'Maret',
                4  => 'April',
                5  => 'Mei',
                6  => 'Juni',
                7  => 'Juli',
                8  => 'Agustus',
                9  => 'September',
                10 => 'Oktober',
                11 => 'November',
                12 => 'Desember',
            ];

            $dayLabel   = $dayNames[$carbon->format('l')] ?? $carbon->format('l');
            $monthLabel = $monthNames[(int) $carbon->format('n')] ?? $carbon->format('F');

            return sprintf(
                '%s, %02d %s %s',
                $dayLabel,
                (int) $carbon->format('d'),
                $monthLabel,
                $carbon->format('Y')
            );
        } catch (\Throwable) {
            return $date;
        }
    }

    private function combineDepartureDateTime(string $date, string $time): ?string
    {
        if (trim($date) === '' || trim($time) === '') {
            return null;
        }

        try {
            return Carbon::parse(
                $date.' '.$time,
                config('chatbot.jet.timezone', 'Asia/Jakarta'),
            )->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    private function parseStateDepartureDatetime(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('chatbot.jet.timezone', 'Asia/Jakarta'));
        } catch (\Throwable) {
            return null;
        }
    }

    private function normalizeState(array $state): array
    {
        return array_merge([
            'status'                      => 'idle',
            'current_step'                => null,
            'booking_data'                => [],
            'schedule_change_data'        => [],
            'last_admin_notification_key' => null,
            'last_completed_booking_at'   => null,
            'departure_datetime'          => null,
            'first_follow_up_sent_at'     => null,
            'booking_edit_mode'           => false,
        ], $state);
    }

    private function resolveNow(mixed $now): Carbon
    {
        $tz = config('chatbot.jet.timezone', 'Asia/Jakarta');

        if ($now instanceof Carbon) {
            return $now->copy()->setTimezone($tz);
        }

        if (is_string($now) && trim($now) !== '') {
            try {
                return Carbon::parse($now, $tz);
            } catch (\Throwable) {
                // fall through
            }
        }

        return now($tz);
    }

    private function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = str_replace(["\u{2019}", "'"], '', $text);
        $text = preg_replace('/[^\p{L}\p{N}\s:\/\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    /**
     * @param  array<int, array<string, mixed>>  $actions
     * @param  array<string, mixed>              $meta
     * @return array<string, mixed>
     */
    private function buildResult(
        string $replyText,
        string $intent,
        array $state,
        array $actions = [],
        array $meta = [],
    ): array {
        return [
            'reply_text' => $replyText,
            'intent'     => $intent,
            'actions'    => $actions,
            'new_state'  => $state,
            'meta'       => $meta,
        ];
    }
}