<?php

namespace App\Services\AI\Learning;

use App\Models\ChatbotAdminCorrection;
use App\Models\ChatbotCaseMemory;
use App\Models\ChatbotLearningSignal;
use Illuminate\Support\Collection;

class CaseMemoryService
{
    public function rememberFromLearningSignal(ChatbotLearningSignal $signal): ?ChatbotCaseMemory
    {
        if (! config('chatbot.continuous_improvement.store_case_memory', true)) {
            return null;
        }

        $intent = (string) ($signal->understanding_result['intent'] ?? '');
        $response = trim((string) ($signal->final_response ?? ''));

        if ($intent === '' || $response === '') {
            return null;
        }

        return ChatbotCaseMemory::updateOrCreate(
            ['learning_signal_id' => $signal->id],
            [
                'conversation_id' => $signal->conversation_id,
                'admin_correction_id' => null,
                'source_type' => 'learning_signal',
                'intent' => $intent,
                'sub_intent' => $signal->understanding_result['sub_intent'] ?? null,
                'user_message' => $signal->user_message,
                'context_summary' => $signal->context_summary,
                'successful_response' => $signal->final_response,
                'example_payload' => [
                    'context_snapshot' => $signal->context_snapshot,
                    'understanding_result' => $signal->understanding_result,
                    'grounded_facts' => $signal->grounded_facts,
                    'final_response_meta' => $signal->final_response_meta,
                    'chosen_action' => $signal->chosen_action,
                ],
                'tags' => $this->buildTags(
                    intent: $intent,
                    chosenAction: $signal->chosen_action,
                    failureType: null,
                ),
                'is_active' => true,
            ],
        );
    }

    public function rememberFromAdminCorrection(ChatbotAdminCorrection $correction): ChatbotCaseMemory
    {
        $signal = $correction->learningSignal;
        $intent = (string) ($signal?->understanding_result['intent'] ?? '');

        return ChatbotCaseMemory::updateOrCreate(
            ['admin_correction_id' => $correction->id],
            [
                'conversation_id' => $correction->conversation_id,
                'learning_signal_id' => $correction->learning_signal_id,
                'source_type' => 'admin_correction',
                'intent' => $intent !== '' ? $intent : null,
                'sub_intent' => $signal?->understanding_result['sub_intent'] ?? null,
                'user_message' => $correction->customer_message_text,
                'context_summary' => $signal?->context_summary,
                'successful_response' => $correction->admin_correction_text,
                'example_payload' => [
                    'bot_response_text' => $correction->bot_response_text,
                    'correction_payload' => $correction->correction_payload,
                    'understanding_result' => $signal?->understanding_result,
                    'chosen_action' => $signal?->chosen_action,
                ],
                'tags' => $this->buildTags(
                    intent: $intent,
                    chosenAction: $signal?->chosen_action,
                    failureType: $correction->failure_type?->value,
                ),
                'is_active' => true,
            ],
        );
    }

    /**
     * @return Collection<int, ChatbotCaseMemory>
     */
    public function findRelevantExamples(
        string $messageText,
        ?string $intent = null,
        int $limit = 0,
    ): Collection {
        $limit = $limit > 0
            ? $limit
            : (int) config('chatbot.continuous_improvement.case_memory_retrieval_limit', 3);
        $maxCandidates = max(
            $limit,
            (int) config('chatbot.continuous_improvement.case_memory_max_candidates', 30),
        );
        $normalizedInput = $this->normalize($messageText);

        $query = ChatbotCaseMemory::query()
            ->where('is_active', true)
            ->latest();

        if ($intent !== null && trim($intent) !== '') {
            $query->where('intent', trim($intent));
        }

        /** @var Collection<int, ChatbotCaseMemory> $candidates */
        $candidates = $query->limit($maxCandidates)->get();

        return $candidates
            ->map(function (ChatbotCaseMemory $memory) use ($normalizedInput): array {
                return [
                    'memory' => $memory,
                    'score' => $this->score($normalizedInput, $memory),
                ];
            })
            ->filter(static fn (array $item): bool => $item['score'] > 0)
            ->sortByDesc('score')
            ->take($limit)
            ->map(static fn (array $item): ChatbotCaseMemory => $item['memory'])
            ->values();
    }

    public function markUsed(ChatbotCaseMemory $memory): void
    {
        $memory->increment('usage_count');
        $memory->forceFill(['last_used_at' => now()])->save();
    }

    /**
     * @return array<int, string>
     */
    private function buildTags(string $intent, ?string $chosenAction, ?string $failureType): array
    {
        return array_values(array_filter([
            $intent !== '' ? 'intent:' . $intent : null,
            $chosenAction !== null && trim($chosenAction) !== '' ? 'action:' . trim($chosenAction) : null,
            $failureType !== null && trim($failureType) !== '' ? 'failure:' . trim($failureType) : null,
        ]));
    }

    private function score(string $normalizedInput, ChatbotCaseMemory $memory): int
    {
        $haystacks = [
            $this->normalize((string) ($memory->user_message ?? '')),
            $this->normalize((string) ($memory->context_summary ?? '')),
        ];

        $tokens = array_values(array_filter(explode(' ', $normalizedInput)));
        if ($tokens === []) {
            return 0;
        }

        $score = 0;

        foreach ($tokens as $token) {
            foreach ($haystacks as $haystack) {
                if ($haystack !== '' && str_contains($haystack, $token)) {
                    $score++;
                    break;
                }
            }
        }

        return $score;
    }

    private function normalize(string $text): string
    {
        $value = mb_strtolower(trim($text), 'UTF-8');
        $value = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return $value;
    }
}
