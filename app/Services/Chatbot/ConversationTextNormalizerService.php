<?php

namespace App\Services\Chatbot;

class ConversationTextNormalizerService
{
    /**
     * @var array<int, string>
     */
    private const AFFIRMATIVE_WORDS = [
        'ya',
        'iya',
        'oke',
        'ok',
        'mantap',
        'sudah',
        'benar',
        'siap',
        'sesuai',
        'lanjut',
    ];

    /**
     * @var array<int, string>
     */
    private const NEGATIVE_WORDS = [
        'tidak',
        'bukan',
        'salah',
        'ganti',
        'ubah',
        'koreksi',
        'batal',
        'nggak',
        'enggak',
    ];

    public function normalize(string $text): string
    {
        $normalized = mb_strtolower(trim($text), 'UTF-8');
        $normalized = str_replace(["\u{2019}", "'"], '', $normalized);
        $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);

        return $normalized;
    }

    public function isAffirmative(string $text): bool
    {
        return $this->matchesVocabulary($this->normalize($text), self::AFFIRMATIVE_WORDS);
    }

    public function isNegative(string $text): bool
    {
        return $this->matchesVocabulary($this->normalize($text), self::NEGATIVE_WORDS);
    }

    /**
     * @return array<int, string>
     */
    public function affirmativeVocabulary(): array
    {
        return self::AFFIRMATIVE_WORDS;
    }

    /**
     * @return array<int, string>
     */
    public function negativeVocabulary(): array
    {
        return self::NEGATIVE_WORDS;
    }

    /**
     * @param  array<int, string>  $vocabulary
     */
    public function matchesVocabulary(string $normalizedText, array $vocabulary): bool
    {
        foreach ($vocabulary as $word) {
            if ($normalizedText === $word || preg_match('/\b'.preg_quote($word, '/').'\b/u', $normalizedText)) {
                return true;
            }
        }

        return false;
    }
}
