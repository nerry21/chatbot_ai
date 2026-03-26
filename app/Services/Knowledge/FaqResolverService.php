<?php

namespace App\Services\Knowledge;

/**
 * FaqResolverService — Tahap 10
 *
 * Conservative local FAQ resolver.
 * Checks whether the top knowledge hit is confident enough to answer the user
 * directly — without relying on the LLM — based on its score.
 *
 * Design principles:
 *   1. Only answer directly when the top article's score exceeds a high threshold
 *      (FAQ_DIRECT_MIN_SCORE).  Better to say nothing than to give a wrong answer.
 *   2. The constructed answer uses the article's actual content — never invented.
 *   3. If uncertain, return matched = false and let the LLM handle it with knowledge context.
 *   4. This service does NOT know about booking flow.  The caller (ResponseGeneratorService)
 *      must skip direct FAQ answers when a booking is in progress.
 */
class FaqResolverService
{
    /**
     * Minimum score required for a knowledge hit to trigger a direct local answer.
     * This is intentionally high (4× the default min_score) to be conservative.
     */
    private const FAQ_DIRECT_MIN_SCORE = 8.0;

    /**
     * Maximum content length for a direct FAQ answer (characters).
     * Prevents dumping huge articles verbatim into the chat.
     */
    private const MAX_ANSWER_LENGTH = 400;

    /**
     * Attempt to resolve the user's message from local knowledge.
     *
     * @param  string  $userMessage
     * @param  array<int, array{id: int, title: string, category: string, score: float, excerpt: string, content: string}>  $knowledgeHits
     * @return array{matched: bool, answer: string|null, source_titles: array<int, string>}
     */
    public function resolve(string $userMessage, array $knowledgeHits = []): array
    {
        $notMatched = ['matched' => false, 'answer' => null, 'source_titles' => []];

        if (empty($knowledgeHits)) {
            return $notMatched;
        }

        $topHit = $knowledgeHits[0];

        // Only answer directly if the top article scores very highly
        if (($topHit['score'] ?? 0.0) < self::FAQ_DIRECT_MIN_SCORE) {
            return $notMatched;
        }

        $answer = $this->buildAnswer($topHit);

        if ($answer === '') {
            return $notMatched;
        }

        return [
            'matched'       => true,
            'answer'        => $answer,
            'source_titles' => [$topHit['title']],
        ];
    }

    // -------------------------------------------------------------------------
    // Private
    // -------------------------------------------------------------------------

    /**
     * Build a clean, readable answer from a knowledge article.
     * Uses the excerpt if it is informative enough; falls back to the
     * first portion of content.
     *
     * @param  array{title: string, excerpt: string, content: string}  $hit
     */
    private function buildAnswer(array $hit): string
    {
        // Prefer the pre-computed excerpt (most relevant sentence)
        $text = trim($hit['excerpt'] ?? '');

        // If excerpt is very short (< 40 chars), use the content start instead
        if (mb_strlen($text) < 40) {
            $text = trim($hit['content'] ?? '');
        }

        if ($text === '') {
            return '';
        }

        // Truncate to safe max length, breaking at a sentence boundary if possible
        if (mb_strlen($text) > self::MAX_ANSWER_LENGTH) {
            $truncated = mb_substr($text, 0, self::MAX_ANSWER_LENGTH);
            // Try to end at a sentence boundary
            $lastPunct = max(
                mb_strrpos($truncated, '.'),
                mb_strrpos($truncated, '!'),
                mb_strrpos($truncated, '?'),
            );
            if ($lastPunct !== false && $lastPunct > self::MAX_ANSWER_LENGTH / 2) {
                $truncated = mb_substr($truncated, 0, $lastPunct + 1);
            }
            $text = $truncated;
        }

        return $text;
    }
}
