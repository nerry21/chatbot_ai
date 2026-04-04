<?php

namespace App\Services\AI;

use App\Data\AI\GroundedResponseFacts;
use App\Data\AI\GroundedResponseResult;
use App\Enums\GroundedResponseMode;
use App\Services\Support\JsonSchemaValidatorService;
use Illuminate\Support\Facades\Log;

class GroundedResponseComposerService
{
    public function __construct(
        private readonly LlmClientService $llmClient,
        private readonly GroundedResponsePromptBuilderService $promptBuilder,
        private readonly JsonSchemaValidatorService $validator,
    ) {
    }

    public function compose(GroundedResponseFacts $facts): GroundedResponseResult
    {
        try {
            $prompts = $this->promptBuilder->build($facts);

            $raw = $this->llmClient->composeGroundedResponse([
                'conversation_id' => $facts->conversationId,
                'message_id' => $facts->messageId,
                'message_text' => $facts->latestMessageText,
                'grounded_response_facts' => $facts->toArray(),
                'system' => $prompts['system'],
                'user' => $prompts['user'],
                'model' => config('chatbot.llm.models.grounded_response', config('chatbot.llm.models.reply')),
            ]);

            $validated = $this->validator->validateAndFill(
                is_array($raw) ? $raw : [],
                ['text'],
                ['mode' => $facts->mode->value],
            );

            if ($validated === null) {
                return $this->fallback($facts);
            }

            $text = trim((string) ($validated['text'] ?? ''));
            $mode = GroundedResponseMode::tryFrom((string) ($validated['mode'] ?? ''))
                ?? $facts->mode;

            if ($text === '') {
                return $this->fallback($facts);
            }

            return new GroundedResponseResult(
                text: $text,
                mode: $mode,
                isFallback: false,
            );
        } catch (\Throwable $e) {
            Log::error('GroundedResponseComposerService: unexpected error', [
                'error' => $e->getMessage(),
                'conversation_id' => $facts->conversationId,
                'message_id' => $facts->messageId,
            ]);

            return $this->fallback($facts);
        }
    }

