<?php

namespace App\Services\Chatbot;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\AI\IntentClassifierService;
use App\Services\AI\ResponseGeneratorService;
use App\Services\AI\ResponseValidationService;
use App\Services\AI\RuleEngineService;
use App\Services\Booking\BookingConfirmationService;
use App\Services\Booking\RouteValidationService;

class ReplyOrchestratorService
{
    /**
     * Human-readable label for each required booking field.
     * Used when generating the "missing fields" prompt.
     *
     * @var array<string, string>
     */
    private const FIELD_LABELS = [
        'pickup_location' => 'titik penjemputan',
        'destination'     => 'tujuan perjalanan',
        'passenger_name'  => 'nama penumpang',
        'passenger_count' => 'jumlah penumpang',
        'departure_date'  => 'tanggal keberangkatan',
        'departure_time'  => 'jam keberangkatan',
        'payment_method'  => 'metode pembayaran',
    ];

    public function __construct(
        private readonly IntentClassifierService $intentClassificationService,
        private readonly ResponseGeneratorService $replyGenerationService,
        private readonly RuleEngineService $ruleEngineService,
        private readonly ResponseValidationService $responseValidationService,
        private readonly BookingConfirmationService $confirmationService,
        private readonly RouteValidationService $routeValidator,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function orchestrate(array $context): array
    {
        $intentResult = is_array($context['intent_result'] ?? null)
            ? $context['intent_result']
            : $this->intentClassificationService->classify($context);

        $replyDraft = is_array($context['reply_result'] ?? null)
            ? $context['reply_result']
            : $this->replyGenerationService->generate(
                context: $context,
                intentResult: $intentResult,
            );

        $ruleEvaluation = $this->ruleEngineService->evaluateOperationalRules(
            context: $context,
            intentResult: $intentResult,
            replyResult: $replyDraft,
        );

        $hasForcingAction =
            (($ruleEvaluation['actions']['force_handoff'] ?? false) === true)
            || (($ruleEvaluation['actions']['force_safe_fallback'] ?? false) === true)
            || (($ruleEvaluation['actions']['force_ask_missing_data'] ?? false) === true);

        if ($hasForcingAction) {
            $replyDraft = $this->ruleEngineService->buildSafeFallbackFromRules(
                context: $context,
                ruleEvaluation: $ruleEvaluation,
            );
        }

        $finalReply = $this->responseValidationService->validateAndFinalize(
            replyResult: $replyDraft,
            context: $context,
            intentResult: $intentResult,
            ruleEvaluation: $ruleEvaluation,
        );

        return [
            'intent_result' => $intentResult,
            'rule_evaluation' => $ruleEvaluation,
            'reply_result' => $finalReply,
        ];
    }

    /**
     * @param  array<string, mixed>  $orchestrated
     * @return array<string, mixed>
     */
    public function buildAuditSnapshot(array $orchestrated): array
    {
        $intent = is_array($orchestrated['intent_result'] ?? null) ? $orchestrated['intent_result'] : [];
        $rules = is_array($orchestrated['rule_evaluation'] ?? null) ? $orchestrated['rule_evaluation'] : [];
        $reply = is_array($orchestrated['reply_result'] ?? null) ? $orchestrated['reply_result'] : [];

        return [
            'intent' => $intent['intent'] ?? null,
            'intent_confidence' => $intent['confidence'] ?? null,
            'should_escalate' => $reply['should_escalate'] ?? false,
            'handoff_reason' => $reply['handoff_reason'] ?? null,
            'next_action' => $reply['next_action'] ?? null,
            'rule_hits' => $rules['rule_hits'] ?? [],
            'reply_source' => $reply['meta']['decision_source'] ?? $reply['meta']['source'] ?? null,
        ];
    }

    /**
     * Snapshot final untuk audit, observability, dan CRM writeback.
     *
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $entityResult
     * @param  array<string, mixed>  $replyResult
     * @param  array<string, mixed>|null  $bookingDecision
     * @return array<string, mixed>
     */
    public function buildFinalSnapshot(
        array $intentResult,
        array $entityResult,
        array $replyResult,
        ?array $bookingDecision = null,
    ): array {
        $replyMeta = is_array($replyResult['meta'] ?? null) ? $replyResult['meta'] : [];

        return [
            'intent' => $intentResult['intent'] ?? null,
            'intent_confidence' => $intentResult['confidence'] ?? null,
            'intent_reasoning' => $intentResult['reasoning_short'] ?? null,
            'entity_keys' => array_values(array_map('strval', array_keys($entityResult))),
            'reply_source' => $replyMeta['decision_source'] ?? $replyMeta['source'] ?? null,
            'reply_action' => $replyMeta['action'] ?? ($replyResult['next_action'] ?? null),
            'reply_force_handoff' => (bool) ($replyMeta['force_handoff'] ?? ($replyResult['should_escalate'] ?? false)),
            'reply_next_action' => $replyResult['next_action'] ?? null,
            'handoff_reason' => $replyResult['handoff_reason'] ?? null,
            'booking_action' => $bookingDecision['action'] ?? null,
            'booking_status' => $bookingDecision['booking_status'] ?? null,
            'is_fallback' => (bool) ($replyResult['is_fallback'] ?? false),
        ];
    }

    /**
     * Compose the final outbound reply text by combining:
     *  - booking engine decision (takes priority when present)
     *  - AI-generated reply from Tahap 3 (fallback when no booking decision)
     *
     * Required context keys:
     *   conversation  (Conversation)
     *   customer      (Customer)
     *   intentResult  (array)
     *   entityResult  (array)
     *   replyResult   (array{text: string, is_fallback: bool})
     *
     * Optional context keys:
     *   bookingDecision  (array|null)   — output of BookingAssistantService::decideNextStep()
     *   booking          (BookingRequest|null)
     *
     * @param  array<string, mixed>  $context
     * @return array{text: string, is_fallback: bool, meta: array<string, mixed>}
     */
    public function compose(array $context): array
    {
        /** @var Conversation $conversation */
        $conversation = $context['conversation'];
        /** @var Customer $customer */
        $customer        = $context['customer'];
        $intentResult    = $context['intentResult'] ?? [];
        $entityResult    = $context['entityResult'] ?? [];
        $replyResult     = $context['replyResult']  ?? ['text' => '', 'is_fallback' => true];
        $bookingDecision = $context['bookingDecision'] ?? null;
        /** @var BookingRequest|null $booking */
        $booking = $context['booking'] ?? null;

        $customerName = $customer->name ?? null;

        // ── No booking engine involvement → pass through AI reply ─────────
        if ($bookingDecision === null) {
            return [
                'text'        => $replyResult['text'],
                'is_fallback' => $replyResult['is_fallback'],
                'meta'        => ['source' => 'ai_reply'],
            ];
        }

        $action = $bookingDecision['action'] ?? 'general_reply';

        $text = match($action) {
            'ask_missing_fields' => $this->composeMissingFields(
                missingFields : $bookingDecision['missing_fields'] ?? [],
                customerName  : $customerName,
            ),

            'unsupported_route'  => $this->composeUnsupportedRoute(
                booking      : $booking,
                customerName : $customerName,
            ),

            'ask_confirmation'   => $this->composeAskConfirmation(
                booking      : $booking,
                customerName : $customerName,
            ),

            'confirmed'          => $this->composeConfirmed($customerName),

            'booking_cancelled'  => $this->composeCancelled($customerName),

            'unavailable'        => $this->composeUnavailable(
                reason       : $bookingDecision['reason'] ?? null,
                customerName : $customerName,
            ),

            'general_reply'      => $replyResult['text'],

            default              => $replyResult['text'],
        };

        return [
            'text'        => $text !== '' ? $text : $replyResult['text'],
            'is_fallback' => false,
            'meta'        => [
                'source'         => 'booking_engine',
                'action'         => $action,
                'booking_status' => $bookingDecision['booking_status'] ?? null,
            ],
        ];
    }

    // -------------------------------------------------------------------------
    // Reply composers
    // -------------------------------------------------------------------------

    /** @param array<int, string> $missingFields */
    private function composeMissingFields(array $missingFields, ?string $customerName): string
    {
        $greeting = $customerName ? "Halo, {$customerName}! " : 'Halo! ';

        $fieldLabels = array_map(
            fn (string $field) => '- ' . (self::FIELD_LABELS[$field] ?? $field),
            $missingFields,
        );

        $listStr = implode("\n", $fieldLabels);

        return <<<TEXT
        {$greeting}Untuk melengkapi pesanan Anda, kami masih membutuhkan informasi berikut:

        {$listStr}

        Mohon informasikan data di atas agar kami bisa memproses pesanan Anda.
        TEXT;
    }

    private function composeUnsupportedRoute(?BookingRequest $booking, ?string $customerName): string
    {
        $greeting = $customerName ? "Mohon maaf, {$customerName}." : 'Mohon maaf.';

        $pickup = $booking?->pickup_location ?? 'yang Anda pilih';
        $dest   = $booking?->destination     ?? 'tujuan tersebut';

        $supported = $this->routeValidator->supportedPickups();
        $routeHint = ! empty($supported)
            ? "\n\nKota keberangkatan yang saat ini kami layani: " . implode(', ', $supported) . '.'
            : '';

        return <<<TEXT
        {$greeting} Rute dari *{$pickup}* menuju *{$dest}* belum tersedia dalam layanan kami saat ini.{$routeHint}

        Ada rute lain yang bisa kami bantu?
        TEXT;
    }

    private function composeAskConfirmation(?BookingRequest $booking, ?string $customerName): string
    {
        if ($booking === null) {
            return 'Mohon konfirmasikan pesanan Anda dengan membalas YA atau BENAR.';
        }

        return $this->confirmationService->buildSummary($booking);
    }

    private function composeConfirmed(?string $customerName): string
    {
        $name = $customerName ? ", {$customerName}" : '';

        return <<<TEXT
        Terima kasih{$name}! Permintaan pemesanan Anda telah berhasil kami catat.

        Tim kami akan segera menghubungi Anda untuk konfirmasi jadwal dan detail pembayaran. Mohon pastikan nomor WhatsApp Anda aktif.
        TEXT;
    }

    private function composeCancelled(?string $customerName): string
    {
        $name = $customerName ? ", {$customerName}" : '';

        return "Baik{$name}, pesanan Anda telah kami batalkan. Jika suatu saat Anda ingin memesan kembali, kami siap membantu.";
    }

    private function composeUnavailable(?string $reason, ?string $customerName): string
    {
        $greeting = $customerName ? "Mohon maaf, {$customerName}." : 'Mohon maaf.';
        $detail   = $reason ? " {$reason}" : ' Slot keberangkatan yang Anda pilih sedang tidak tersedia.';

        return "{$greeting}{$detail} Apakah Anda ingin mencoba tanggal atau waktu lain?";
    }
}
