<?php

namespace App\Services\Booking\Guardrails;

use App\Enums\IntentType;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Services\Booking\BookingReplyNaturalizerService;

class ActionEligibilityValidatorService
{
    public function __construct(
        private readonly BookingStateGuardService $bookingStateGuard,
        private readonly BookingReplyNaturalizerService $replyNaturalizer,
    ) {
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $slots
     * @param  array<string, mixed>  $updates
     * @return array{
     *     intent_result: array<string, mixed>,
     *     reply: array<string, mixed>|null,
     *     meta: array{validator: string, action: string, reasons: array<int, string>}
     * }
     */
    public function validate(
        Conversation $conversation,
        array $intentResult,
        array $slots,
        array $updates = [],
        ?BookingRequest $booking = null,
    ): array {
        $snapshot = $this->bookingStateGuard->snapshot($booking, $slots);
        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));
        $reasons = [];

        if ($intent === IntentType::BookingCancel) {
            $reasons[] = $snapshot['has_active_booking_context']
                ? 'booking_cancellation_requires_admin'
                : 'booking_cancellation_without_context';

            return [
                'intent_result' => $this->handoffIntent(
                    intentResult: $intentResult,
                    reasoning: 'Permintaan pembatalan booking diarahkan ke admin untuk validasi manual.',
                ),
                'reply' => $this->reply(
                    $snapshot['has_active_booking_context']
                        ? 'Izin Bapak/Ibu, untuk pembatalan booking akan kami bantu teruskan ke admin ya.'
                        : 'Izin Bapak/Ibu, jika ingin pembatalan booking silakan kirim detail bookingnya ya. Nanti kami bantu cek ke admin.',
                    'handoff_booking_cancel',
                ),
                'meta' => [
                    'validator' => 'action_eligibility',
                    'action' => 'handoff',
                    'reasons' => $reasons,
                ],
            ];
        }

        if (
            in_array($intent, [IntentType::BookingConfirm, IntentType::KonfirmasiBooking, IntentType::Confirmation], true)
            && ! $snapshot['is_review_pending']
            && ! $snapshot['is_completed']
        ) {
            $reasons[] = 'confirmation_without_review';

            return [
                'intent_result' => $this->clarifyIntent(
                    intentResult: $intentResult,
                    reasoning: 'Konfirmasi diterima tetapi belum ada review booking aktif.',
                    clarificationQuestion: 'Izin Bapak/Ibu, kami belum menemukan ringkasan booking yang siap dikonfirmasi. Jika ingin lanjut, silakan kirim detail perjalanannya ya.',
                ),
                'reply' => $this->reply(
                    'Izin Bapak/Ibu, kami belum menemukan ringkasan booking yang siap dikonfirmasi. Jika ingin lanjut, silakan kirim detail perjalanannya ya.',
                    'clarify_confirmation_without_review',
                ),
                'meta' => [
                    'validator' => 'action_eligibility',
                    'action' => 'clarify',
                    'reasons' => $reasons,
                ],
            ];
        }

        if (
            in_array($intent, [IntentType::UbahDataBooking, IntentType::Rejection], true)
            && ! $snapshot['has_active_booking_context']
            && $updates === []
        ) {
            $reasons[] = 'change_request_without_context';

            return [
                'intent_result' => $this->clarifyIntent(
                    intentResult: $intentResult,
                    reasoning: 'Permintaan ubah data diterima tanpa booking aktif.',
                    clarificationQuestion: 'Izin Bapak/Ibu, data booking yang ingin diubah yang mana ya? Jika belum ada data, silakan kirim rute atau detail perjalanannya dulu.',
                ),
                'reply' => $this->reply(
                    $this->replyNaturalizer->compose([
                        'Izin Bapak/Ibu, kami belum menemukan data booking aktif yang bisa diubah.',
                        'Jika belum ada data, silakan kirim rute atau detail perjalanannya dulu ya.',
                    ]),
                    'clarify_change_without_booking',
                ),
                'meta' => [
                    'validator' => 'action_eligibility',
                    'action' => 'clarify',
                    'reasons' => $reasons,
                ],
            ];
        }

        return [
            'intent_result' => $intentResult,
            'reply' => null,
            'meta' => [
                'validator' => 'action_eligibility',
                'action' => 'allow',
                'reasons' => [],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function clarifyIntent(array $intentResult, string $reasoning, string $clarificationQuestion): array
    {
        $intentResult['intent'] = IntentType::Booking->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0.0), 0.80);
        $intentResult['needs_clarification'] = true;
        $intentResult['clarification_question'] = $clarificationQuestion;
        $intentResult['reasoning_short'] = $reasoning;

        return $intentResult;
    }

    /**
     * @param  array<string, mixed>  $intentResult
     * @return array<string, mixed>
     */
    private function handoffIntent(array $intentResult, string $reasoning): array
    {
        $intentResult['intent'] = IntentType::HumanHandoff->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0.0), 0.95);
        $intentResult['handoff_recommended'] = true;
        $intentResult['needs_clarification'] = false;
        $intentResult['clarification_question'] = null;
        $intentResult['reasoning_short'] = $reasoning;

        return $intentResult;
    }

    /**
     * @return array{text: string, is_fallback: bool, message_type: string, outbound_payload: array<string, mixed>, meta: array<string, mixed>}
     */
    private function reply(string $text, string $action): array
    {
        return [
            'text' => $text,
            'is_fallback' => false,
            'message_type' => 'text',
            'outbound_payload' => [],
            'meta' => [
                'source' => 'guard.action_eligibility',
                'action' => $action,
            ],
        ];
    }
}
