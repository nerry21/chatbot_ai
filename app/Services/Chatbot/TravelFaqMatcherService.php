<?php

namespace App\Services\Chatbot;

use App\Services\Knowledge\KnowledgeBaseService;

/**
 * TravelFaqMatcherService
 *
 * Provides a simple, chatbot-layer FAQ API by delegating search to
 * KnowledgeBaseService (which reads the knowledge_articles table).
 *
 * Note: there is no separate chatbot_faqs table.  All knowledge lives in
 * knowledge_articles and is managed via KnowledgeBaseService.
 *
 * Admin phone: chatbot.jet.admin_phone
 */
class TravelFaqMatcherService
{
    /**
     * Minimum KnowledgeBaseService score for a direct local answer.
     * Below this, shouldFallbackToAdmin() returns true.
     */
    public const FALLBACK_SCORE_THRESHOLD = 3.5;

    public function __construct(
        private readonly KnowledgeBaseService $knowledgeBase,
    ) {}

    /**
     * Find the best matching knowledge article for the incoming text.
     *
     * @return array{
     *     id: int,
     *     title: string,
     *     category: string,
     *     answer: string,
     *     score: float,
     * }|null
     */
    public function match(string $incomingText): ?array
    {
        if (trim($incomingText) === '') {
            return null;
        }

        $hits = $this->knowledgeBase->search($incomingText, ['max_in_prompt' => 1]);

        if ($hits === []) {
            return null;
        }

        $top = $hits[0];

        return [
            'id' => $top['id'],
            'title' => $top['title'],
            'category' => $top['category'],
            'answer' => $this->extractAnswer($top),
            'score' => (float) ($top['score'] ?? 0.0),
        ];
    }

    /**
     * Return the best answer text, or null when nothing matches.
     */
    public function getAnswer(string $incomingText): ?string
    {
        $match = $this->match($incomingText);

        return $match['answer'] ?? null;
    }

    /**
     * Return true when the bot cannot answer confidently and should hand off
     * the conversation to an admin.
     *
     * @param  float  $minimumScore  Override the default threshold if needed.
     */
    public function shouldFallbackToAdmin(string $incomingText, float $minimumScore = self::FALLBACK_SCORE_THRESHOLD): bool
    {
        $match = $this->match($incomingText);

        if ($match === null) {
            return true;
        }

        return $match['score'] < $minimumScore;
    }

    /**
     * Text sent to the customer when the bot falls back to admin.
     */
    public function buildFallbackCustomerMessage(): string
    {
        return 'Izin Bapak/Ibu, terima kasih atas pertanyaannya. Izin kami konsultasikan dahulu ke tim kami ya.';
    }

    /**
     * Text forwarded to the admin when the bot cannot answer.
     * Uses the admin phone from chatbot.jet.admin_phone as a reference in logs;
     * the caller is responsible for actually routing the message.
     */
    public function buildFallbackAdminMessage(string $customerPhone): string
    {
        $masked = $this->maskPhone($customerPhone);

        return "Bos, ada pertanyaan dari nomor {$masked} yang belum bisa dijawab bot. Bisa bantu dijawab bos?";
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    /**
     * @param  array{excerpt: string, content: string}  $hit
     */
    private function extractAnswer(array $hit): string
    {
        $text = trim($hit['excerpt'] ?? '');

        if (mb_strlen($text) < 40) {
            $text = trim($hit['content'] ?? '');
        }

        if (mb_strlen($text) > 400) {
            $truncated = mb_substr($text, 0, 400);
            $lastPunct = max(
                mb_strrpos($truncated, '.') ?: 0,
                mb_strrpos($truncated, '!') ?: 0,
                mb_strrpos($truncated, '?') ?: 0,
            );

            if ($lastPunct > 200) {
                $truncated = mb_substr($truncated, 0, $lastPunct + 1);
            }

            $text = $truncated;
        }

        return $text;
    }

    private function maskPhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone) ?? '';
        $len = strlen($digits);

        if ($len <= 6) {
            return str_repeat('*', $len);
        }

        return substr($digits, 0, 4).str_repeat('*', $len - 6).substr($digits, -2);
    }
}
