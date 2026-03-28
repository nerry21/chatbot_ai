<?php

namespace Tests\Unit;

use App\Services\OpenAiChatService;
use Tests\TestCase;

class OpenAiChatServiceTest extends TestCase
{
    public function test_safe_json_decode_reads_json_inside_markdown_fence(): void
    {
        $service = new class extends OpenAiChatService {
            public function exposeSafeJsonDecode(string $text, array $fallback = []): array
            {
                return $this->safeJsonDecode($text, $fallback);
            }
        };

        $result = $service->exposeSafeJsonDecode(
            <<<TEXT
```json
{"intent":"booking","confidence":0.92,"reason":"user ingin pesan travel"}
```
TEXT,
            ['intent' => 'unknown'],
        );

        $this->assertSame('booking', $result['intent']);
        $this->assertSame(0.92, $result['confidence']);
    }

    public function test_extract_output_text_prefers_output_text_then_content_blocks(): void
    {
        $service = new class extends OpenAiChatService {
            public function exposeExtractOutputText(array $data): string
            {
                return $this->extractOutputText($data);
            }
        };

        $fromOutputText = $service->exposeExtractOutputText([
            'output_text' => 'halo dari output_text',
        ]);

        $fromContentBlocks = $service->exposeExtractOutputText([
            'output' => [
                [
                    'content' => [
                        ['text' => 'baris 1'],
                        ['text' => 'baris 2'],
                    ],
                ],
            ],
        ]);

        $this->assertSame('halo dari output_text', $fromOutputText);
        $this->assertSame("baris 1\nbaris 2", $fromContentBlocks);
    }

    public function test_messages_to_text_formats_role_and_text(): void
    {
        $service = new class extends OpenAiChatService {
            public function exposeMessagesToText(array $messages): string
            {
                return $this->messagesToText($messages);
            }
        };

        $result = $service->exposeMessagesToText([
            ['role' => 'user', 'text' => 'Halo'],
            ['role' => 'assistant', 'content' => 'Siap bantu'],
        ]);

        $this->assertSame("USER: Halo\nASSISTANT: Siap bantu", $result);
    }
}
