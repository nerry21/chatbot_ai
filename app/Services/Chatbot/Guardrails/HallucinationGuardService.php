<?php

namespace App\Services\Chatbot\Guardrails;

use App\Enums\IntentType;
use App\Models\Conversation;

class HallucinationGuardService
{
    /**
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $context
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{
     *         guard_group: string,
     *         action: string,
     *         blocked: bool,
     *         reason: string|null
     *     }
     * }
     */
    public function guardReply(
        Conversation $conversation,
        array $intentResult,
        array $reply,
        array $context = [],
    ): array {
        $source = (string) ($reply['meta']['source'] ?? '');
        if ($source !== 'ai_reply') {
            return $this->allow($reply, $intentResult);
        }

        if (($conversation->isAdminTakeover() || (($context['admin_takeover'] ?? false) === true))) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Admin takeover aktif; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, percakapan ini sedang ditangani admin ya.',
            );
        }

        if (($intentResult['handoff_recommended'] ?? false) === true) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding meminta handoff; AI reply diblokir.',
                text: 'Izin Bapak/Ibu, pertanyaan ini kami bantu teruskan ke admin ya.',
            );
        }

        if (($intentResult['needs_clarification'] ?? false) === true) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Understanding masih ambigu; AI reply bebas diblokir.',
                text: (string) ($intentResult['clarification_question'] ?? 'Izin Bapak/Ibu, boleh dijelaskan lagi kebutuhan perjalanannya ya?'),
            );
        }

        $intent = IntentType::tryFrom((string) ($intentResult['intent'] ?? ''));
        $text = (string) ($reply['text'] ?? '');
        $hasGroundedKnowledge = ($context['faq_result']['matched'] ?? false) === true
            || ! empty($context['knowledge_hits']);

        if ($intent !== null && $this->isSensitiveOperationalIntent($intent) && ! $hasGroundedKnowledge) {
            return $this->clarify(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI mencoba menjawab intent operasional tanpa grounding yang aman.',
                text: $this->clarificationTextForIntent($intent),
            );
        }

        if ($this->containsSensitiveBusinessClaim($text) && ! $hasGroundedKnowledge) {
            return $this->handoff(
                reply: $reply,
                intentResult: $intentResult,
                reason: 'Reply AI mengandung klaim bisnis sensitif tanpa grounding yang aman.',
                text: 'Izin Bapak/Ibu, untuk detail promo, kebijakan, atau ketersediaan spesifik ini kami bantu cek dulu ke admin ya.',
            );
        }

        return $this->allow($reply, $intentResult);
    }

    private function isSensitiveOperationalIntent(IntentType $intent): bool
    {
        return in_array($intent, [
            IntentType::Booking,
            IntentType::BookingConfirm,
            IntentType::BookingCancel,
            IntentType::ScheduleInquiry,
            IntentType::PriceInquiry,
            IntentType::LocationInquiry,
            IntentType::TanyaKeberangkatanHariIni,
            IntentType::TanyaHarga,
            IntentType::TanyaRute,
            IntentType::TanyaJam,
            IntentType::KonfirmasiBooking,
            IntentType::UbahDataBooking,
        ], true);
    }

    private function containsSensitiveBusinessClaim(string $text): bool
    {
        $patterns = [
            '/\b(rp|rupiah|harga|ongkos|tarif)\b/iu',
            '/\b(jadwal|jam keberangkatan|slot|berangkat jam)\b/iu',
            '/\b(seat tersedia|kursi tersedia|masih kosong|seat kosong|seat [a-z0-9])/iu',
            '/\b(promo|diskon|cashback|potongan harga)\b/iu',
            '/\b(kebijakan|refund|reschedule|pembatalan|pelunasan|dp)\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function clarificationTextForIntent(IntentType $intent): string
    {
        return match ($intent) {
            IntentType::TanyaHarga,
            IntentType::PriceInquiry
                => 'Izin Bapak/Ibu, untuk cek ongkos kami perlu titik jemput dan tujuan perjalanannya ya.',
            IntentType::TanyaJam,
            IntentType::ScheduleInquiry,
            IntentType::TanyaKeberangkatanHariIni
                => 'Izin Bapak/Ibu, untuk cek jadwal yang tepat mohon kirim rute atau tujuan perjalanannya ya.',
            IntentType::TanyaRute,
            IntentType::LocationInquiry
                => 'Izin Bapak/Ibu, titik jemput atau tujuan mana yang ingin dicek rutenya ya?',
            default
                => 'Izin Bapak/Ibu, mohon kirim detail perjalanan yang ingin dibantu supaya jawabannya tetap akurat ya.',
        };
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{guard_group: string, action: string, blocked: bool, reason: string|null}
     * }
     */
    private function clarify(array $reply, array $intentResult, string $reason, string $text): array
    {
        $intentResult['needs_clarification'] = true;
        $intentResult['clarification_question'] = $text;
        $intentResult['reasoning_short'] = $reason;

        return [
            'reply' => [
                'text' => $text,
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'guard.hallucination',
                    'action' => 'clarify_sensitive_request',
                    'original_source' => $reply['meta']['source'] ?? null,
                    'original_action' => $reply['meta']['action'] ?? null,
                ],
            ],
            'intent_result' => $intentResult,
            'meta' => [
                'guard_group' => 'hallucination',
                'action' => 'clarify',
                'blocked' => true,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{guard_group: string, action: string, blocked: bool, reason: string|null}
     * }
     */
    private function handoff(array $reply, array $intentResult, string $reason, string $text): array
    {
        $intentResult['intent'] = IntentType::HumanHandoff->value;
        $intentResult['confidence'] = max((float) ($intentResult['confidence'] ?? 0.0), 0.95);
        $intentResult['handoff_recommended'] = true;
        $intentResult['needs_clarification'] = false;
        $intentResult['clarification_question'] = null;
        $intentResult['reasoning_short'] = $reason;

        return [
            'reply' => [
                'text' => $text,
                'is_fallback' => false,
                'message_type' => 'text',
                'outbound_payload' => [],
                'meta' => [
                    'source' => 'guard.hallucination',
                    'action' => 'handoff_sensitive_request',
                    'original_source' => $reply['meta']['source'] ?? null,
                    'original_action' => $reply['meta']['action'] ?? null,
                ],
            ],
            'intent_result' => $intentResult,
            'meta' => [
                'guard_group' => 'hallucination',
                'action' => 'handoff',
                'blocked' => true,
                'reason' => $reason,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reply
     * @param  array<string, mixed>  $intentResult
     * @return array{
     *     reply: array<string, mixed>,
     *     intent_result: array<string, mixed>,
     *     meta: array{guard_group: string, action: string, blocked: bool, reason: string|null}
     * }
     */
    private function allow(array $reply, array $intentResult): array
    {
        return [
            'reply' => $reply,
            'intent_result' => $intentResult,
            'meta' => [
                'guard_group' => 'hallucination',
                'action' => 'allow',
                'blocked' => false,
                'reason' => null,
            ],
        ];
    }
}