    /**
     * @param  array<string, mixed>  $replyDraft
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $intentResult
     * @param  array<string, mixed>  $orchestrationSnapshot
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>|null  $faqResult
     * @return array<string, mixed>
     */
    public function composeGroundedReply(
        array $replyDraft,
        array $context,
        array $intentResult = [],
        array $orchestrationSnapshot = [],
        array $knowledgeHits = [],
        ?array $faqResult = null,
    ): array {
        $reply = trim((string) ($replyDraft['reply'] ?? $replyDraft['text'] ?? ''));
        $usedFacts = is_array($replyDraft['used_crm_facts'] ?? null) ? $replyDraft['used_crm_facts'] : [];

        $crm = is_array($context['crm_context'] ?? null) ? $context['crm_context'] : [];
        $customer = is_array($crm['customer'] ?? null) ? $crm['customer'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $lead = is_array($crm['lead_pipeline'] ?? null) ? $crm['lead_pipeline'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];

        $groundingNotes = [];

        if (! empty($customer['name'])) {
            $usedFacts[] = 'customer.name';
        }

        if (! empty($lead['stage'])) {
            $usedFacts[] = 'lead_pipeline.stage';
        }

        if (! empty($conversation['current_intent'])) {
            $usedFacts[] = 'conversation.current_intent';
        }

        if (! empty($booking['booking_status'])) {
            $usedFacts[] = 'booking.booking_status';
        }

        if (! empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            $usedFacts[] = 'booking.missing_fields';
            $groundingNotes[] = 'Reply constrained by booking missing fields';
        }

        if (($flags['admin_takeover_active'] ?? false) === true) {
            $usedFacts[] = 'business_flags.admin_takeover_active';
            $groundingNotes[] = 'Reply constrained by admin takeover';
        }

        if (! empty($context['conversation_summary'])) {
            $groundingNotes[] = 'Conversation summary grounding used';
        }

        if (! empty($context['customer_memory'])) {
            $groundingNotes[] = 'Customer memory grounding used';
        }

        if ($faqResult !== null && ! empty($faqResult['matched'])) {
            $groundingNotes[] = 'FAQ grounding used';
        }

        if ($knowledgeHits !== []) {
            $groundingNotes[] = 'Knowledge grounding used';
        }

        if (($orchestrationSnapshot['reply_force_handoff'] ?? false) === true) {
            $groundingNotes[] = 'Reply constrained by orchestration handoff flag';
        }

        if (($intentResult['intent'] ?? null) !== null) {
            $groundingNotes[] = 'Intent-aware grounding applied';
        }

        if ($reply === '') {
            $reply = 'Baik, saya bantu dulu ya. Mohon jelaskan sedikit lebih detail agar saya bisa menindaklanjuti dengan tepat.';
            $groundingNotes[] = 'Empty draft replaced with grounded fallback';
        }

        $replyDraft['reply'] = $reply;
        $replyDraft['text'] = $reply;
        $replyDraft['used_crm_facts'] = array_values(array_unique(array_filter($usedFacts)));
        $replyDraft['grounding_notes'] = array_values(array_unique(array_filter(array_merge(
            is_array($replyDraft['grounding_notes'] ?? null) ? $replyDraft['grounding_notes'] : [],
            $groundingNotes,
        ))));
        $replyDraft['message_type'] = $replyDraft['message_type'] ?? 'text';
        $replyDraft['outbound_payload'] = is_array($replyDraft['outbound_payload'] ?? null)
            ? $replyDraft['outbound_payload']
            : [];

        $replyDraft['meta'] = array_merge(
            is_array($replyDraft['meta'] ?? null) ? $replyDraft['meta'] : [],
            [
                'grounded' => true,
                'grounding_source' => $this->detectGroundingSource($faqResult, $knowledgeHits, $crm),
            ],
        );

        return $replyDraft;
    }

    private function fallback(GroundedResponseFacts $facts): GroundedResponseResult
    {
        $officialFacts = $facts->officialFacts;

        $text = match ($facts->mode) {
            GroundedResponseMode::ClarificationQuestion => $this->clarificationFallback($facts),
            GroundedResponseMode::BookingContinuation => $this->bookingContinuationFallback($facts),
            GroundedResponseMode::PoliteRefusal => $this->politeRefusalFallback($facts),
            GroundedResponseMode::HandoffMessage => 'Izin Bapak/Ibu, pertanyaan ini kami bantu teruskan ke admin ya. Mohon tunggu sebentar.',
            GroundedResponseMode::DirectAnswer => $this->directAnswerFallback($facts),
        };

        return new GroundedResponseResult(
            text: $text,
            mode: $facts->mode,
            isFallback: true,
        );
    }

    /**
     * @param  array<int, mixed>  $knowledgeHits
     * @param  array<string, mixed>  $crm
     */
    private function detectGroundingSource(?array $faqResult, array $knowledgeHits, array $crm): string
    {
        if ($faqResult !== null && ! empty($faqResult['matched']) && $knowledgeHits !== []) {
            return 'faq+knowledge+crm';
        }

        if ($faqResult !== null && ! empty($faqResult['matched'])) {
            return 'faq+crm';
        }

        if ($knowledgeHits !== []) {
            return 'knowledge+crm';
        }

        if ($crm !== []) {
            return 'crm';
        }

        return 'fallback';
    }

    private function directAnswerFallback(GroundedResponseFacts $facts): string
    {
        $verifiedAnswer = is_array($facts->officialFacts['verified_answer'] ?? null)
            ? $facts->officialFacts['verified_answer']
            : null;
        if ($verifiedAnswer !== null && ! empty($verifiedAnswer['text'])) {
            return (string) $verifiedAnswer['text'];
        }

        $requestedSchedule = is_array($facts->officialFacts['requested_schedule'] ?? null)
            ? $facts->officialFacts['requested_schedule']
            : [];
        $destination = $facts->entityResult['destination'] ?? $facts->officialFacts['route']['destination'] ?? null;
        $hasIslamicGreeting = preg_match('/ass?alamu[\'’ ]?alaikum/iu', $facts->latestMessageText) === 1;

        if (($requestedSchedule['available'] ?? null) === true) {
            $dateLabel = $this->dateLabel($requestedSchedule['travel_date'] ?? null);
            $timeLabel = $this->timeLabel($requestedSchedule['travel_time'] ?? null);
            $destinationLabel = is_string($destination) && trim($destination) !== '' ? ' ke '.$destination : '';

            return trim(($hasIslamicGreeting ? 'Waalaikumsalam Bapak/Ibu, ' : 'Baik Bapak/Ibu, ').'untuk keberangkatan'.$dateLabel.$destinationLabel.', jadwal pukul '.$timeLabel.' tersedia. Jika ingin, saya bisa bantu lanjut bookingnya.');
        }

        return 'Baik Bapak/Ibu, saya bantu jawab berdasarkan data yang tersedia ya.';
    }

    private function clarificationFallback(GroundedResponseFacts $facts): string
    {
        $question = $facts->intentResult['clarification_question'] ?? $facts->officialFacts['suggested_follow_up'] ?? null;

        return is_string($question) && trim($question) !== ''
            ? trim($question)
            : 'Izin Bapak/Ibu, boleh dijelaskan lagi detail perjalanan yang ingin dicek ya?';
    }

    private function bookingContinuationFallback(GroundedResponseFacts $facts): string
    {
        $expectedInput = $facts->officialFacts['booking_context']['expected_input'] ?? null;

        return match ($expectedInput) {
            'passenger_count' => 'Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya ya?',
            'travel_date' => 'Izin Bapak/Ibu, tanggal keberangkatannya kapan ya?',
            'travel_time' => 'Izin Bapak/Ibu, jam keberangkatannya yang diinginkan jam berapa ya?',
            'selected_seats' => 'Izin Bapak/Ibu, mohon pilih seat yang diinginkan ya.',
            'pickup_location' => 'Izin Bapak/Ibu, lokasi jemputnya di mana ya?',
            'pickup_full_address' => 'Izin Bapak/Ibu, mohon dibantu alamat jemput lengkapnya ya.',
            'destination' => 'Izin Bapak/Ibu, tujuan pengantarannya ke mana ya?',
            'passenger_name' => 'Izin Bapak/Ibu, mohon dibantu nama penumpangnya ya.',
            'contact_number' => 'Izin Bapak/Ibu, mohon dibantu nomor kontak penumpangnya ya.',
            default => 'Baik Bapak/Ibu, jika berkenan saya bisa bantu lanjutkan bookingnya ya.',
        };
    }

    private function politeRefusalFallback(GroundedResponseFacts $facts): string
    {
        $route = is_array($facts->officialFacts['route'] ?? null) ? $facts->officialFacts['route'] : [];
        if (($route['supported'] ?? null) === false) {
            return 'Izin Bapak/Ibu, untuk rute tersebut saat ini belum tersedia. Jika berkenan, silakan kirim rute lain yang ingin dicek ya.';
        }

        $requestedSchedule = is_array($facts->officialFacts['requested_schedule'] ?? null)
            ? $facts->officialFacts['requested_schedule']
            : [];
        if (($requestedSchedule['available'] ?? null) === false) {
            return 'Izin Bapak/Ibu, jam yang dimaksud saat ini belum tersedia. Jika berkenan, silakan pilih jam keberangkatan lain ya.';
        }

        return 'Izin Bapak/Ibu, untuk permintaan tersebut saat ini belum bisa kami penuhi. Jika berkenan, saya bantu cek opsi lain yang tersedia ya.';
    }

    private function dateLabel(mixed $date): string
    {
        if (! is_string($date) || trim($date) === '') {
            return '';
        }

        return ' '.trim($date);
    }

    private function timeLabel(mixed $time): string
    {
        if (! is_string($time) || trim($time) === '') {
            return '-';
        }

        return str_replace(':', '.', trim($time));
    }
}
