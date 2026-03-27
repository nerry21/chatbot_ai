<?php

namespace App\Services\Booking;

use App\Enums\BookingFlowState;
use App\Models\BookingRequest;
use Illuminate\Support\Carbon;

class BookingReplyNaturalizerService
{
    /**
     * @var array<string, string>
     */
    private const SLOT_LABELS = [
        'pickup_location' => 'titik jemput',
        'destination' => 'tujuan',
        'passenger_name' => 'nama penumpang',
        'passenger_count' => 'jumlah penumpang',
        'travel_date' => 'tanggal keberangkatan',
        'travel_time' => 'jam berangkat',
        'payment_method' => 'metode pembayaran',
    ];

    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly RouteValidationService $routeValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $currentSlots
     * @param  array<string, mixed>  $updates
     * @return array<int, string>
     */
    public function correctionLines(array $currentSlots, array $updates): array
    {
        $changes = [];

        foreach ($updates as $slot => $newValue) {
            $changes[$slot] = [
                'old' => $currentSlots[$slot] ?? null,
                'new' => $newValue,
            ];
        }

        return $this->correctionLinesFromChanges($changes);
    }

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<int, string>
     */
    public function correctionLinesFromChanges(array $changes): array
    {
        $lines = [];

        foreach (self::SLOT_LABELS as $slot => $label) {
            if (! array_key_exists($slot, $changes)) {
                continue;
            }

            $oldValue = $changes[$slot]['old'] ?? null;
            $newValue = $changes[$slot]['new'] ?? null;

            if ($this->sameValue($oldValue, $newValue) || $this->isBlank($oldValue)) {
                continue;
            }

            $value = $this->displayValue($slot, $newValue);
            $lines[] = $this->chooseVariant(
                'correction_line.'.$slot,
                [$oldValue, $newValue],
                [
                    'Baik, saya update '.$label.' jadi '.$value.'.',
                    'Siap, saya ubah '.$label.' jadi '.$value.'.',
                    'Oke, saya sesuaikan '.$label.' jadi '.$value.'.',
                ],
            );
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function captureBlock(array $updates): ?string
    {
        $lines = [];
        foreach (self::SLOT_LABELS as $slot => $label) {
            if (! array_key_exists($slot, $updates)) {
                continue;
            }
            $lines[] = '- '.$label.': '.$this->displayValue($slot, $updates[$slot]);
        }
        if ($lines === []) {
            return null;
        }
        $intro = $this->chooseVariant(
            'capture_block_intro',
            array_keys($updates),
            [
                'Baik, saya catat dulu ya:',
                'Siap, saya catat ya:',
                'Oke, saya rangkum dulu datanya ya:',
            ],
        );

        return implode("\n", array_merge([$intro], $lines));
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function captureSummary(array $updates): ?string
    {
        $trackedUpdates = array_intersect_key($updates, self::SLOT_LABELS);

        if ($trackedUpdates === []) {
            return null;
        }

        if (count($trackedUpdates) > 3) {
            return $this->captureBlock($trackedUpdates);
        }

        $fragments = [];

        foreach ($trackedUpdates as $slot => $value) {
            $fragments[] = $this->slotSummaryFragment($slot, $value);
        }

        return $this->chooseVariant(
            'capture_summary',
            array_keys($trackedUpdates),
            [
                'Baik, saya catat '.$this->joinLabels($fragments).' ya.',
                'Siap, saya catat '.$this->joinLabels($fragments).' ya.',
                'Oke, saya catat '.$this->joinLabels($fragments).' ya.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $capturedUpdates
     * @param  array<int, string>  $correctionLines
     */
    public function naturalizeRuleReply(
        array $capturedUpdates,
        array $correctionLines,
        string $prompt,
        ?string $routeLine = null,
        ?string $priceLine = null,
        ?string $scheduleLine = null,
    ): string {
        $parts = $correctionLines;
        $captureSummary = $this->captureSummary($capturedUpdates);

        if ($captureSummary !== null) {
            $parts[] = $captureSummary;
        }

        $facts = array_values(array_filter([
            $routeLine,
            $priceLine,
            $this->shouldIncludeScheduleLine($scheduleLine, $prompt) ? $scheduleLine : null,
        ]));

        $parts[] = $this->mergeFactsWithPrompt($facts, $prompt);

        return $this->composeReplyParts($parts);
    }

    /**
     * @param  array<string, mixed>  $capturedUpdates
     * @param  array<int, string>  $correctionLines
     */
    public function naturalizeUnsupportedRuleReply(
        array $capturedUpdates,
        array $correctionLines,
        string $unsupportedReply,
    ): string {
        $parts = $correctionLines;
        $captureSummary = $this->captureSummary($capturedUpdates);

        if ($captureSummary !== null) {
            $parts[] = $captureSummary;
        }

        $parts[] = $unsupportedReply;

        return $this->composeReplyParts($parts);
    }

    public function routeAvailableLine(?string $pickup, ?string $destination): ?string
    {
        if (blank($pickup) || blank($destination)) {
            return null;
        }

        return $this->chooseVariant(
            'route_available',
            [$pickup, $destination],
            [
                'Rute '.$pickup.' ke '.$destination.' tersedia ya.',
                'Untuk rute '.$pickup.' ke '.$destination.' tersedia ya.',
                'Rute '.$pickup.' ke '.$destination.' saat ini tersedia ya.',
            ],
        );
    }

    /**
     * @param  array<int, string>  $suggestions
     */
    public function unsupportedRouteReply(
        ?string $pickup,
        ?string $destination,
        array $suggestions,
        string $focusSlot,
    ): string {
        if ($focusSlot === 'pickup_location' && filled($pickup) && filled($destination)) {
            $text = $this->chooseVariant(
                'unsupported.pickup',
                [$pickup, $destination],
                [
                    'Mohon maaf ya, untuk penjemputan dari '.$pickup.' ke '.$destination.' saat ini belum tersedia.',
                    'Mohon maaf, untuk penjemputan dari '.$pickup.' ke '.$destination.' saat ini belum tersedia ya.',
                ],
            );
        } elseif ($focusSlot === 'destination' && filled($pickup) && filled($destination)) {
            $text = $this->chooseVariant(
                'unsupported.destination',
                [$pickup, $destination],
                [
                    'Mohon maaf ya, untuk tujuan '.$destination.' dari '.$pickup.' saat ini belum tersedia.',
                    'Mohon maaf, untuk tujuan '.$destination.' dari '.$pickup.' saat ini belum tersedia ya.',
                ],
            );
        } else {
            $parts = array_filter([$pickup, $destination]);
            $text = $this->chooseVariant(
                'unsupported.route',
                $parts,
                [
                    'Mohon maaf ya, untuk rute '.implode(' ke ', $parts).' saat ini belum tersedia.',
                    'Mohon maaf, untuk rute '.implode(' ke ', $parts).' saat ini belum tersedia ya.',
                ],
            );
        }
        if ($suggestions !== []) {
            $label = $focusSlot === 'destination'
                ? 'Tujuan yang tersedia saat ini'
                : 'Titik jemput yang tersedia saat ini';
            $text .= "\n\n".$label.' antara lain '.$this->joinLabels($suggestions).'.';
        }
        $followUp = $focusSlot === 'destination'
            ? $this->chooseVariant(
                'unsupported.followup.destination',
                $suggestions,
                [
                    'Kalau mau lanjut, silakan kirim tujuan lain yang tersedia ya. Nanti saya bantu lanjutkan.',
                    'Kalau ingin saya cek lagi, kirim tujuan lain yang tersedia ya. Nanti saya bantu proses.',
                ],
            )
            : $this->chooseVariant(
                'unsupported.followup.pickup',
                $suggestions,
                [
                    'Kalau mau lanjut, silakan kirim titik jemput lain yang tersedia ya. Nanti saya bantu lanjutkan.',
                    'Kalau ingin saya cek lagi, kirim titik jemput lain yang tersedia ya. Nanti saya bantu proses.',
                ],
            );

        return $text."\n\n".$followUp;
    }

    /**
     * @param  array<int, string>  $missing
     * @param  array<string, mixed>  $slots
     */
    public function askBasicDetails(array $missing, array $slots): string
    {
        $hasPickup = filled($slots['pickup_location'] ?? null);
        $hasDestination = filled($slots['destination'] ?? null);
        if ($hasDestination && in_array('pickup_location', $missing, true)) {
            $labels = ['titik jemput'];
            if (in_array('passenger_name', $missing, true)) {
                $labels[] = 'nama penumpang';
            }
            if (in_array('passenger_count', $missing, true)) {
                $labels[] = 'jumlah penumpang';
            }

            return $this->chooseVariant(
                'ask_basic.pickup',
                [$missing, $slots['destination'] ?? null],
                [
                    'Baik, saya bantu cek ya. '.$this->detailRequest($labels, 'Supaya saya cek lebih lanjut'),
                    'Siap, saya bantu ya. '.$this->detailRequest($labels, 'Biar saya lanjut cek'),
                    'Oke, saya bantu lanjut ya. '.$this->detailRequest($labels, 'Supaya saya proses'),
                ],
            );
        }
        if ($hasPickup && in_array('destination', $missing, true)) {
            $labels = ['tujuan'];
            if (in_array('passenger_name', $missing, true)) {
                $labels[] = 'nama penumpang';
            }
            if (in_array('passenger_count', $missing, true)) {
                $labels[] = 'jumlah penumpang';
            }

            return $this->chooseVariant(
                'ask_basic.destination',
                [$missing, $slots['pickup_location'] ?? null],
                [
                    'Baik, saya bantu lanjut ya. '.$this->detailRequest($labels, 'Supaya saya cek lebih lanjut'),
                    'Siap, saya bantu ya. '.$this->detailRequest($labels, 'Biar saya lanjut proses'),
                    'Oke, saya bantu cek ya. '.$this->detailRequest($labels, 'Supaya saya lanjutkan'),
                ],
            );
        }
        if ($missing === ['pickup_location', 'destination']) {
            return $this->chooseVariant(
                'ask_basic.route_only',
                $missing,
                [
                    'Baik, saya bantu ya. Mohon kirim titik jemput dan tujuan perjalanannya.',
                    'Siap, saya bantu cek ya. Boleh kirim titik jemput dan tujuan perjalanannya dulu.',
                    'Oke, lanjut ya. Mohon kirim titik jemput dan tujuan perjalanannya.',
                ],
            );
        }
        if ($missing === ['passenger_name']) {
            return $this->chooseVariant(
                'ask_basic.passenger_name',
                $missing,
                [
                    'Baik, tinggal kirim nama penumpangnya ya.',
                    'Siap, tinggal kirim nama penumpangnya ya.',
                    'Oke, boleh kirim nama penumpangnya dulu ya.',
                ],
            );
        }
        if ($missing === ['passenger_count']) {
            return $this->chooseVariant(
                'ask_basic.passenger_count',
                $missing,
                [
                    'Baik, untuk keberangkatan ini ada berapa penumpang ya?',
                    'Siap, jumlah penumpangnya ada berapa orang ya?',
                    'Oke, boleh kirim jumlah penumpangnya ya.',
                ],
            );
        }
        if ($missing === ['passenger_name', 'passenger_count']) {
            return $this->chooseVariant(
                'ask_basic.passenger_name_count',
                $missing,
                [
                    'Baik, tinggal kirim nama penumpang dan jumlah penumpangnya ya.',
                    'Siap, boleh kirim nama penumpang dan jumlah penumpangnya dulu ya.',
                    'Oke, saya lanjut ya. Mohon kirim nama penumpang dan jumlah penumpangnya.',
                ],
            );
        }
        $labels = array_map(
            fn (string $slot) => self::SLOT_LABELS[$slot] ?? $slot,
            $missing,
        );

        return $this->chooseVariant(
            'ask_basic.default',
            $labels,
            [
                'Baik, supaya bisa saya proses, mohon kirim '.$this->joinLabels($labels).'.',
                'Siap, saya bantu ya. Boleh kirim '.$this->joinLabels($labels).' dulu.',
                'Oke, saya lanjutkan ya. Mohon kirim '.$this->joinLabels($labels).'.',
            ],
        );
    }

    public function askTravelDate(): string
    {
        return $this->chooseVariant(
            'ask_travel_date',
            [],
            [
                'Boleh kirim tanggal keberangkatannya ya?',
                'Tanggal keberangkatannya kapan ya?',
                'Untuk lanjut bookingnya, mohon kirim tanggal keberangkatannya ya.',
            ],
        );
    }

    public function askTravelTime(bool $ambiguous = false): string
    {
        $slots = '05.00, 08.00, 10.00, 14.00, 16.00, atau 19.00 WIB';
        if ($ambiguous) {
            return $this->chooseVariant(
                'ask_travel_time.ambiguous',
                [$ambiguous],
                [
                    'Baik, tanggalnya sudah saya catat. Biar tidak keliru, mohon kirim jam pastinya ya. Slot yang tersedia '.$slots.'.',
                    'Siap, tanggalnya sudah masuk ya. Supaya pas, mohon kirim jam yang dipilih. Slot yang tersedia '.$slots.'.',
                    'Oke, tanggalnya sudah saya catat. Sekarang mohon kirim jam keberangkatannya ya. Slot yang tersedia '.$slots.'.',
                ],
            );
        }

        return $this->chooseVariant(
            'ask_travel_time',
            [],
            [
                'Untuk jam berangkatnya, ingin yang jam berapa ya? Contoh: 08.00 atau 10.00.',
                'Jam keberangkatannya mau pilih yang jam berapa ya? Contoh: 08.00 atau 10.00.',
                'Boleh kirim jam keberangkatannya ya? Contoh: 08.00 atau 10.00.',
            ],
        );
    }

    public function askPaymentMethod(): string
    {
        return $this->chooseVariant(
            'ask_payment_method',
            [],
            [
                'Untuk pembayarannya, mau transfer bank, QRIS, atau cash ya?',
                'Metode pembayarannya ingin transfer bank, QRIS, atau cash ya?',
                'Baik, untuk pembayarannya pilih transfer bank, QRIS, atau cash ya?',
            ],
        );
    }

    public function askCorrection(): string
    {
        return $this->chooseVariant(
            'ask_correction',
            [],
            [
                'Baik, silakan kirim bagian data yang mau diubah ya. Nanti saya bantu update tanpa mulai dari awal.',
                'Siap, kirim saja data yang ingin diubah ya. Saya bantu perbarui tanpa mengulang dari awal.',
                'Oke, tinggal kirim bagian yang mau dikoreksi ya. Saya bantu update.',
            ],
        );
    }

    public function closing(): string
    {
        return $this->chooseVariant(
            'closing',
            [],
            [
                'Baik, terima kasih ya. Kalau nanti mau cek jadwal atau lanjut booking, tinggal chat lagi.',
                'Siap, terima kasih ya. Kalau ada yang mau dicek lagi, langsung kirim pesan saja.',
                'Oke, terima kasih ya. Kalau nanti ingin lanjut booking atau cek jadwal lain, tinggal hubungi lagi.',
            ],
        );
    }

    public function inProgressAcknowledgement(string $pendingPrompt): string
    {
        $nextStep = $this->normalizePendingPrompt($pendingPrompt);
        if ($nextStep === '') {
            $nextStep = 'tinggal kirim data lanjutnya ya.';
        }

        return $this->chooseVariant(
            'in_progress_acknowledgement',
            [$pendingPrompt],
            [
                'Baik, kalau sudah siap, '.$nextStep,
                'Siap, kalau sudah siap, '.$nextStep,
                'Oke, kalau sudah siap, '.$nextStep,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $signals
     * @param  array<int, string>  $routeSuggestions
     */
    public function fallbackForState(
        string $state,
        array $slots = [],
        ?string $pendingPrompt = null,
        array $signals = [],
        ?string $routeIssue = null,
        array $routeSuggestions = [],
    ): string {
        return match ($state) {
            BookingFlowState::CollectingRoute->value,
            BookingFlowState::CollectingPassenger->value,
            BookingFlowState::CollectingSchedule->value => $this->bookingFallbackFromPrompt(
                $pendingPrompt ?: $this->defaultFallbackPrompt($state, $slots),
            ),
            BookingFlowState::RouteUnavailable->value => $this->routeUnavailableFallback(
                $routeIssue ?? 'pickup_location',
                $routeSuggestions,
            ),
            BookingFlowState::ReadyToConfirm->value => $this->confirmationFallback(),
            BookingFlowState::Confirmed->value => $this->confirmedConversationFallback(),
            BookingFlowState::Closed->value => $this->closedConversationFallback($signals),
            default => $this->generalFallback($signals),
        };
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    public function generalFallback(array $signals = []): string
    {
        if (($signals['schedule_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'general_fallback.schedule',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu cek jadwal ya. Boleh kirim titik jemput, tujuan, dan tanggal keberangkatannya?',
                    'Siap, saya bantu cek jadwal. Mohon kirim titik jemput, tujuan, dan tanggal keberangkatannya ya.',
                    'Oke, saya bantu cek. Supaya pas, kirim titik jemput, tujuan, dan tanggal keberangkatannya ya.',
                ],
            );
        }

        if (($signals['price_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'general_fallback.price',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu cek harga ya. Mohon kirim titik jemput, tujuan, dan jumlah penumpangnya.',
                    'Siap, saya bantu cek harga. Boleh kirim titik jemput, tujuan, dan jumlah penumpangnya ya?',
                    'Oke, saya bantu cek tarifnya. Supaya tidak salah, kirim titik jemput, tujuan, dan jumlah penumpangnya ya.',
                ],
            );
        }

        if (($signals['booking_keyword'] ?? false) === true || ($signals['route_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'general_fallback.booking',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu. Supaya tidak salah, mohon kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya.',
                    'Siap, saya bantu ya. Boleh kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya dulu?',
                    'Oke, saya bantu lanjut. Mohon kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya ya.',
                ],
            );
        }

        return $this->chooseVariant(
            'general_fallback',
            array_keys(array_filter($signals)),
            [
                'Baik, saya bantu ya. Kalau terkait travel, boleh kirim rute, jadwal, atau detail yang ingin dicek?',
                'Siap, saya bantu cek ya. Silakan kirim detail perjalanan yang ingin ditanyakan.',
                'Oke, saya bantu. Boleh jelaskan lagi kebutuhan perjalanannya ya?',
            ],
        );
    }

    public function confirmed(): string
    {
        return $this->chooseVariant(
            'confirmed',
            [],
            [
                'Baik, data perjalanannya sudah saya catat ya. Admin kami lanjut hubungi Anda di WhatsApp ini untuk proses berikutnya.',
                'Siap, data bookingnya sudah masuk ya. Admin kami akan lanjut hubungi Anda lewat WhatsApp ini.',
                'Oke, data perjalanan sudah tercatat ya. Admin kami lanjut proses dan akan hubungi Anda di WhatsApp ini.',
            ],
        );
    }

    public function reviewSummary(BookingRequest $booking): string
    {
        $fare = $booking->price_estimate !== null
            ? (int) round((float) $booking->price_estimate)
            : $this->fareCalculator->calculate(
                $booking->pickup_location,
                $booking->destination,
                $booking->passenger_count,
            );
        $lines = [
            $this->chooseVariant(
                'review_summary_intro',
                [
                    $booking->pickup_location,
                    $booking->destination,
                    $booking->passenger_count,
                ],
                [
                    'Baik, saya rangkum dulu data perjalanannya ya:',
                    'Siap, saya rangkum dulu detail perjalanannya ya:',
                    'Oke, saya cek lagi data perjalanannya ya:',
                ],
            ),
            '- titik jemput: '.($booking->pickup_location ?? '-'),
            '- tujuan: '.($booking->destination ?? '-'),
            '- nama penumpang: '.($booking->passenger_name ?? '-'),
            '- jumlah penumpang: '.(($booking->passenger_count ?? 0) > 0 ? $booking->passenger_count.' orang' : '-'),
            '- tanggal keberangkatan: '.($booking->departure_date?->translatedFormat('d F Y') ?? '-'),
            '- jam berangkat: '.($booking->departure_time ? $booking->departure_time.' WIB' : '-'),
            '- metode pembayaran: '.$this->paymentMethodLabel($booking->payment_method),
        ];
        if ($fare !== null) {
            $lines[] = '- estimasi total: '.$this->fareCalculator->formatRupiah($fare);
        }
        $lines[] = '';
        $lines[] = $this->chooseVariant(
            'review_summary_confirm',
            [$booking->pickup_location, $booking->destination],
            [
                'Kalau datanya sudah sesuai, balas YA atau BENAR ya.',
                'Kalau sudah cocok, cukup balas YA atau BENAR ya.',
                'Kalau semuanya sudah benar, balas YA atau BENAR ya.',
            ],
        );
        $lines[] = $this->chooseVariant(
            'review_summary_edit',
            [$booking->pickup_location, $booking->destination],
            [
                'Kalau ada yang mau diubah, tinggal kirim bagian yang benar saja.',
                'Kalau ada yang perlu diubah, kirim koreksinya saja ya.',
                'Kalau masih ada yang salah, tinggal kirim perbaikannya ya.',
            ],
        );

        return implode("\n", $lines);
    }

    public function scheduleLine(): string
    {
        return $this->chooseVariant(
            'schedule_line',
            [],
            [
                'Jadwal keberangkatan tersedia setiap hari di jam 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.',
                'Untuk jadwal, kami tersedia setiap hari di jam 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.',
                'Jadwal travel tersedia setiap hari pada pukul 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.',
            ],
        );
    }

    public function priceLine(?string $pickup, ?string $destination, ?int $passengerCount = null): ?string
    {
        if (blank($pickup) || blank($destination)) {
            return null;
        }
        $unitFare = $this->fareCalculator->unitFare($pickup, $destination);
        if ($unitFare === null) {
            return null;
        }
        $text = $this->chooseVariant(
            'price_line',
            [$pickup, $destination, $passengerCount],
            [
                'Untuk rute '.$pickup.' ke '.$destination.', tarifnya saat ini '.$this->fareCalculator->formatRupiah($unitFare).' per penumpang.',
                'Tarif rute '.$pickup.' ke '.$destination.' saat ini '.$this->fareCalculator->formatRupiah($unitFare).' per penumpang.',
                'Kalau untuk rute '.$pickup.' ke '.$destination.', tarifnya '.$this->fareCalculator->formatRupiah($unitFare).' per penumpang.',
            ],
        );
        if (($passengerCount ?? 0) > 1) {
            $totalFare = $this->fareCalculator->calculate($pickup, $destination, $passengerCount);
            if ($totalFare !== null) {
                $text .= $this->chooseVariant(
                    'price_line_total',
                    [$passengerCount, $totalFare],
                    [
                        ' Estimasi total untuk '.$passengerCount.' penumpang sekitar '.$this->fareCalculator->formatRupiah($totalFare).'.',
                        ' Kalau '.$passengerCount.' penumpang, estimasi totalnya '.$this->fareCalculator->formatRupiah($totalFare).'.',
                        ' Total perkiraannya untuk '.$passengerCount.' penumpang sekitar '.$this->fareCalculator->formatRupiah($totalFare).'.',
                    ],
                );
            }
        }

        return $text;
    }

    public function routeListReply(): string
    {
        $locations = $this->routeValidator->menuLocations();

        return $this->chooseVariant(
            'route_list_reply',
            $locations,
            [
                'Titik jemput dan tujuan yang tersedia saat ini antara lain '.$this->joinLabels($locations).'. Kalau mau lanjut booking, silakan kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.',
                'Untuk titik jemput dan tujuan yang tersedia saat ini ada '.$this->joinLabels($locations).'. Kalau mau lanjut, kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.',
                'Lokasi yang tersedia saat ini antara lain '.$this->joinLabels($locations).'. Kalau ingin lanjut booking, tinggal kirim titik jemput, tujuan, atau tanggal keberangkatannya ya.',
            ],
        );
    }

    public function paymentMethodLabel(?string $value): string
    {
        if (blank($value)) {
            return '-';
        }
        foreach ((array) config('chatbot.jet.payment_methods', []) as $method) {
            if (($method['id'] ?? null) === $value) {
                return (string) ($method['label'] ?? $value);
            }
        }

        return mb_convert_case((string) $value, MB_CASE_TITLE, 'UTF-8');
    }

    private function detailRequest(array $labels, string $context): string
    {
        return $context.', mohon kirim '.$this->joinLabels($labels).'.';
    }

    private function normalizePendingPrompt(string $pendingPrompt): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $pendingPrompt) ?? $pendingPrompt);
        $text = $this->trimKnownLeads($text);

        return $this->lowercaseFirst($text);
    }

    private function bookingFallbackFromPrompt(string $prompt): string
    {
        $request = $this->fallbackRequestText($prompt);

        return $this->chooseVariant(
            'booking_state_fallback',
            [$prompt],
            [
                'Maaf, saya belum menangkap detailnya dengan jelas. '.$this->uppercaseFirst($request),
                'Baik, saya bantu. Supaya tidak salah, '.$this->lowercaseFirst($request),
                'Maaf ya, biar tidak keliru, '.$this->lowercaseFirst($request),
            ],
        );
    }

    /**
     * @param  array<int, string>  $suggestions
     */
    private function routeUnavailableFallback(string $routeIssue, array $suggestions = []): string
    {
        $target = $routeIssue === 'destination'
            ? 'tujuan lain yang tersedia'
            : 'titik jemput lain yang tersedia';

        $text = $this->chooseVariant(
            'route_unavailable_fallback.'.$routeIssue,
            [$routeIssue, $suggestions],
            [
                'Maaf, untuk lanjut saya perlu '.$target.' ya.',
                'Baik, supaya saya bisa cek lagi, mohon kirim '.$target.'.',
                'Maaf ya, biar saya bantu lanjut, kirim '.$target.' ya.',
            ],
        );

        if ($suggestions !== []) {
            $text .= ' Contohnya '.$this->joinLabels(array_slice($suggestions, 0, 4)).'.';
        }

        return $text;
    }

    private function confirmationFallback(): string
    {
        return $this->chooseVariant(
            'confirmation_fallback',
            [],
            [
                'Maaf, saya belum menangkap jawabannya dengan jelas. Kalau datanya sudah sesuai, balas YA atau BENAR ya. Kalau ada yang mau diubah, kirim bagian yang benar saja.',
                'Baik, supaya tidak salah, kalau datanya sudah sesuai balas YA atau BENAR ya. Kalau ada yang perlu diubah, kirim koreksinya saja.',
                'Maaf ya, biar jelas, kalau datanya sudah cocok balas YA atau BENAR ya. Kalau ada yang ingin diubah, kirim bagian yang benar saja.',
            ],
        );
    }

    private function confirmedConversationFallback(): string
    {
        return $this->chooseVariant(
            'confirmed_conversation_fallback',
            [],
            [
                'Baik, booking sebelumnya sudah kami catat ya. Kalau mau cek perjalanan lain, tinggal kirim rute dan tanggal keberangkatannya.',
                'Siap, data booking sebelumnya sudah masuk ya. Kalau ingin cek jadwal atau perjalanan lain, kirim detailnya saja.',
                'Oke, booking sebelumnya sudah tercatat ya. Kalau mau lanjut cek perjalanan lain, tinggal kirim rutenya.',
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $signals
     */
    private function closedConversationFallback(array $signals = []): string
    {
        if (($signals['booking_keyword'] ?? false) === true || ($signals['route_keyword'] ?? false) === true) {
            return $this->chooseVariant(
                'closed_fallback.booking',
                array_keys(array_filter($signals)),
                [
                    'Baik, saya bantu mulai lagi ya. Mohon kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya.',
                    'Siap, kalau mau lanjut lagi, boleh kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya ya.',
                    'Oke, saya bantu lanjut lagi. Kirim titik jemput, tujuan, nama penumpang, dan jumlah penumpangnya ya.',
                ],
            );
        }

        return $this->chooseVariant(
            'closed_fallback',
            array_keys(array_filter($signals)),
            [
                'Baik, kalau mau lanjut lagi, tinggal kirim detail perjalanan yang ingin dicek ya.',
                'Siap, kalau ada yang mau dicek lagi, langsung kirim rute atau jadwalnya ya.',
                'Oke, kalau ingin lanjut lagi, tinggal kirim detail perjalanannya ya.',
            ],
        );
    }

    private function defaultFallbackPrompt(string $state, array $slots): string
    {
        return match ($state) {
            BookingFlowState::CollectingRoute->value => $this->askBasicDetails(
                ['pickup_location', 'destination'],
                $slots,
            ),
            BookingFlowState::CollectingPassenger->value => $this->askBasicDetails(
                array_values(array_filter([
                    empty($slots['passenger_name']) ? 'passenger_name' : null,
                    empty($slots['passenger_count']) ? 'passenger_count' : null,
                ])),
                $slots,
            ),
            BookingFlowState::CollectingSchedule->value => $this->askTravelDate(),
            default => $this->askBasicDetails(['pickup_location', 'destination'], $slots),
        };
    }

    private function fallbackRequestText(string $prompt): string
    {
        $text = preg_replace('/^Tinggal\s+/ui', '', $this->followUpPrompt($prompt)) ?? $this->followUpPrompt($prompt);

        return rtrim(trim($text), '.').'.';
    }

    private function mergeFactsWithPrompt(array $facts, string $prompt): string
    {
        $facts = array_values(array_filter(array_map(
            fn (mixed $fact) => is_string($fact) ? trim($fact) : '',
            $facts,
        )));

        if ($facts === []) {
            return $prompt;
        }

        $lead = implode(' ', array_map(
            fn (string $fact) => rtrim($fact, ".!?\t\n\r\0\x0B").'.',
            $facts,
        ));

        return trim($lead.' '.$this->followUpPrompt($prompt));
    }

    private function followUpPrompt(string $prompt): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $prompt) ?? $prompt);
        $text = $this->trimKnownLeads($text);
        $text = preg_replace('/^(?:Supaya|Biar)\s+saya\s+(?:cek|lanjut\s+cek|lanjut\s+proses|proses|lanjutkan),\s*/ui', '', $text) ?? $text;
        $text = preg_replace('/^Untuk\s+lanjut\s+bookingnya,\s*/ui', '', $text) ?? $text;

        if (preg_match('/^Tanggal keberangkatannya kapan ya\?$/ui', $text)) {
            return 'Tinggal mohon kirim tanggal keberangkatannya ya.';
        }

        if (preg_match('/^(?:Untuk jam berangkatnya|Jam keberangkatannya).+?(Contoh:\s*.+)$/ui', $text, $matches)) {
            return 'Tinggal kirim jam keberangkatannya ya. '.$matches[1];
        }

        if (preg_match('/^Boleh kirim jam keberangkatannya ya\?\s*(Contoh:\s*.+)$/ui', $text, $matches)) {
            return 'Tinggal kirim jam keberangkatannya ya. '.$matches[1];
        }

        if (preg_match('/^(?:Untuk pembayarannya|Metode pembayarannya|Baik, untuk pembayarannya).+$/ui', $text)) {
            return 'Tinggal pilih metode pembayarannya ya: transfer bank, QRIS, atau cash.';
        }

        if (preg_match('/^(?:Mohon|Boleh)\s+kirim\b/ui', $text)) {
            $tail = preg_replace('/^(?:Mohon|Boleh)\s+kirim\s*/ui', '', $text) ?? $text;
            $tail = rtrim($tail, " \t\n\r\0\x0B?.").'.';

            return 'Tinggal mohon kirim '.$tail;
        }

        if (preg_match('/^tinggal\b/ui', $text)) {
            return 'Tinggal '.$this->lowercaseFirst(preg_replace('/^tinggal\s*/ui', '', $text) ?? $text);
        }

        return 'Tinggal '.$this->lowercaseFirst(rtrim($text, '.')).'.';
    }

    private function shouldIncludeScheduleLine(?string $scheduleLine, string $prompt): bool
    {
        if ($scheduleLine === null) {
            return false;
        }

        return ! (bool) preg_match('/\b(tanggal keberangkatan|jam berangkat|jam keberangkatan)\b/ui', $prompt);
    }

    /**
     * @param  array<int, string>  $parts
     */
    private function composeReplyParts(array $parts): string
    {
        return implode("\n\n", array_values(array_filter(array_map(
            fn (mixed $part) => is_string($part) ? trim($part) : '',
            $parts,
        ))));
    }

    private function trimKnownLeads(string $text): string
    {
        $knownLeads = [
            'Baik, saya bantu ya. ',
            'Baik, saya bantu cek ya. ',
            'Baik, saya bantu lanjut ya. ',
            'Baik, saya bantu lanjutkan ya. ',
            'Siap, saya bantu ya. ',
            'Siap, saya bantu cek ya. ',
            'Siap, saya bantu lanjut ya. ',
            'Siap, saya bantu lanjutkan ya. ',
            'Oke, saya bantu ya. ',
            'Oke, saya bantu cek ya. ',
            'Oke, saya bantu lanjut ya. ',
            'Oke, saya bantu lanjutkan ya. ',
            'Baik, tinggal ',
            'Siap, tinggal ',
            'Oke, tinggal ',
        ];

        foreach ($knownLeads as $lead) {
            if (str_starts_with($text, $lead)) {
                return substr($text, strlen($lead));
            }
        }

        return $text;
    }

    private function displayValue(string $slot, mixed $value): string
    {
        return match ($slot) {
            'passenger_count' => (int) $value.' orang',
            'travel_date' => $this->formatDateValue($value),
            'travel_time' => (string) $value.' WIB',
            'payment_method' => $this->paymentMethodLabel(is_string($value) ? $value : null),
            default => (string) $value,
        };
    }

    private function slotSummaryFragment(string $slot, mixed $value): string
    {
        $display = $this->displayValue($slot, $value);

        return match ($slot) {
            'pickup_location' => 'titik jemput '.$display,
            'destination' => 'tujuan '.$display,
            'passenger_name' => 'nama penumpang '.$display,
            'passenger_count' => 'jumlah '.$display,
            'travel_date' => 'tanggal '.$display,
            'travel_time' => 'jam '.$display,
            'payment_method' => 'metode pembayaran '.$display,
            default => (self::SLOT_LABELS[$slot] ?? $slot).' '.$display,
        };
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter(array_map(
            fn (mixed $label) => is_string($label) ? trim($label) : '',
            $labels,
        )));
        if ($labels === []) {
            return '';
        }
        if (count($labels) === 1) {
            return $labels[0];
        }
        if (count($labels) === 2) {
            return $labels[0].' dan '.$labels[1];
        }
        $last = array_pop($labels);

        return implode(', ', $labels).', dan '.$last;
    }

    private function lowercaseFirst(string $text): string
    {
        if ($text === '') {
            return '';
        }
        $first = mb_substr($text, 0, 1, 'UTF-8');
        $rest = mb_substr($text, 1, null, 'UTF-8');

        return mb_strtolower($first, 'UTF-8').$rest;
    }

    private function uppercaseFirst(string $text): string
    {
        if ($text === '') {
            return '';
        }

        $first = mb_substr($text, 0, 1, 'UTF-8');
        $rest = mb_substr($text, 1, null, 'UTF-8');

        return mb_strtoupper($first, 'UTF-8').$rest;
    }

    private function formatDateValue(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return (string) $value;
        }

        try {
            return Carbon::parse($value)->translatedFormat('d F Y');
        } catch (\Throwable) {
            return $value;
        }
    }

    /**
     * @param  array<int|string, mixed>  $context
     * @param  array<int, string>  $variants
     */
    private function chooseVariant(string $group, array $context, array $variants): string
    {
        if ($variants === []) {
            return '';
        }
        if (count($variants) === 1) {
            return $variants[0];
        }
        $payload = json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $hash = sha1($group.'|'.$payload);
        $index = hexdec(substr($hash, 0, 6)) % count($variants);

        return $variants[$index];
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($left) === json_encode($right);
    }

    private function isBlank(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value)) {
            return trim($value) === '';
        }
        if (is_array($value)) {
            return $value === [];
        }

        return false;
    }
}
