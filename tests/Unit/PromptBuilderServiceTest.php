<?php

namespace Tests\Unit;

use App\Services\AI\PromptBuilderService;
use Tests\TestCase;

class PromptBuilderServiceTest extends TestCase
{
    public function test_it_includes_hubspot_context_in_intent_and_extraction_prompts_when_enabled(): void
    {
        config([
            'chatbot.crm.ai_context.include_in_intent_tasks' => true,
            'chatbot.crm.ai_context.include_in_extraction_tasks' => true,
        ]);

        $service = new PromptBuilderService;
        $context = [
            'message_text' => 'saya mau booking ke pekanbaru',
            'intent_result' => ['intent' => 'booking'],
            'customer_memory' => [
                'primary_name' => 'Nerry',
                'preferred_pickup' => 'Ujung Batu',
                'preferred_destination' => 'Pekanbaru',
                'hubspot' => [
                    'company' => 'PT Travel Jaya',
                    'lifecycle_stage' => 'customer',
                    'lead_status' => 'OPEN',
                ],
            ],
        ];

        $intentPrompt = $service->buildIntentPrompt($context);
        $extractionPrompt = $service->buildExtractionPrompt($context);

        $this->assertStringContainsString('=== INFO CRM HUBSPOT ===', $intentPrompt['user']);
        $this->assertStringContainsString('Lifecycle stage: customer', $intentPrompt['user']);
        $this->assertStringContainsString('Lead status: OPEN', $intentPrompt['user']);
        $this->assertStringContainsString('Perusahaan: PT Travel Jaya', $intentPrompt['user']);

        $this->assertStringContainsString('=== KONTEKS CRM HUBSPOT ===', $extractionPrompt['user']);
        $this->assertStringContainsString('Perusahaan: PT Travel Jaya', $extractionPrompt['user']);
        $this->assertStringContainsString('Lifecycle stage: customer', $extractionPrompt['user']);
        $this->assertStringContainsString('Lead status: OPEN', $extractionPrompt['user']);
    }

    public function test_it_includes_hubspot_context_in_reply_prompt_when_enabled(): void
    {
        config([
            'chatbot.crm.ai_context.include_in_reply_tasks' => true,
        ]);

        $service = new PromptBuilderService;
        $prompt = $service->buildReplyPrompt([
            'message_text' => 'berapa harga ke pekanbaru',
            'intent_result' => ['intent' => 'tanya_harga'],
            'customer_memory' => [
                'primary_name' => 'Nerry',
                'hubspot' => [
                    'company' => 'PT Travel Jaya',
                    'jobtitle' => 'Procurement',
                    'lifecycle_stage' => 'opportunity',
                    'lead_status' => 'QUALIFIED',
                    'score' => '77',
                    'source' => 'hubspot_api',
                ],
            ],
        ]);

        $this->assertStringContainsString('=== INFO CRM HUBSPOT ===', $prompt['user']);
        $this->assertStringContainsString('Perusahaan: PT Travel Jaya', $prompt['user']);
        $this->assertStringContainsString('Jabatan: Procurement', $prompt['user']);
        $this->assertStringContainsString('Lifecycle stage: opportunity', $prompt['user']);
        $this->assertStringContainsString('Lead status: QUALIFIED', $prompt['user']);
        $this->assertStringContainsString('HubSpot score: 77', $prompt['user']);
        $this->assertStringContainsString('Sumber CRM: hubspot_api', $prompt['user']);
    }
}
