<?php

namespace App\Services\Chatbot;

use Carbon\Carbon;

class TravelMessageRouterService
{
    public function __construct(
        protected TravelGreetingService $greetingService,
        protected TravelFareService $fareService,
        protected TravelBookingRuleService $bookingRuleService,
        protected TravelFaqMatcherService $faqMatcherService,
    ) {
    }

    /**
     * Input contract:
     * [
     *   'text' => 'Saya mau booking',
     *   'phone' => '62812xxxx',
     *   'state' => [
     *      'status' => 'idle|booking|booking_confirmed|schedule_change',
     *      'current_step' => null|string,
     *      'booking_data' => [],
     *      'last_admin_notification_key' => null|string,
     *      'last_completed_booking_at' => null|string,
     *      'departure_datetime' => null|string,
     *      'first_follow_up_sent_at' => null|string,
     *   ],
     *   'now' => Carbon|string|null,
     * ]
     *
     * Output contract:
     * [
     *   'reply_text' => '...',
     *   'intent' => '...',
     *   'actions' => [
     *      ['type' => 'notify_admin', 'message' => '...'],
     *      ['type' => 'save_state'],
     *   ],
     *   'new_state' => [...],
     *   'meta' => [...],
     * ]
     */
    public function route(array $payload): array
    {
        $text = trim((string) ($payload['text'] ?? ''));
        $phone = trim((string) ($payload['phone'] ?? ''));
        $state = $this->normalizeState((array) ($payload['state'] ?? []));
        $now = $this->resolveNow($payload['now'] ?? null);

        if ($text === '') {
            return $this->buildResult(
                replyText: $this->faqMatcherService->buildFallbackCustomerMessage(),
                intent: 'empty_message',
                state: $state,
                actions: [],
                meta: ['reason' => 'empty_text']
            );
        }

        /**
         * Salam / pembuka fleksibel:
         * - Assalamualaikum
         * - Assalamualaikum selamat pagi
         * - Halo, pagi
         * tapi hanya jika belum masuk pertanyaan bisnis spesifik.
         */
        if (
            $this->looksLikeGreetingMessage($text)
            && ! $this->looksLikeBusinessQuestion($text)
            && $state['status'] === 'idle'
        ) {
            return $this->handleGreetingOnly($text, $state, $now);
        }

        /**
         * Kalau user selesai booking lalu chat lagi, tawarkan:
         * pesan lagi atau ubah jadwal.
         */
        if ($this->shouldOfferRepeatBookingOrScheduleChange($state, $text)) {
            return $this->handleRepeatBookingOrScheduleChangeQuestion($state);
        }

        /**
         * Perubahan jadwal.
         */
        if ($this->isScheduleChangeMessage($text)) {
            return $this->handleScheduleChangeStart($text, $phone, $state, $now);
        }

        if ($state['status'] === 'schedule_change') {
            return $this->handleScheduleChangeFlow($text, $phone, $state, $now);
        }

        /**
         * Booking start.
         */
        if ($this->isBookingStartMessage($text) && $state['status'] === 'idle') {
            return $this->handleBookingStart($state);
        }

        /**
         * Kalau sedang di flow booking, lanjutkan.
         */
        if ($state['status'] === 'booking') {
            return $this->handleBookingFlow($text, $phone, $state, $now);
        }

        /**
         * Tanya tarif.
         */
        if ($this->looksLikeFareQuestion($text)) {
            $fareResponse = $this->tryHandleFareQuestion($text, $state);
            if ($fareResponse !== null) {
                return $fareResponse;
            }
        }

        /**
         * Tanya jadwal sederhana.
         * Contoh:
         * - Apakah ada keberangkatan jam 10 pagi?
         * - Jam 8 ada?
         * - Besok ada jadwal malam?
         */
        if ($this->looksLikeSimpleScheduleQuestion($text)) {
            return $this->handleSimpleScheduleQuestion($text, $state);
        }

        /**
         * FAQ matcher.
         */
        $faqMatch = $this->faqMatcherService->match($text);
        if ($faqMatch !== null && ($faqMatch['score'] ?? 0) >= 35) {
            return $this->buildResult(
                replyText: $faqMatch['answer'],
                intent: (string) $faqMatch['intent'],
                state: $state,
                actions: [['type' => 'save_state']],
                meta: ['faq_match' => $faqMatch]
            );
        }

        /**
         * Kalau tidak cocok apa pun, baru fallback ke admin.
         */
        return $this->handleFallbackToAdmin($phone, $state, $text);
    }

