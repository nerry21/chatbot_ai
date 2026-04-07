<?php

namespace App\Services\Booking;

use App\Models\BookingRequest;
use App\Services\Chatbot\ResponseVariationService;
use Illuminate\Support\Carbon;

class BookingReplyNaturalizerService
{
    private const SLOT_LABELS = [
        'passenger_count' => 'jumlah penumpang',
        'travel_date' => 'tanggal keberangkatan',
        'travel_time' => 'jam keberangkatan',
        'selected_seats' => 'seat terpilih',
        'pickup_location' => 'lokasi penjemputan',
        'pickup_full_address' => 'alamat jemput',
        'destination' => 'lokasi pengantaran',
        'destination_full_address' => 'alamat tujuan lengkap',
        'passenger_name' => 'nama penumpang',
        'passenger_names' => 'nama penumpang',
        'contact_number' => 'nomor kontak penumpang',
    ];

    public function __construct(
        private readonly FareCalculatorService $fareCalculator,
        private readonly RouteValidationService $routeValidator,
        private readonly BookingReviewFormatterService $reviewFormatter,
        private readonly ResponseVariationService $variation,
    ) {}

    /**
     * @param  array<string, array{old: mixed, new: mixed}>  $changes
     * @return array<int, string>
     */
    public function correctionLinesFromChanges(array $changes): array
    {
        $lines = [];

        foreach ($changes as $slot => $change) {
            if ($this->isBlank($change['old'] ?? null)) {
                continue;
            }

            $label = self::SLOT_LABELS[$slot] ?? $slot;
            $lines[] = 'Baik, saya update '.$label.' menjadi '.$this->displayValue($slot, $change['new']).'.';
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $updates
     */
    public function captureSummary(array $updates): ?string
    {
        $parts = [];

        foreach ($updates as $slot => $value) {
            if (! array_key_exists($slot, self::SLOT_LABELS)) {
                continue;
            }

            $parts[] = self::SLOT_LABELS[$slot].' '.$this->displayValue($slot, $value);
        }

        if ($parts === []) {
            return null;
        }

        return 'Baik, saya catat '.$this->joinLabels($parts).' ya.';
    }

    /**
     * @param  array<int, string>  $parts
     */
    public function compose(array $parts): string
    {
        return implode("\n\n", array_values(array_filter(array_map(
            fn (mixed $part): string => is_string($part) ? trim($part) : '',
            $parts,
        ))));
    }

    public function askPassengerCount(?string $seed = null): string
    {
        return $this->variation->pick([
            'Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya?',
            'Izin Bapak/Ibu, untuk perjalanan ini ada berapa penumpang ya?',
            'Boleh dibantu, untuk keberangkatan ini ada berapa orang penumpangnya ya?',
        ], $seed);
    }

    public function manualConfirmationNotice(): string
    {
        return 'Izin Bapak/Ibu, untuk 6 penumpang perlu kami bantu konfirmasi dahulu ke admin ya.';
    }

    public function askTravelDate(?string $seed = null): string
    {
        return $this->variation->pick([
            'Izin Bapak/Ibu, untuk keberangkatannya mohon pilih tanggalnya dulu ya.',
            'Izin Bapak/Ibu, rencananya berangkat tanggal berapa ya? Silakan pilih tanggalnya.',
            'Boleh dibantu, untuk keberangkatannya mohon pilih tanggal dulu ya.',
        ], $seed);
    }

    public function askTravelTime(bool $ambiguous = false): string
    {
        $slots = 'Subuh 05.00 WIB, Pagi 08.00 WIB, Pagi 10.00 WIB, Siang 14.00 WIB, Sore 16.00 WIB, atau Malam 19.00 WIB';

        if ($ambiguous) {
            return 'Izin Bapak/Ibu, supaya tidak keliru mohon pilih jam keberangkatannya ya. Pilih salah satu: '.$slots.'.';
        }

        return 'Izin Bapak/Ibu, untuk jam keberangkatannya mohon pilih salah satu yang tersedia ya. Pilihannya '.$slots.'.';
    }

    /**
     * @param  array<int, string>  $availableSeats
     */
    public function availableSeatsLine(string $travelTime, array $availableSeats): string
    {
        return 'Izin Bapak/Ibu, untuk ketersediaan seat di jam '.$travelTime.' WIB saat ini sisa '.$this->joinLabels($availableSeats).'.';
    }

    /**
     * @param  array<int, array{id?: string, label?: string, time?: string, available_count?: int}>  $alternativeSlots
     */
    public function seatUnavailableAtTime(string $travelTime, array $alternativeSlots = []): string
    {
        $text = 'Izin Bapak/Ibu, untuk jam '.$travelTime.' WIB seat-nya saat ini sudah penuh.';

        if ($alternativeLine = $this->alternativeDepartureTimesLine($alternativeSlots)) {
            return $text.' '.$alternativeLine.' Mohon pilih jam lain ya.';
        }

        return $text.' Jika berkenan, kami bisa bantu arahkan ke admin untuk cek opsi jadwal lainnya ya.';
    }

    /**
     * @param  array<int, string>  $availableSeats
     * @param  array<int, array{id?: string, label?: string, time?: string, available_count?: int}>  $alternativeSlots
     */
    public function seatCapacityInsufficient(
        string $travelTime,
        int $requiredCount,
        array $availableSeats,
        array $alternativeSlots = [],
    ): string {
        $text = 'Izin Bapak/Ibu, untuk jam '.$travelTime.' WIB seat yang tersedia saat ini belum cukup untuk '.$requiredCount.' penumpang.';

        if ($availableSeats !== []) {
            $text .= ' Sisa seat yang masih tersedia '.$this->joinLabels($availableSeats).'.';
        }

        if ($alternativeLine = $this->alternativeDepartureTimesLine($alternativeSlots)) {
            return $text.' '.$alternativeLine.' Jika berkenan, mohon pilih jadwal lain ya.';
        }

        return $text.' Jika berkenan, kami bisa bantu arahkan ke admin untuk cek opsi jadwal lainnya ya.';
    }

    /**
     * @param  array<int, string>  $availableSeats
     * @param  array<int, string>  $selectedSeats
     */
    public function askSeatSelection(int $needed, array $availableSeats, array $selectedSeats = []): string
    {
        if ($selectedSeats !== [] && $needed > 0) {
            return 'Izin Bapak/Ibu, seat yang sudah dipilih '.$this->joinLabels($selectedSeats).'. Mohon pilih '.$needed.' seat lagi ya.';
        }

        if ($needed <= 1) {
            return 'Izin Bapak/Ibu, silakan pilih seat yang diinginkan ya.';
        }

        return 'Izin Bapak/Ibu, silakan pilih '.$needed.' seat yang diinginkan ya. Jika lebih mudah, boleh kirim beberapa seat sekaligus dipisah koma.';
    }

    public function seatSelectionInvalid(int $needed, ?string $reason = null): string
    {
        $base = 'Izin Bapak/Ibu, pilihan seat-nya belum bisa kami proses.';

        if ($reason !== null) {
            $base .= ' '.$reason;
        }

        return $base.' Mohon pilih '.($needed > 1 ? $needed.' seat' : 'seat').' yang masih tersedia ya.';
    }

    public function askPickupLocation(?string $seed = null): string
    {
        return $this->variation->pick([
            'Izin Bapak/Ibu, mohon pilih lokasi penjemputannya ya.',
            'Izin Bapak/Ibu, titik jemputnya di lokasi mana ya?',
            'Boleh dibantu pilih lokasi penjemputannya ya, Bapak/Ibu?',
        ], $seed);
    }

    public function askPickupAddress(?string $pickupLocation = null, ?string $seed = null): string
    {
        if ($pickupLocation !== null) {
            return $this->variation->pick([
                'Izin Bapak/Ibu, mohon dibantu alamat lengkap penjemputan di '.$pickupLocation.' ya.',
                'Baik Bapak/Ibu, boleh dibantu alamat jemput lengkap di '.$pickupLocation.' ya?',
                'Izin Bapak/Ibu, alamat lengkap penjemputannya di '.$pickupLocation.' mohon dibantu ya.',
            ], $seed);
        }

        return $this->variation->pick([
            'Izin Bapak/Ibu, mohon dibantu alamat lengkap penjemputannya ya.',
            'Baik Bapak/Ibu, boleh dibantu alamat jemput lengkapnya ya?',
            'Izin Bapak/Ibu, untuk alamat lengkap penjemputannya mohon dibantu ya.',
        ], $seed);
    }

    public function askDestination(?string $pickupLocation = null): string
    {
        return $pickupLocation !== null
            ? 'Izin Bapak/Ibu, untuk pengantarannya tujuan akhirnya ke mana ya dari '.$pickupLocation.'?'
            : 'Izin Bapak/Ibu, mohon pilih lokasi pengantarannya ya.';
    }

    public function askDestinationAddress(?string $destination = null, ?string $seed = null): string
    {
        if ($destination !== null) {
            return $this->variation->pick([
                'Baik Bapak/Ibu, boleh dibantu alamat lengkap tujuan antar di '.$destination.' ya?',
                'Izin Bapak/Ibu, mohon dibantu alamat lengkap titik pengantarannya di '.$destination.' ya.',
                'Baik Bapak/Ibu, untuk titik pengantaran di '.$destination.' mohon alamat lengkapnya ya.',
            ], $seed);
        }

        return $this->variation->pick([
            'Baik Bapak/Ibu, boleh dibantu alamat lengkap tujuan antarnya ya?',
            'Izin Bapak/Ibu, mohon dibantu alamat lengkap titik pengantarannya ya.',
            'Baik Bapak/Ibu, untuk lokasi pengantaran mohon alamat lengkapnya ya.',
        ], $seed);
    }

    public function askPassengerName(int $passengerCount, int $missingCount = 0, ?string $seed = null): string
    {
        if ($passengerCount <= 1) {
            return $this->variation->pick([
                'Izin Bapak/Ibu, boleh kami minta nama penumpangnya?',
                'Baik Bapak/Ibu, nama penumpangnya boleh dibantu ya?',
                'Izin Bapak/Ibu, siapa nama penumpangnya ya?',
            ], $seed);
        }

        if ($missingCount > 0 && $missingCount < $passengerCount) {
            return $this->variation->pick([
                'Izin Bapak/Ibu, masih kurang '.$missingCount.' nama penumpang lagi ya.',
                'Baik Bapak/Ibu, masih ada '.$missingCount.' nama penumpang yang belum masuk ya.',
                'Izin Bapak/Ibu, mohon dibantu '.$missingCount.' nama penumpang lagi ya.',
            ], $seed);
        }

        return $this->variation->pick([
            'Izin Bapak/Ibu, boleh dibantu nama-nama penumpangnya?',
            'Baik Bapak/Ibu, nama para penumpangnya boleh dibantu ya?',
            'Izin Bapak/Ibu, mohon dibantu nama masing-masing penumpangnya ya.',
        ], $seed);
    }

    public function askContactNumber(): string
    {
        return 'Izin Bapak/Ibu, apakah nomor kontak penumpangnya sama dengan nomor yang sedang menghubungi ini atau berbeda? Jika berbeda, mohon dibantu kirim nomor HP-nya. Jika sama, cukup ketik: sama.';
    }

    public function routeAvailableLine(?string $pickup, ?string $destination): ?string
    {
        if (blank($pickup) || blank($destination)) {
            return null;
        }

        return 'Izin Bapak/Ibu, rute '.$pickup.' ke '.$destination.' tersedia.';
    }

    /**
     * @param  array<int, string>  $suggestions
     */
    public function unsupportedRouteReply(?string $pickup, ?string $destination, array $suggestions, string $focusSlot): string
    {
        $text = 'Izin Bapak/Ibu, untuk rute '.trim(($pickup ?? '-').' ke '.($destination ?? '-')).' saat ini belum tersedia.';

        if ($suggestions !== []) {
            $label = $focusSlot === 'destination'
                ? 'Pilihan tujuan yang tersedia'
                : 'Pilihan lokasi jemput yang tersedia';

            $text .= ' '.$label.' antara lain '.$this->joinLabels($suggestions).'.';
        }

        return $text;
    }

    public function priceLine(?string $pickup, ?string $destination, ?int $passengerCount = null): ?string
    {
        if (blank($pickup) || blank($destination)) {
            return null;
        }

        $fare = $this->fareCalculator->unitFare($pickup, $destination);

        if ($fare === null) {
            return null;
        }

        $text = 'Izin Bapak/Ibu, ongkos rute '.$pickup.' ke '.$destination.' saat ini '.$this->fareCalculator->formatRupiah($fare).' per penumpang.';

        if (($passengerCount ?? 0) > 1) {
            $total = $this->fareCalculator->calculate($pickup, $destination, $passengerCount);
            if ($total !== null) {
                $text .= ' Estimasi total untuk '.$passengerCount.' penumpang '.$this->fareCalculator->formatRupiah($total).'.';
            }
        }

        return $text;
    }

    public function routeListReply(): string
    {
        return 'Izin Bapak/Ibu, lokasi jemput dan tujuan yang tersedia saat ini antara lain '.$this->joinLabels($this->routeValidator->menuLocations()).'.';
    }

    public function scheduleLine(): string
    {
        return 'Jadwal keberangkatan tersedia setiap hari pada pukul 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.';
    }

    public function scheduleTodayReply(string $dateLabel): string
    {
        return 'Izin Bapak/Ibu, untuk keberangkatan '.$dateLabel.' jadwal yang tersedia ada di jam 05.00, 08.00, 10.00, 14.00, 16.00, dan 19.00 WIB.';
    }

    public function priceNeedRouteReply(): string
    {
        return 'Izin Bapak/Ibu, untuk cek ongkos kami perlu lokasi jemput, tujuan, dan jumlah penumpangnya ya.';
    }

    public function askCorrection(): string
    {
        return 'Izin Bapak/Ibu, silakan kirim bagian data yang ingin diubah ya. Nanti kami sesuaikan tanpa mulai dari awal.';
    }

    public function confirmed(): string
    {
        return 'Baik Bapak/Ibu, data sudah kami terima. Kami akan kembali menghubungi melalui kanal WA ini atau dari Admin Utama ya.';
    }

    public function closing(?string $seed = null): string
    {
        return $this->variation->pick([
            'Baik Bapak/Ibu, terima kasih. Jika ingin cek jadwal atau booking lagi, silakan hubungi kami kembali ya.',
            'Baik Bapak/Ibu, terima kasih ya. Kalau nanti ingin lanjut lagi, tinggal chat kami kembali.',
            'Siap Bapak/Ibu, terima kasih. Jika ada yang ingin dicek lagi, kami siap bantu di WA ini ya.',
        ], $seed);
    }

    public function inProgressAcknowledgement(string $pendingPrompt): string
    {
        return 'Baik Bapak/Ibu. '.$pendingPrompt;
    }

    public function waitingAdminTakeover(): string
    {
        return 'Izin Bapak/Ibu, pertanyaannya sedang kami konsultasikan ke admin ya. Mohon tunggu sebentar.';
    }

    public function fallbackQuestionToAdmin(): string
    {
        return 'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Izin kami konsultasikan dahulu ya.';
    }

    public function shortAcknowledgement(?string $pendingPrompt = null, ?string $expectedInput = null, ?string $seed = null): string
    {
        $reminders = [
            'passenger_count' => [
                'Baik Bapak/Ibu, kami tunggu jumlah penumpangnya ya.',
                'Siap Bapak/Ibu, tinggal dibantu jumlah penumpangnya ya.',
            ],
            'travel_date' => [
                'Baik Bapak/Ibu, kami tunggu tanggal dan jam keberangkatannya ya.',
                'Siap Bapak/Ibu, tinggal dibantu tanggal dan jam berangkatnya ya.',
            ],
            'travel_time' => [
                'Baik Bapak/Ibu, kami tunggu pilihan jam keberangkatannya ya.',
                'Siap Bapak/Ibu, tinggal pilih jam berangkatnya ya.',
            ],
            'selected_seats' => [
                'Baik Bapak/Ibu, kami tunggu pilihan seat-nya ya.',
                'Siap Bapak/Ibu, tinggal dibantu seat yang dipilih ya.',
            ],
            'pickup_location' => [
                'Baik Bapak/Ibu, kami tunggu lokasi jemputnya ya.',
                'Siap Bapak/Ibu, tinggal dibantu titik jemputnya ya.',
            ],
            'pickup_full_address' => [
                'Baik Bapak/Ibu, kami tunggu alamat jemput lengkapnya ya.',
                'Siap Bapak/Ibu, tinggal dibantu alamat jemput lengkapnya ya.',
            ],
            'destination' => [
                'Baik Bapak/Ibu, kami tunggu tujuan pengantarannya ya.',
                'Siap Bapak/Ibu, tinggal dibantu tujuan pengantarannya ya.',
            ],
            'destination_full_address' => [
                'Baik Bapak/Ibu, kami tunggu alamat tujuan lengkapnya ya.',
                'Siap Bapak/Ibu, tinggal dibantu alamat tujuan lengkapnya ya.',
            ],
            'passenger_name' => [
                'Baik Bapak/Ibu, kami tunggu nama penumpangnya ya.',
                'Siap Bapak/Ibu, tinggal dibantu nama penumpangnya ya.',
            ],
            'contact_number' => [
                'Baik Bapak/Ibu, kami tunggu nomor kontak penumpangnya ya.',
                'Siap Bapak/Ibu, tinggal dibantu nomor kontak penumpangnya ya.',
            ],
            'final_confirmation' => [
                'Baik Bapak/Ibu, silakan pilih Benar atau Ubah Data pada ringkasan booking sebelumnya ya.',
                'Izin Bapak/Ibu, mohon pilih Benar atau Ubah Data agar bookingnya bisa kami lanjutkan ya.',
            ],
        ];

        if ($expectedInput !== null && isset($reminders[$expectedInput])) {
            return $this->variation->pick($reminders[$expectedInput], $seed);
        }

        if ($pendingPrompt !== null) {
            return $this->variation->pick([
                'Baik Bapak/Ibu, kami tunggu detail berikutnya ya.',
                'Siap Bapak/Ibu, tinggal dilanjutkan saja ya.',
            ], $seed);
        }

        return $this->variation->pick([
            'Baik Bapak/Ibu.',
            'Siap Bapak/Ibu.',
        ], $seed);
    }

    public function reviewSummary(BookingRequest $booking, ?string $seed = null): string
    {
        return $this->reviewFormatter->buildCustomerReview($booking, $seed);
    }

    public function finalConfirmationReminder(?string $seed = null): string
    {
        return $this->variation->pick([
            'Izin Bapak/Ibu, silakan pilih Benar atau Ubah Data pada ringkasan booking sebelumnya ya.',
            'Baik Bapak/Ibu, agar booking bisa kami lanjutkan mohon pilih Benar atau Ubah Data ya.',
            'Izin Bapak/Ibu, kami menunggu pilihan Benar atau Ubah Data untuk ringkasan booking tadi ya.',
        ], $seed);
    }

    public function fallbackForState(string $state, ?string $pendingPrompt = null): string
    {
        if (in_array($state, ['waiting_admin_takeover', 'closed'], true)) {
            return $this->waitingAdminTakeover();
        }

        if (in_array($state, ['completed', 'confirmed'], true)) {
            return $this->confirmed();
        }

        if ($state === 'awaiting_final_confirmation') {
            return 'Izin Bapak/Ibu, mohon pilih Benar atau Ubah Data pada ringkasan booking tadi ya.';
        }

        return $pendingPrompt !== null
            ? 'Izin Bapak/Ibu, agar tidak keliru mohon lanjutkan dengan data berikut ya. '.$pendingPrompt
            : 'Izin Bapak/Ibu, mohon dibantu detail perjalanannya ya.';
    }

    private function displayValue(string $slot, mixed $value): string
    {
        return match ($slot) {
            'passenger_count' => (int) $value.' orang',
            'travel_date' => $this->formatDateValue($value),
            'travel_time' => (string) $value.' WIB',
            'selected_seats', 'passenger_names' => is_array($value) ? implode(', ', $value) : (string) $value,
            default => (string) $value,
        };
    }

    /**
     * @param  array<int, string>  $labels
     */
    private function joinLabels(array $labels): string
    {
        $labels = array_values(array_filter(array_map(
            fn (mixed $label): string => is_string($label) ? trim($label) : '',
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

    /**
     * @param  array<int, array{id?: string, label?: string, time?: string, available_count?: int}>  $alternativeSlots
     */
    private function alternativeDepartureTimesLine(array $alternativeSlots): ?string
    {
        $labels = array_values(array_filter(array_map(
            fn (array $slot): string => trim((string) ($slot['label'] ?? '')),
            $alternativeSlots,
        )));

        if ($labels === []) {
            return null;
        }

        return 'Pilihan jam lain yang masih tersedia antara lain '.$this->joinLabels($labels).'.';
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
