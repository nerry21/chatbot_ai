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
            'crm_context' => [
                'customer' => [
                    'name' => 'Nerry',
                    'phone_e164' => '+6281234567890',
                    'tags' => ['pelanggan_baru', 'booking_draft'],
                ],
                'lead_pipeline' => [
                    'stage' => 'engaged',
                ],
                'conversation' => [
                    'current_intent' => 'booking',
                    'summary' => 'Customer sedang menyiapkan booking.',
                    'needs_human' => false,
                ],
                'booking' => [
                    'booking_status' => 'draft',
                    'pickup_location' => 'Ujung Batu',
                    'destination' => 'Pekanbaru',
                    'departure_date' => '2026-04-05',
                    'departure_time' => '08:00',
                    'missing_fields' => ['passenger_count'],
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
        $this->assertStringContainsString('=== KONTEKS CRM TERPADU (FAKTA BISNIS) ===', $intentPrompt['user']);
        $this->assertStringContainsString('Stage pipeline internal: engaged', $intentPrompt['user']);
        $this->assertStringContainsString('Status booking: draft', $intentPrompt['user']);
        $this->assertStringContainsString('Data booking yang masih kurang: passenger_count', $intentPrompt['user']);
        $this->assertStringContainsString('=== KONTEKS CRM TERPADU (FAKTA BISNIS) ===', $extractionPrompt['user']);
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
            'crm_context' => [
                'customer' => [
                    'name' => 'Nerry',
                    'phone_e164' => '+6281234567890',
                    'tags' => ['pernah_booking'],
                ],
                'lead_pipeline' => [
                    'stage' => 'awaiting_confirmation',
                ],
                'conversation' => [
                    'current_intent' => 'tanya_harga',
                    'summary' => 'Customer bertanya harga.',
                    'needs_human' => true,
                ],
                'escalation' => [
                    'has_open_escalation' => true,
                    'priority' => 'normal',
                    'reason' => 'Butuh admin.',
                ],
                'business_flags' => [
                    'admin_takeover_active' => true,
                    'bot_paused' => true,
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
        $this->assertStringContainsString('=== KONTEKS CRM TERPADU (FAKTA BISNIS) ===', $prompt['user']);
        $this->assertStringContainsString('Stage pipeline internal: awaiting_confirmation', $prompt['user']);
        $this->assertStringContainsString('Ada escalation terbuka: ya', $prompt['user']);
        $this->assertStringContainsString('Admin takeover aktif: ya', $prompt['user']);
    }
}
