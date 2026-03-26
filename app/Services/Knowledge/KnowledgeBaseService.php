<?php

namespace App\Services\Knowledge;

use App\Models\KnowledgeArticle;
use Illuminate\Support\Facades\Log;

/**
 * KnowledgeBaseService — Tahap 10
 *
 * Retrieves and ranks knowledge articles using a simple, dependency-free
 * keyword-scoring algorithm.  No external search engines, no embeddings.
 *
 * Scoring weights (per matched token):
 *   Title match    : +3.0 pts
 *   Keyword match  : +2.0 pts
 *   Category match : +2.0 pts
 *   Content match  : +0.5 pts (capped at 3 content hits to avoid content-heavy articles dominating)
 *
 * The score threshold (min_keyword_match_score) is configurable in config/chatbot.php.
 * Results are sorted descending by score and limited by max_in_prompt.
 */
class KnowledgeBaseService
{
    /**
     * Common Indonesian stop words filtered out before scoring.
     * Keeping the list lean avoids filtering out meaningful business terms.
     *
     * @var array<int, string>
     */
    private const STOP_WORDS = [
        'yang', 'dan', 'di', 'ke', 'dari', 'untuk', 'adalah', 'ini', 'itu',
        'dengan', 'atau', 'jika', 'bisa', 'ada', 'tidak', 'ya', 'iya', 'mohon',
        'tolong', 'boleh', 'mau', 'saya', 'kami', 'kamu', 'anda', 'tanya',
        'bagaimana', 'kapan', 'berapa', 'apa', 'siapa', 'dimana', 'kenapa',
        'halo', 'hai', 'selamat', 'pagi', 'siang', 'sore', 'malam',
    ];

