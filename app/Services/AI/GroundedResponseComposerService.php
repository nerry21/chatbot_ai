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
