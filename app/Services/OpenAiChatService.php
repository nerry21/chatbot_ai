<?php

namespace App\Services;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiChatService
{
    public function detectIntent(string $userMessage, array $context = []): array
    {
        $developerPrompt = <<<PROMPT
Anda adalah modul klasifikasi intent untuk chatbot WhatsApp travel.

Tugas:
- Baca pesan user.
- Tentukan intent utama.
- Jangan menjawab panjang.
- Kembalikan HASIL HANYA DALAM JSON VALID.

Format JSON:
{
  "intent": "greeting|faq|booking|reschedule|cancel|payment|complaint|location|schedule|unknown",
  "confidence": 0.0,
  "reason": "alasan singkat"
}
PROMPT;

        $input = $this->buildInput(
            developerPrompt: $developerPrompt,
            userMessage: $userMessage,
            context: $context
        );

        $text = $this->requestText(
            model: (string) config('services.openai.model_intent'),
            reasoningEffort: (string) config('services.openai.reasoning_effort_intent', 'low'),
            maxOutputTokens: (int) config('services.openai.max_output_tokens_intent', 300),
            input: $input
        );

        return $this->safeJsonDecode($text, [
            'intent' => 'unknown',
            'confidence' => 0,
            'reason' => 'fallback',
        ]);
    }

    public function extractBookingData(string $userMessage, array $context = []): array
    {
        $developerPrompt = <<<PROMPT
Anda adalah modul ekstraksi data booking travel dari pesan WhatsApp.

Tugas:
- Ambil data penting dari pesan user.
- Jangan mengarang.
- Jika data tidak ada, isi null.
- Kembalikan HASIL HANYA DALAM JSON VALID.

Format JSON:
{
  "passenger_name": null,
  "origin": null,
  "destination": null,
  "departure_date": null,
  "departure_time": null,
  "seat_count": null,
  "phone": null,
  "notes": null
}
PROMPT;

        $input = $this->buildInput(
            developerPrompt: $developerPrompt,
            userMessage: $userMessage,
            context: $context
        );

        $text = $this->requestText(
            model: (string) config('services.openai.model_extraction'),
            reasoningEffort: (string) config('services.openai.reasoning_effort_extraction', 'low'),
            maxOutputTokens: (int) config('services.openai.max_output_tokens_extraction', 500),
            input: $input
        );

        return $this->safeJsonDecode($text, [
            'passenger_name' => null,
            'origin' => null,
            'destination' => null,
            'departure_date' => null,
            'departure_time' => null,
            'seat_count' => null,
            'phone' => null,
            'notes' => null,
        ]);
    }

    public function generateReply(
        string $userMessage,
        array $context = [],
        ?string $businessRules = null
    ): string {
        $developerPrompt = <<<PROMPT
Anda adalah admin WhatsApp travel sungguhan.

Gaya jawaban:
- natural
- sopan
- hangat
- singkat tapi jelas
- jangan terasa seperti robot
- jangan mengarang informasi yang tidak ada
- jika data belum cukup, arahkan user dengan pertanyaan yang singkat
- prioritaskan membantu user sampai selesai

Aturan:
- Gunakan konteks yang diberikan.
- Patuhi aturan bisnis bila tersedia.
- Jangan menjanjikan hal yang tidak pasti.
- Jika jadwal/harga tidak ada di konteks, katakan dengan jujur dan minta data tambahan secara singkat.
PROMPT;

        if ($businessRules) {
            $developerPrompt .= "\n\nAturan bisnis tambahan:\n" . $businessRules;
        }

        $input = $this->buildInput(
            developerPrompt: $developerPrompt,
            userMessage: $userMessage,
            context: $context
        );

        return $this->requestText(
            model: (string) config('services.openai.model_reply'),
            reasoningEffort: (string) config('services.openai.reasoning_effort_reply', 'medium'),
            maxOutputTokens: (int) config('services.openai.max_output_tokens_reply', 700),
            input: $input
        );
    }

