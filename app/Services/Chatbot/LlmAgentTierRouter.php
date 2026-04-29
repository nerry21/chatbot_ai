<?php

namespace App\Services\Chatbot;

use App\Enums\MessageDirection;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Models\Escalation;
use App\Services\CRM\JetCrmContextService;
use App\Support\WaLog;
use Throwable;

class LlmAgentTierRouter
{
    private const TIER_1 = 'tier1';
    private const TIER_2 = 'tier2';
    private const THRESHOLD = 3;

    private const COMPLAINT_KEYWORDS = [
        'kecewa', 'marah', 'refund', 'salah', 'kapok',
        'lambat', 'gak puas', 'komplain',
    ];

    public function __construct(
        private readonly JetCrmContextService $crmContext,
    ) {}

    /**
     * Decide tier untuk chat ini.
     *
     * @return array{
     *     tier: string,
     *     model: string,
     *     score: int,
     *     reasons: array<int, string>,
     *     trigger_details: array<string, int>
     * }
     */
    public function decide(
        ConversationMessage $message,
        Conversation $conversation,
        Customer $customer,
    ): array {
        try {
            $triggers = [];

            if ($this->triggerVip($customer)) {
                $triggers['vip'] = 2;
            }
            if ($this->triggerNegativeSentiment($customer)) {
                $triggers['sentiment_negative'] = 3;
            }
            if ($this->triggerMultiTurnUnresolved($conversation, $message)) {
                $triggers['multi_turn'] = 1;
            }
            if ($this->triggerPendingEscalation($conversation)) {
                $triggers['escalation_pending'] = 3;
            }
            if ($this->triggerComplaintKeyword($message)) {
                $triggers['complaint_keyword'] = 2;
            }

            $score = array_sum($triggers);
            $tier = $score >= self::THRESHOLD ? self::TIER_2 : self::TIER_1;
            $model = $this->resolveModel($tier);

            WaLog::info('[LlmAgent] Tier decided', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'customer_id' => $customer->id,
                'tier' => $tier,
                'model' => $model,
                'score' => $score,
                'reasons' => array_keys($triggers),
            ]);

            return [
                'tier' => $tier,
                'model' => $model,
                'score' => $score,
                'reasons' => array_keys($triggers),
                'trigger_details' => $triggers,
            ];
        } catch (Throwable $e) {
            WaLog::warning('[TierRouter] decision failed, falling back to tier1', [
                'conversation_id' => $conversation->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);

            return [
                'tier' => self::TIER_1,
                'model' => $this->resolveModel(self::TIER_1),
                'score' => 0,
                'reasons' => ['fallback_error'],
                'trigger_details' => [],
            ];
        }
    }

    private function triggerVip(Customer $customer): bool
    {
        $customer->loadMissing('preferences');
        foreach ($customer->preferences as $pref) {
            if ($pref->key === 'vip_indicator' && (float) $pref->confidence >= 0.5) {
                return true;
            }
        }
        return false;
    }

    private function triggerNegativeSentiment(Customer $customer): bool
    {
        $context = $this->crmContext->resolveForCustomer($customer);
        $sentiment = strtolower((string) ($context['ai_memory']['ai_sentiment'] ?? ''));
        return in_array($sentiment, ['negative', 'urgent'], true);
    }

    private function triggerMultiTurnUnresolved(Conversation $conversation, ConversationMessage $currentMessage): bool
    {
        $inboundCount = ConversationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('direction', MessageDirection::Inbound->value)
            ->count();
        return $inboundCount >= 6;
    }

    private function triggerPendingEscalation(Conversation $conversation): bool
    {
        return Escalation::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('status', ['open', 'assigned'])
            ->where('created_at', '>=', now()->subHours(24))
            ->exists();
    }

    private function triggerComplaintKeyword(ConversationMessage $message): bool
    {
        $text = strtolower((string) ($message->message_text ?? ''));
        if ($text === '') {
            return false;
        }
        foreach (self::COMPLAINT_KEYWORDS as $kw) {
            if (str_contains($text, $kw)) {
                return true;
            }
        }
        return false;
    }

    private function resolveModel(string $tier): string
    {
        if ($tier === self::TIER_2) {
            return (string) config('chatbot.agent.tier2_model', 'gpt-5.4');
        }
        return (string) config('chatbot.agent.model', 'gpt-5.4-mini');
    }
}