    protected function handleGreetingOnly(string $text, array $state, Carbon $now): array
    {
        $reply = $this->greetingService->buildOpeningGreeting($text, $now);

        /**
         * Buat sedikit lebih natural.
         */
        $reply = $this->makeGreetingMoreNatural($reply);

        return $this->buildResult(
            replyText: $reply,
            intent: $this->greetingService->shouldReplyIslamicGreeting($text)
                ? 'greeting_islamic'
                : 'greeting_general',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['greeting_label' => $this->greetingService->getGreetingLabel($now)]
        );
    }

    protected function handleBookingStart(array $state): array
    {
        $state['status'] = 'booking';
        $state['current_step'] = 'ask_passenger_count';
        $state['booking_data'] = [];

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.ask_passenger_count'),
            intent: 'start_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['booking_started' => true]
        );
    }

    protected function handleBookingFlow(string $text, string $phone, array $state, Carbon $now): array
    {
        $step = $state['current_step'] ?? 'ask_passenger_count';

        return match ($step) {
            'ask_passenger_count' => $this->handlePassengerCountStep($text, $state),
            'ask_departure_time_and_date' => $this->handleDepartureStep($text, $state),
            'ask_seat' => $this->handleSeatStep($text, $state),
            'ask_pickup_point' => $this->handlePickupPointStep($text, $state),
            'ask_pickup_address' => $this->handlePickupAddressStep($text, $state),
            'ask_dropoff_point' => $this->handleDropoffPointStep($text, $state),
            'ask_passenger_name' => $this->handlePassengerNameStep($text, $state),
            'ask_contact_number' => $this->handleContactStep($text, $state, $phone),
            'ask_review_confirmation' => $this->handleReviewConfirmationStep($text, $phone, $state, $now),
            default => $this->resetBookingToFirstQuestion($state),
        };
    }

    protected function handlePassengerCountStep(string $text, array $state): array
    {
        $count = $this->extractPassengerCount($text);

        if ($count === null) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu isi jumlah penumpangnya terlebih dahulu.',
                intent: 'passenger_count',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_passenger_count']
            );
        }

        $validation = $this->bookingRuleService->validatePassengerCount($count);
        if (! $validation['valid']) {
            return $this->buildResult(
                replyText: $validation['message'],
                intent: 'passenger_count_invalid',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_passenger_count']
            );
        }

        $state['booking_data']['passenger_count'] = $count;
        $state['booking_data']['requires_passenger_confirmation'] = $validation['requires_confirmation'];
        $state['current_step'] = 'ask_departure_time_and_date';

        $reply = '';
        if ($validation['requires_confirmation']) {
            $reply .= $validation['message'] . ' ';
        }
        $reply .= (string) config('chatbot.booking.ask_departure_time_and_date');

        return $this->buildResult(
            replyText: trim($reply),
            intent: 'passenger_count',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_departure_time_and_date']
        );
    }

    protected function handleDepartureStep(string $text, array $state): array
    {
        $date = $this->extractDateText($text);
        $time = $this->bookingRuleService->findDepartureTime($text);

        if ($date === null || $time === null) {
            $choices = $this->buildDepartureMenuText();

            return $this->buildResult(
                replyText: "Izin Bapak/Ibu, mohon dibantu isi tanggal dan pilih jam keberangkatannya.\n\n{$choices}",
                intent: 'departure_time_date',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_departure_time_and_date']
            );
        }

        $state['booking_data']['departure_date'] = $date;
        $state['booking_data']['departure_time'] = $time['time'];
        $state['booking_data']['departure_time_label'] = $time['label'];
        $state['current_step'] = 'ask_seat';

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.ask_seat'),
            intent: 'departure_time_date',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: [
                'step' => 'ask_seat',
                'departure_time' => $time,
            ]
        );
    }

    protected function handleSeatStep(string $text, array $state): array
    {
        $seat = trim($text);

        if ($seat === '') {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon pilih atau tuliskan seat yang diinginkan.',
                intent: 'seat',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_seat']
            );
        }

        $state['booking_data']['seat'] = $seat;
        $state['current_step'] = 'ask_pickup_point';

        $reply = (string) config('chatbot.booking.ask_pickup_point') . "\n\n" . $this->fareService->buildLocationListText();

        return $this->buildResult(
            replyText: $reply,
            intent: 'seat',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_pickup_point']
        );
    }

    protected function handlePickupPointStep(string $text, array $state): array
    {
        $location = $this->extractLocation($text);

        if ($location === null) {
            return $this->buildResult(
                replyText: "Izin Bapak/Ibu, mohon pilih titik penjemputannya.\n\n" . $this->fareService->buildLocationListText(),
                intent: 'pickup_location',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_pickup_point']
            );
        }

        $state['booking_data']['pickup_point'] = $location;
        $state['current_step'] = 'ask_pickup_address';

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.ask_pickup_address'),
            intent: 'pickup_location',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_pickup_address']
        );
    }

    protected function handlePickupAddressStep(string $text, array $state): array
    {
        $address = trim($text);

        if ($address === '') {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu alamat lengkap penjemputannya.',
                intent: 'pickup_address',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_pickup_address']
            );
        }

        $state['booking_data']['pickup_address'] = $address;
        $state['current_step'] = 'ask_dropoff_point';

        $reply = (string) config('chatbot.booking.ask_dropoff_point') . "\n\n" . $this->fareService->buildLocationListText();

        return $this->buildResult(
            replyText: $reply,
            intent: 'dropoff_location',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_dropoff_point']
        );
    }

    protected function handleDropoffPointStep(string $text, array $state): array
    {
        $location = $this->extractLocation($text);

        if ($location === null) {
            return $this->buildResult(
                replyText: "Izin Bapak/Ibu, mohon pilih tujuan pengantarannya.\n\n" . $this->fareService->buildLocationListText(),
                intent: 'dropoff_location',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_dropoff_point']
            );
        }

        $state['booking_data']['dropoff_point'] = $location;
        $state['current_step'] = 'ask_passenger_name';

        $count = (int) ($state['booking_data']['passenger_count'] ?? 1);
        $question = $count > 1
            ? (string) config('chatbot.booking.ask_passenger_name_multi')
            : (string) config('chatbot.booking.ask_passenger_name_single');

        return $this->buildResult(
            replyText: $question,
            intent: 'dropoff_location',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_passenger_name']
        );
    }

    protected function handlePassengerNameStep(string $text, array $state): array
    {
        $names = $this->parsePassengerNames($text);

        if ($names === []) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu isi nama penumpangnya.',
                intent: 'passenger_name',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_passenger_name']
            );
        }

        $state['booking_data']['passenger_names'] = $names;
        $state['current_step'] = 'ask_contact_number';

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.ask_contact_number'),
            intent: 'passenger_name',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_contact_number']
        );
    }

    protected function handleContactStep(string $text, array $state, string $phone): array
    {
        $normalized = $this->normalizeText($text);

        if ($normalized === 'sama') {
            $state['booking_data']['contact_number'] = $phone;
        } else {
            $number = $this->extractPhoneNumber($text);
            if ($number === null) {
                return $this->buildResult(
                    replyText: 'Izin Bapak/Ibu, jika nomor kontaknya berbeda mohon dibantu kirim nomor HP-nya. Jika sama, cukup ketik "sama".',
                    intent: 'contact_confirmation',
                    state: $state,
                    actions: [],
                    meta: ['step' => 'ask_contact_number']
                );
            }

            $state['booking_data']['contact_number'] = $number;
        }

        $state['current_step'] = 'ask_review_confirmation';

        $review = $this->bookingRuleService->buildBookingReview($state['booking_data'], $this->fareService);

        return $this->buildResult(
            replyText: $review,
            intent: 'booking_review',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_review_confirmation']
        );
    }

    protected function handleReviewConfirmationStep(string $text, string $phone, array $state, Carbon $now): array
    {
        if (! $this->bookingRuleService->isConfirmationText($text)) {
            return $this->buildResult(
                replyText: 'Baik Bapak/Ibu, jika ada yang perlu diperbaiki silakan sampaikan data mana yang ingin diubah.',
                intent: 'booking_review_unconfirmed',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_review_confirmation']
            );
        }

        $adminMessage = $this->buildAdminBookingMessage($phone, $state['booking_data'] ?? []);

        $state['status'] = 'booking_confirmed';
        $state['current_step'] = null;
        $state['last_completed_booking_at'] = $now->toDateTimeString();
        $state['departure_datetime'] = $this->combineDepartureDateTime(
            (string) ($state['booking_data']['departure_date'] ?? ''),
            (string) ($state['booking_data']['departure_time'] ?? '')
        );

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.post_confirmation_message'),
            intent: 'booking_confirmed',
            state: $state,
            actions: [
                ['type' => 'notify_admin', 'message' => $adminMessage, 'channel' => 'main_admin'],
                ['type' => 'save_state'],
            ],
            meta: ['admin_code' => 'Booking Baru']
        );
    }

    protected function handleScheduleChangeStart(string $text, string $phone, array $state, Carbon $now): array
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
                    meta: ['schedule_change_check' => $check]
                );
            }
        }

        $state['status'] = 'schedule_change';
        $state['current_step'] = 'ask_change_time_or_date';
        $state['schedule_change_data'] = [];

        return $this->buildResult(
            replyText: (string) config('chatbot.schedule_change.ask_change_time_or_date'),
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_time_or_date']
        );
    }

    protected function handleScheduleChangeFlow(string $text, string $phone, array $state, Carbon $now): array
    {
        $step = $state['current_step'] ?? 'ask_change_time_or_date';

        return match ($step) {
            'ask_change_time_or_date' => $this->handleChangeTimeOrDateStep($text, $state),
            'ask_change_seat' => $this->handleChangeSeatStep($text, $state),
            'ask_change_pickup_address' => $this->handleChangePickupAddressStep($text, $state),
            'ask_change_dropoff_point' => $this->handleChangeDropoffPointStep($text, $state),
            'ask_schedule_change_confirmation' => $this->handleScheduleChangeConfirmationStep($text, $phone, $state),
            default => $this->buildResult(
                replyText: (string) config('chatbot.schedule_change.ask_change_time_or_date'),
                intent: 'change_schedule',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_change_time_or_date']
            ),
        };
    }

    protected function handleChangeTimeOrDateStep(string $text, array $state): array
    {
        $date = $this->extractDateText($text);
        $time = $this->bookingRuleService->findDepartureTime($text);

        if ($date === null && $time === null) {
            return $this->buildResult(
                replyText: 'Izin Bapak/Ibu, mohon dibantu perubahan tanggal atau jam keberangkatannya.',
                intent: 'change_schedule',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_change_time_or_date']
            );
        }

        if ($date !== null) {
            $state['schedule_change_data']['departure_date'] = $date;
        }

        if ($time !== null) {
            $state['schedule_change_data']['departure_time'] = $time['time'];
            $state['schedule_change_data']['departure_time_label'] = $time['label'];
        }

        $state['current_step'] = 'ask_change_seat';

        return $this->buildResult(
            replyText: (string) config('chatbot.schedule_change.ask_change_seat'),
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_seat']
        );
    }

    protected function handleChangeSeatStep(string $text, array $state): array
    {
        $state['schedule_change_data']['seat'] = trim($text);
        $state['current_step'] = 'ask_change_pickup_address';

        return $this->buildResult(
            replyText: (string) config('chatbot.schedule_change.ask_change_pickup_address'),
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_pickup_address']
        );
    }

    protected function handleChangePickupAddressStep(string $text, array $state): array
    {
        $state['schedule_change_data']['pickup_address'] = trim($text);
        $state['current_step'] = 'ask_change_dropoff_point';

        return $this->buildResult(
            replyText: (string) config('chatbot.schedule_change.ask_change_dropoff_point'),
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_change_dropoff_point']
        );
    }

    protected function handleChangeDropoffPointStep(string $text, array $state): array
    {
        $location = $this->extractLocation($text) ?? trim($text);
        $state['schedule_change_data']['dropoff_point'] = $location;
        $state['current_step'] = 'ask_schedule_change_confirmation';

        $review = $this->bookingRuleService->buildScheduleChangeReview(
            array_merge((array) ($state['booking_data'] ?? []), (array) ($state['schedule_change_data'] ?? [])),
            $this->fareService
        );

        return $this->buildResult(
            replyText: $review . "\n\n" . (string) config('chatbot.booking.ask_review_confirmation'),
            intent: 'change_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['step' => 'ask_schedule_change_confirmation']
        );
    }

    protected function handleScheduleChangeConfirmationStep(string $text, string $phone, array $state): array
    {
        if (! $this->bookingRuleService->isConfirmationText($text)) {
            return $this->buildResult(
                replyText: 'Baik Bapak/Ibu, silakan sampaikan bagian perubahan mana yang masih ingin diperbaiki.',
                intent: 'change_schedule_unconfirmed',
                state: $state,
                actions: [],
                meta: ['step' => 'ask_schedule_change_confirmation']
            );
        }

        $key = $this->buildScheduleChangeAdminKey($phone, $state);
        $actions = [['type' => 'save_state']];
        $meta = ['admin_code' => (string) config('chatbot.schedule_change.admin_code', 'Perubahan Jadwal')];

        if (($state['last_admin_notification_key'] ?? null) !== $key) {
            $actions[] = [
                'type' => 'notify_admin',
                'channel' => 'main_admin',
                'message' => $this->buildAdminScheduleChangeMessage($phone, $state),
            ];
            $state['last_admin_notification_key'] = $key;
        }

        $state['status'] = 'booking_confirmed';
        $state['current_step'] = null;

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.post_confirmation_message'),
            intent: 'change_schedule_confirmed',
            state: $state,
            actions: $actions,
            meta: $meta
        );
    }

    protected function handleRepeatBookingOrScheduleChangeQuestion(array $state): array
    {
        return $this->buildResult(
            replyText: (string) config('chatbot.repeat_booking.question'),
            intent: 'repeat_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['offer_repeat_booking' => true]
        );
    }

    protected function tryHandleFareQuestion(string $text, array $state): ?array
    {
        [$origin, $destination] = $this->extractOriginDestination($text);

        if ($origin !== null && $destination !== null) {
            $fareText = $this->fareService->getFareText($origin, $destination);

            if ($fareText !== null) {
                return $this->buildResult(
                    replyText: $fareText,
                    intent: 'ask_fare',
                    state: $state,
                    actions: [['type' => 'save_state']],
                    meta: compact('origin', 'destination')
                );
            }
        }

        return null;
    }

    protected function handleSimpleScheduleQuestion(string $text, array $state): array
    {
        $normalized = $this->normalizeText($text);

        $message = 'Jadwal keberangkatan yang tersedia adalah 05.00 WIB, 08.00 WIB, 10.00 WIB, 14.00 WIB, 16.00 WIB, dan 19.00 WIB. Jika Bapak/Ibu ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';

        if (str_contains($normalized, 'jam 10')) {
            $message = 'Untuk jadwal pagi tersedia pukul 08.00 WIB dan 10.00 WIB. Jadi untuk jam 10 pagi tersedia, Bapak/Ibu. Jika ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';
        } elseif (str_contains($normalized, 'jam 8')) {
            $message = 'Untuk jadwal pagi tersedia pukul 08.00 WIB dan 10.00 WIB. Jadi untuk jam 8 pagi tersedia, Bapak/Ibu. Jika ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';
        } elseif (str_contains($normalized, 'jam 5')) {
            $message = 'Untuk jadwal subuh tersedia pukul 05.00 WIB. Jika ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';
        } elseif (str_contains($normalized, 'jam 2')) {
            $message = 'Untuk jadwal siang tersedia pukul 14.00 WIB. Jika ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';
        } elseif (str_contains($normalized, 'jam 4')) {
            $message = 'Untuk jadwal sore tersedia pukul 16.00 WIB. Jika ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';
        } elseif (str_contains($normalized, 'jam 7')) {
            $message = 'Untuk jadwal malam tersedia pukul 19.00 WIB. Jika ingin lanjut booking, izin dibantu untuk berapa orang jumlah penumpangnya?';
        }

        return $this->buildResult(
            replyText: $message,
            intent: 'ask_schedule',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['schedule_answered' => true]
        );
    }

    protected function handleFallbackToAdmin(string $phone, array $state, string $text): array
    {
        $customerReply = $this->faqMatcherService->buildFallbackCustomerMessage();
        $adminMessage = $this->faqMatcherService->buildFallbackAdminMessage($phone);

        $key = 'fallback:' . md5($phone . '|' . $this->normalizeText($text));
        $actions = [['type' => 'save_state']];

        if (($state['last_admin_notification_key'] ?? null) !== $key) {
            $actions[] = [
                'type' => 'notify_admin',
                'channel' => 'main_admin',
                'message' => $adminMessage,
            ];
            $state['last_admin_notification_key'] = $key;
        }

        return $this->buildResult(
            replyText: $customerReply,
            intent: 'fallback_admin',
            state: $state,
            actions: $actions,
            meta: ['fallback' => true]
        );
    }

    protected function resetBookingToFirstQuestion(array $state): array
    {
        $state['status'] = 'booking';
        $state['current_step'] = 'ask_passenger_count';

        return $this->buildResult(
            replyText: (string) config('chatbot.booking.ask_passenger_count'),
            intent: 'start_booking',
            state: $state,
            actions: [['type' => 'save_state']],
            meta: ['reset_booking' => true]
        );
    }

    protected function buildAdminBookingMessage(string $phone, array $bookingData): string
    {
        $review = $this->bookingRuleService->buildBookingReview($bookingData, $this->fareService);

        return "Booking Baru\nNomor Customer: {$phone}\n\n{$review}";
    }

    protected function buildAdminScheduleChangeMessage(string $phone, array $state): string
    {
        $code = (string) config('chatbot.schedule_change.admin_code', 'Perubahan Jadwal');
        $data = array_merge((array) ($state['booking_data'] ?? []), (array) ($state['schedule_change_data'] ?? []));
        $review = $this->bookingRuleService->buildScheduleChangeReview($data, $this->fareService);

        return "{$code}\nNomor Customer: {$phone}\n\n{$review}";
    }

    protected function buildScheduleChangeAdminKey(string $phone, array $state): string
    {
        return 'schedule_change:' . md5($phone . '|' . json_encode($state['schedule_change_data'] ?? []));
    }

    protected function combineDepartureDateTime(string $date, string $time): ?string
    {
        if (trim($date) === '' || trim($time) === '') {
            return null;
        }

        try {
            return Carbon::parse($date . ' ' . $time, config('chatbot.timezone', 'Asia/Jakarta'))->toDateTimeString();
        } catch (\Throwable) {
            return null;
        }
    }

    protected function parseStateDepartureDatetime(?string $value): ?Carbon
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value, config('chatbot.timezone', 'Asia/Jakarta'));
        } catch (\Throwable) {
            return null;
        }
    }

    protected function shouldOfferRepeatBookingOrScheduleChange(array $state, string $text): bool
    {
        if (($state['status'] ?? 'idle') !== 'booking_confirmed') {
            return false;
        }

        $normalized = $this->normalizeText($text);

        return in_array($normalized, ['halo', 'hai', 'pagi', 'siang', 'sore', 'malam', 'assalamualaikum'], true)
            || str_contains($normalized, 'pesan lagi')
            || str_contains($normalized, 'booking lagi');
    }

    protected function isBookingStartMessage(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            'mau booking',
            'mau pesan',
            'pesan travel',
            'booking',
            'reservasi',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function isScheduleChangeMessage(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            'ubah jadwal',
            'ganti jadwal',
            'perubahan jadwal',
            'ubah tanggal',
            'ubah jam',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeFareQuestion(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $patterns = ['ongkos', 'harga', 'tarif', 'berapa'];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeGreetingMessage(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            'assalamualaikum',
            'halo',
            'hai',
            'selamat pagi',
            'selamat siang',
            'selamat sore',
            'selamat malam',
            'pagi',
            'siang',
            'sore',
            'malam',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeBusinessQuestion(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        $patterns = [
            'booking',
            'pesan',
            'jadwal',
            'keberangkatan',
            'jam 5',
            'jam 8',
            'jam 10',
            'jam 2',
            'jam 4',
            'jam 7',
            'ongkos',
            'tarif',
            'seat',
            'kursi',
            'jemput',
            'antar',
            'ubah jadwal',
        ];

        foreach ($patterns as $pattern) {
            if (str_contains($normalized, $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function looksLikeSimpleScheduleQuestion(string $text): bool
    {
        $normalized = $this->normalizeText($text);

        if (
            str_contains($normalized, 'keberangkatan')
            || str_contains($normalized, 'jadwal')
            || str_contains($normalized, 'berangkat')
        ) {
            return true;
        }

        if (
            str_contains($normalized, 'jam 5')
            || str_contains($normalized, 'jam 8')
            || str_contains($normalized, 'jam 10')
            || str_contains($normalized, 'jam 2')
            || str_contains($normalized, 'jam 4')
            || str_contains($normalized, 'jam 7')
        ) {
            return true;
        }

        return false;
    }

    protected function makeGreetingMoreNatural(string $reply): string
    {
        $reply = str_replace(
            'Izin Bapak/Ibu, kalau boleh tahu ada keperluan apa menghubungi JET (Jasa Executive Travel)?',
            'Ada yang bisa kami bantu untuk perjalanannya, Bapak/Ibu?',
            $reply
        );

        return trim($reply);
    }

    protected function extractPassengerCount(string $text): ?int
    {
        if (preg_match('/\b([1-9][0-9]?)\b/u', $text, $matches)) {
            return (int) $matches[1];
        }

        $normalized = $this->normalizeText($text);

        $map = [
            'satu' => 1,
            'dua' => 2,
            'tiga' => 3,
            'empat' => 4,
            'lima' => 5,
            'enam' => 6,
        ];

        foreach ($map as $word => $number) {
            if (str_contains($normalized, $word)) {
                return $number;
            }
        }

        return null;
    }

    protected function extractDateText(string $text): ?string
    {
        $text = trim($text);

        if (preg_match('/\b\d{4}-\d{2}-\d{2}\b/', $text, $match)) {
            return $match[0];
        }

        if (preg_match('/\b\d{1,2}[\/\-]\d{1,2}[\/\-]\d{2,4}\b/', $text, $match)) {
            return $match[0];
        }

        $normalized = $this->normalizeText($text);

        if (str_contains($normalized, 'hari ini')) {
            return now(config('chatbot.timezone', 'Asia/Jakarta'))->toDateString();
        }

        if (str_contains($normalized, 'besok')) {
            return now(config('chatbot.timezone', 'Asia/Jakarta'))->addDay()->toDateString();
        }

        return null;
    }

    protected function extractLocation(string $text): ?string
    {
        $normalized = $this->normalizeText($text);

        foreach ($this->fareService->getAllLocations() as $location) {
            if (str_contains($normalized, $this->normalizeText($location))) {
                return $location;
            }
        }

        return null;
    }

    protected function parsePassengerNames(string $text): array
    {
        $text = trim($text);

        if ($text === '') {
            return [];
        }

        $parts = preg_split('/,|;| dan /u', $text) ?: [];
        $parts = array_map(fn ($item) => trim((string) $item), $parts);
        $parts = array_filter($parts, fn ($item) => $item !== '');

        return array_values($parts);
    }

    protected function extractPhoneNumber(string $text): ?string
    {
        if (preg_match('/\b(?:\+?62|0)8[0-9]{7,13}\b/', preg_replace('/\s+/', '', $text) ?? $text, $match)) {
            return $match[0];
        }

        return null;
    }

    protected function extractOriginDestination(string $text): array
    {
        $normalized = $this->normalizeText($text);
        $locations = $this->fareService->getAllLocations();

        $found = [];
        foreach ($locations as $location) {
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

    protected function buildDepartureMenuText(): string
    {
        $lines = [];
        foreach ($this->bookingRuleService->getDepartureTimes() as $index => $item) {
            $number = $index + 1;
            $label = $item['label'] ?? 'Jadwal';
            $time = substr((string) ($item['time'] ?? ''), 0, 5);
            $lines[] = "{$number}. {$label} ({$time} WIB)";
        }

        return implode("\n", $lines);
    }

    protected function normalizeState(array $state): array
    {
        return array_merge([
            'status' => 'idle',
            'current_step' => null,
            'booking_data' => [],
            'schedule_change_data' => [],
            'last_admin_notification_key' => null,
            'last_completed_booking_at' => null,
            'departure_datetime' => null,
            'first_follow_up_sent_at' => null,
        ], $state);
    }

    protected function resolveNow(mixed $now): Carbon
    {
        if ($now instanceof Carbon) {
            return $now->copy()->setTimezone(config('chatbot.timezone', 'Asia/Jakarta'));
        }

        if (is_string($now) && trim($now) !== '') {
            return Carbon::parse($now, config('chatbot.timezone', 'Asia/Jakarta'));
        }

        return now(config('chatbot.timezone', 'Asia/Jakarta'));
    }

    protected function normalizeText(string $text): string
    {
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/[^\p{L}\p{N}\s:\/\-]/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return trim($text);
    }

    protected function buildResult(
        string $replyText,
        string $intent,
        array $state,
        array $actions = [],
        array $meta = []
    ): array {
        return [
            'reply_text' => $replyText,
            'intent' => $intent,
            'actions' => $actions,
            'new_state' => $state,
            'meta' => $meta,
        ];
    }
}