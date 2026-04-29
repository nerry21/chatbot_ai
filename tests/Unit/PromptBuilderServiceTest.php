<?php

namespace Tests\Unit;

use App\Services\AI\PromptBuilderService;
use App\Services\AI\UnderstandingCrmContextFormatterService;
use Tests\TestCase;

class PromptBuilderServiceTest extends TestCase
{
    private function makeService(): PromptBuilderService
    {
        return new PromptBuilderService(new UnderstandingCrmContextFormatterService);
    }

    public function test_it_includes_hubspot_context_in_intent_and_extraction_prompts_when_enabled(): void
    {
        config([
            'chatbot.crm.ai_context.include_in_intent_tasks' => true,
            'chatbot.crm.ai_context.include_in_extraction_tasks' => true,
        ]);

        $service = $this->makeService();
        $context = [
            'message_text' => 'saya mau booking ke pekanbaru',
            'intent_result' => ['intent' => 'booking'],
            'customer_memory' => [
                'customer_profile' => [
                    'name' => 'Nerry',
                    'phone_e164' => '+6281234567890',
                ],
                'relationship_memory' => [
                    'preferred_pickup' => 'Ujung Batu',
                    'preferred_destination' => 'Pekanbaru',
                ],
            ],
            'crm_context' => [
                'customer' => [
                    'name' => 'Nerry',
                    'phone_e164' => '+6281234567890',
                    'total_bookings' => 0,
                    'tags' => ['pelanggan_baru', 'booking_draft'],
                ],
                'hubspot' => [
                    'company' => 'PT Travel Jaya',
                    'lifecycle_stage' => 'customer',
                    'lead_status' => 'OPEN',
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
                    'ready_for_confirmation' => false,
                    'missing_fields' => ['passenger_count'],
                ],
            ],
            'conversation_summary' => 'Pelanggan sedang menanyakan booking Pekanbaru.',
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
        $this->assertStringContainsString('=== HIRARKI KEPUTUSAN WAJIB ===', $intentPrompt['user']);
        $this->assertStringContainsString('=== ARAH TINDAKAN YANG DIUTAMAKAN ===', $intentPrompt['user']);
        $this->assertStringContainsString('Pelanggan ini kemungkinan baru. Gunakan penjelasan singkat dan jelas.', $intentPrompt['user']);
        $this->assertStringContainsString('=== RINGKASAN BISNIS TERAKHIR ===', $intentPrompt['user']);
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

        $service = $this->makeService();
        $prompt = $service->buildReplyPrompt([
            'message_text' => 'berapa harga ke pekanbaru',
            'intent_result' => ['intent' => 'tanya_harga'],
            'customer_memory' => [
                'customer_profile' => [
                    'name' => 'Nerry',
                ],
            ],
            'crm_context' => [
                'customer' => [
                    'name' => 'Nerry',
                    'phone_e164' => '+6281234567890',
                    'total_bookings' => 4,
                    'tags' => ['pernah_booking'],
                ],
                'hubspot' => [
                    'company' => 'PT Travel Jaya',
                    'job_title' => 'Procurement',
                    'lifecycle_stage' => 'opportunity',
                    'lead_status' => 'QUALIFIED',
                    'score' => '77',
                    'source' => 'hubspot_api',
                ],
                'lead_pipeline' => [
                    'stage' => 'awaiting_confirmation',
                ],
                'conversation' => [
                    'current_intent' => 'tanya_harga',
                    'summary' => 'Customer bertanya harga.',
                    'needs_human' => true,
                ],
                'booking' => [
                    'missing_fields' => ['pickup_location', 'destination'],
                    'ready_for_confirmation' => false,
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
        $this->assertStringContainsString('CRM score: 77', $prompt['user']);
        $this->assertStringContainsString('Sumber CRM: hubspot_api', $prompt['user']);
        $this->assertStringContainsString('=== KONTEKS CRM TERPADU (FAKTA BISNIS) ===', $prompt['user']);
        $this->assertStringContainsString('=== HIRARKI KEPUTUSAN WAJIB ===', $prompt['user']);
        $this->assertStringContainsString('=== BATASAN WAJABAN OPERASIONAL ===', $prompt['user']);
        $this->assertStringContainsString('=== ARAH TINDAKAN YANG DIUTAMAKAN ===', $prompt['user']);
        $this->assertStringContainsString('=== FORMAT OUTPUT WAJIB ===', $prompt['user']);
        $this->assertStringContainsString('Stage pipeline internal: awaiting_confirmation', $prompt['user']);
        $this->assertStringContainsString('Ada escalation terbuka: ya', $prompt['user']);
        $this->assertStringContainsString('Admin takeover aktif: ya', $prompt['user']);
        $this->assertStringContainsString('Kondisi saat ini: admin takeover aktif.', $prompt['user']);
        $this->assertStringContainsString('Data booking yang harus diprioritaskan: pickup_location, destination', $prompt['user']);
        $this->assertStringContainsString('Pelanggan ini pernah bertransaksi. Jaga kesinambungan konteks.', $prompt['user']);
    }

    /**
     * Regression test untuk bug:
     *   "Unknown named parameter $crmContext" yang muncul di IntentClassifierService /
     *   EntityExtractorService / ConversationSummaryService karena
     *   PromptBuilderService::appendUnifiedCrmContextLines() memanggil
     *   UnderstandingCrmContextFormatterService::formatForUnderstanding(crmContext: ...)
     *   padahal signature-nya menerima `array $crmHints`.
     *
     * Test ini memanggil 3 builder publik yang semuanya menjalankan
     * appendUnifiedCrmContextLines() dengan crm_context non-empty.
     * Sebelum fix: TypeError / Error karena named parameter tidak dikenal.
     * Setelah fix: berjalan normal dan menghasilkan blok CRM di prompt.
     */
    public function test_unified_crm_context_lines_does_not_throw_unknown_named_parameter(): void
    {
        $service = $this->makeService();

        $context = [
            'message_text' => 'mau booking ke pekanbaru',
            'intent_result' => ['intent' => 'booking'],
            'crm_context' => [
                'customer' => ['name' => 'Nerry'],
                'lead_pipeline' => ['stage' => 'engaged'],
                'conversation' => ['current_intent' => 'booking'],
            ],
            'conversation_summary' => 'Pelanggan ingin booking.',
            'admin_takeover' => false,
        ];

        $intentPrompt = $service->buildIntentPrompt($context);
        $extractionPrompt = $service->buildExtractionPrompt($context);
        $replyPrompt = $service->buildReplyPrompt($context);

        $this->assertNotEmpty($intentPrompt['user']);
        $this->assertNotEmpty($extractionPrompt['user']);
        $this->assertNotEmpty($replyPrompt['user']);
    }
}