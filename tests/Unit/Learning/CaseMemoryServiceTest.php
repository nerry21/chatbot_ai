<?php

namespace Tests\Unit\Learning;

use App\Models\ChatbotCaseMemory;
use App\Services\AI\Learning\CaseMemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaseMemoryServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_returns_relevant_examples_by_intent_and_message_overlap(): void
    {
        ChatbotCaseMemory::create([
            'source_type' => 'learning_signal',
            'intent' => 'tanya_jam',
            'sub_intent' => null,
            'user_message' => 'besok jam 10 ke pekanbaru ada?',
            'context_summary' => 'cek jadwal ke pekanbaru',
            'successful_response' => 'Untuk besok jam 10 ke Pekanbaru tersedia.',
            'example_payload' => [],
            'tags' => ['intent:tanya_jam'],
            'is_active' => true,
        ]);

        ChatbotCaseMemory::create([
            'source_type' => 'learning_signal',
            'intent' => 'tanya_harga',
            'sub_intent' => null,
            'user_message' => 'berapa harga ke bangkinang?',
            'context_summary' => 'cek ongkos ke bangkinang',
            'successful_response' => 'Ongkosnya Rp100.000.',
            'example_payload' => [],
            'tags' => ['intent:tanya_harga'],
            'is_active' => true,
        ]);

        $examples = app(CaseMemoryService::class)->findRelevantExamples(
            messageText: 'besok ke pekanbaru jam 10 ada ya?',
            intent: 'tanya_jam',
            limit: 2,
        );

        $this->assertCount(1, $examples);
        $this->assertSame('tanya_jam', $examples->first()->intent);
        $this->assertStringContainsString('Pekanbaru', $examples->first()->successful_response);
    }
}