    /**
     * Search for relevant knowledge articles based on the user query.
     *
     * @param  string               $query    Raw user message or derived search text.
     * @param  array<string, mixed> $options  Override config values per-call:
     *                                        'max_candidates', 'max_in_prompt', 'min_score',
     *                                        'category_boost' (string), 'intent' (string).
     * @return array<int, array{id: int, title: string, category: string, score: float, excerpt: string, content: string, keywords: array}>
     */
    public function search(string $query, array $options = []): array
    {
        if (! config('chatbot.knowledge.enabled', true)) {
            return [];
        }

        $maxCandidates = (int) ($options['max_candidates'] ?? config('chatbot.knowledge.max_candidates', 30));
        $maxResults    = (int) ($options['max_in_prompt']   ?? config('chatbot.knowledge.max_in_prompt', 3));
        $minScore      = (float) ($options['min_score']     ?? config('chatbot.knowledge.min_keyword_match_score', 2.0));
        $categoryBoost = $options['category_boost'] ?? null; // e.g. 'payment', 'schedule'
        $intentHint    = $options['intent'] ?? null;

        $tokens = $this->tokenize($query);

        if (empty($tokens)) {
            return [];
        }

        try {
            // Fetch a reasonable pool of active articles ordered by recency.
            // We do not add SQL LIKE filtering here — the article pool should be
            // small enough that PHP-level scoring is fast and avoids N+1 issues.
            $articles = KnowledgeArticle::active()
                ->orderBy('updated_at', 'desc')
                ->limit($maxCandidates)
                ->get(['id', 'title', 'category', 'content', 'keywords']);

            $scored = [];

            foreach ($articles as $article) {
                $score = $this->score($article, $tokens, $categoryBoost, $intentHint);

                if ($score >= $minScore) {
                    $scored[] = [
                        'id'       => $article->id,
                        'title'    => $article->title,
                        'category' => $article->category,
                        'score'    => round($score, 2),
                        'excerpt'  => $this->excerpt($article->content, $tokens),
                        'content'  => $article->content,
                        'keywords' => $article->keywords ?? [],
                    ];
                }
            }

            // Sort descending by score, keep top results
            usort($scored, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);

            return array_slice($scored, 0, $maxResults);
        } catch (\Throwable $e) {
            Log::warning('KnowledgeBaseService: search failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Build a compact, prompt-friendly context block from scored articles.
     * Intended to be injected into system or user prompts.
     * Kept intentionally short to avoid prompt bloat.
     *
     * @param  array<int, array{id: int, title: string, category: string, excerpt: string, score: float}>  $articles
     */
    public function buildContextBlock(array $articles): string
    {
        if (empty($articles)) {
            return '';
        }

        $lines = ['[PENGETAHUAN TERSEDIA — gunakan sebagai sumber utama jika relevan]'];

        foreach ($articles as $i => $article) {
            $num      = $i + 1;
            $title    = mb_strtoupper($article['title']);
            $category = $article['category'];
            $excerpt  = $article['excerpt'];

            $lines[] = "{$num}. {$title} [kategori: {$category}]";
            $lines[] = "   {$excerpt}";
        }

        $lines[] = '';
        $lines[] = 'Catatan: Jika informasi tidak ada dalam knowledge di atas, jangan mengarang. Katakan bahwa informasi akan dicek.';

        return implode("\n", $lines);
    }

    /**
     * Build a minimal category/title hint for intent or extraction prompts.
     * Much shorter than buildContextBlock() — suitable for lean prompts.
     *
     * @param  array<int, array{id: int, title: string, category: string}>  $articles
     */
    public function buildCompactHint(array $articles): string
    {
        if (empty($articles)) {
            return '';
        }

        $titles = array_map(static fn (array $a): string => "{$a['title']} [{$a['category']}]", $articles);

        return '[Topik terkait: ' . implode(', ', $titles) . ']';
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Score an article against the given tokens.
     *
     * @param  array<int, string>  $tokens
     */
    private function score(
        KnowledgeArticle $article,
        array $tokens,
        ?string $categoryBoost,
        ?string $intentHint,
    ): float {
        $score         = 0.0;
        $titleLower    = mb_strtolower($article->title);
        $contentLower  = mb_strtolower($article->content);
        $categoryLower = mb_strtolower($article->category);
        $keywords      = array_map('mb_strtolower', $article->keywords ?? []);
        $contentHits   = 0;

        foreach ($tokens as $token) {
            // --- Title match (highest weight) ---
            if (str_contains($titleLower, $token)) {
                $score += 3.0;
            }

            // --- Keyword match ---
            foreach ($keywords as $kw) {
                if ($kw !== '' && (str_contains($kw, $token) || str_contains($token, $kw))) {
                    $score += 2.0;
                    break; // One keyword match per token is enough
                }
            }

            // --- Category match ---
            if (str_contains($categoryLower, $token)) {
                $score += 2.0;
            }

            // --- Content match (capped to avoid long-content bias) ---
            if ($contentHits < 3 && str_contains($contentLower, $token)) {
                $score += 0.5;
                $contentHits++;
            }
        }

        // --- Category boost: caller can hint a known category ---
        if ($categoryBoost !== null && str_contains($categoryLower, mb_strtolower($categoryBoost))) {
            $score += 1.5;
        }

        // --- Intent hint: minor boost for articles whose category aligns with intent ---
        if ($intentHint !== null && $this->intentMatchesCategory($intentHint, $categoryLower)) {
            $score += 1.0;
        }

        return $score;
    }

    /**
     * Map known intent values to likely category keywords for soft boosting.
     */
    private function intentMatchesCategory(string $intent, string $categoryLower): bool
    {
        return match ($intent) {
            'price_inquiry'    => str_contains($categoryLower, 'harga') || str_contains($categoryLower, 'price') || str_contains($categoryLower, 'tarif'),
            'schedule_inquiry' => str_contains($categoryLower, 'jadwal') || str_contains($categoryLower, 'schedule'),
            'booking'          => str_contains($categoryLower, 'booking') || str_contains($categoryLower, 'pesan'),
            'location_inquiry' => str_contains($categoryLower, 'lokasi') || str_contains($categoryLower, 'rute'),
            'payment'          => str_contains($categoryLower, 'bayar') || str_contains($categoryLower, 'payment'),
            'support'          => str_contains($categoryLower, 'bantuan') || str_contains($categoryLower, 'support'),
            default            => false,
        };
    }

    /**
     * Extract the most relevant excerpt from article content.
     * Prefers the sentence containing the most query tokens.
     * Falls back to the first sentence or the beginning of content.
     *
     * @param  array<int, string>  $tokens
     */
    private function excerpt(string $content, array $tokens): string
    {
        if ($content === '') {
            return '';
        }

        // Split into sentences on common punctuation
        $sentences = preg_split('/(?<=[.!?])\s+/', trim($content), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (empty($sentences)) {
            return mb_substr($content, 0, 200);
        }

        // Find the sentence with the most token hits
        $best      = $sentences[0];
        $bestScore = 0;

        foreach ($sentences as $sentence) {
            $lower = mb_strtolower($sentence);
            $hits  = 0;
            foreach ($tokens as $token) {
                if (str_contains($lower, $token)) {
                    $hits++;
                }
            }
            if ($hits > $bestScore) {
                $bestScore = $hits;
                $best      = $sentence;
            }
        }

        return mb_substr(trim($best), 0, 200);
    }

    /**
     * Tokenize the query string:
     *  1. Lowercase
     *  2. Remove non-word characters
     *  3. Split on whitespace
     *  4. Remove stop words and very short tokens
     *
     * @return array<int, string>
     */
    private function tokenize(string $text): array
    {
        $lower   = mb_strtolower($text);
        $cleaned = (string) preg_replace('/[^\w\s]/u', ' ', $lower);
        $parts   = (array) preg_split('/\s+/', trim($cleaned), -1, PREG_SPLIT_NO_EMPTY);

        return array_values(array_filter(
            $parts,
            static fn (string $t): bool => mb_strlen($t) > 2 && ! in_array($t, self::STOP_WORDS, true),
        ));
    }
}