    public function summarizeConversation(array $messages, array $context = []): string
    {
        $developerPrompt = <<<PROMPT
Anda adalah modul ringkasan percakapan chatbot WhatsApp.

Tugas:
- Ringkas isi percakapan secara padat.
- Sorot maksud user, data penting, status proses, dan tindak lanjut.
- Gunakan bahasa Indonesia.
- Maksimal ringkas dan jelas.
PROMPT;

        $conversationText = $this->messagesToText($messages);

        $input = $this->buildInput(
            developerPrompt: $developerPrompt,
            userMessage: $conversationText,
            context: $context
        );

        return $this->requestText(
            model: (string) config('services.openai.model_summary'),
            reasoningEffort: (string) config('services.openai.reasoning_effort_summary', 'low'),
            maxOutputTokens: (int) config('services.openai.max_output_tokens_summary', 250),
            input: $input
        );
    }

    protected function requestText(
        string $model,
        string $reasoningEffort,
        int $maxOutputTokens,
        array $input
    ): string {
        $apiKey = (string) config('services.openai.api_key');
        $baseUrl = rtrim((string) config('services.openai.base_url', 'https://api.openai.com/v1'), '/');
        $timeout = (int) config('services.openai.timeout', 90);

        if ($apiKey === '') {
            throw new RuntimeException('OPENAI_API_KEY belum diatur.');
        }

        $payload = [
            'model' => $model,
            'reasoning' => [
                'effort' => $reasoningEffort,
            ],
            'max_output_tokens' => $maxOutputTokens,
            'input' => $input,
        ];

        try {
            $response = Http::timeout($timeout)
                ->acceptJson()
                ->withToken($apiKey)
                ->post($baseUrl . '/responses', $payload)
                ->throw();

            $data = $response->json();

            $text = $this->extractOutputText(is_array($data) ? $data : []);

            if ($text === '') {
                throw new RuntimeException('OpenAI mengembalikan respons kosong.');
            }

            return trim($text);
        } catch (RequestException $e) {
            $body = $e->response?->json();
            $message = Arr::get($body, 'error.message')
                ?? $e->getMessage()
                ?? 'Gagal menghubungi OpenAI.';

            throw new RuntimeException('OpenAI request gagal: ' . $message, previous: $e);
        }
    }

    protected function buildInput(string $developerPrompt, string $userMessage, array $context = []): array
    {
        $content = [];

        $content[] = [
            'type' => 'input_text',
            'text' => $developerPrompt,
        ];

        if (! empty($context)) {
            $content[] = [
                'type' => 'input_text',
                'text' => "Konteks tambahan:\n" . json_encode(
                    $context,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ),
            ];
        }

        $developer = [
            'role' => 'developer',
            'content' => $content,
        ];

        $user = [
            'role' => 'user',
            'content' => [
                [
                    'type' => 'input_text',
                    'text' => $userMessage,
                ],
            ],
        ];

        return [$developer, $user];
    }

    protected function extractOutputText(array $data): string
    {
        $outputText = Arr::get($data, 'output_text');
        if (is_string($outputText) && trim($outputText) !== '') {
            return trim($outputText);
        }

        $output = Arr::get($data, 'output', []);
        if (! is_array($output)) {
            return '';
        }

        $texts = [];

        foreach ($output as $item) {
            $contents = $item['content'] ?? [];

            if (! is_array($contents)) {
                continue;
            }

            foreach ($contents as $content) {
                $text = $content['text'] ?? null;

                if (is_string($text) && trim($text) !== '') {
                    $texts[] = trim($text);
                }
            }
        }

        return trim(implode("\n", $texts));
    }

    protected function safeJsonDecode(string $text, array $fallback = []): array
    {
        $clean = trim($text);

        if (str_starts_with($clean, '```')) {
            $clean = preg_replace('/^```(?:json)?/i', '', $clean) ?? $clean;
            $clean = preg_replace('/```$/', '', $clean) ?? $clean;
            $clean = trim($clean);
        }

        $decoded = json_decode($clean, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        if (preg_match('/\{.*\}/s', $clean, $matches)) {
            $decoded = json_decode($matches[0], true);

            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return $fallback;
    }

    protected function messagesToText(array $messages): string
    {
        $lines = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'unknown');
            $text = (string) ($message['text'] ?? $message['content'] ?? '');

            if ($text !== '') {
                $lines[] = strtoupper($role) . ': ' . $text;
            }
        }

        return implode("\n", $lines);
    }
}
