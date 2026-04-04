<?php

namespace Tests\Unit;

use App\Models\Conversation;
use App\Models\Customer;
use App\Services\CRM\CrmDecisionNoteBuilderService;
use Tests\TestCase;

class CrmDecisionNoteBuilderServiceTest extends TestCase
{
    public function test_it_builds_a_readable_decision_note_for_crm(): void
    {
        $customer = new Customer;
        $customer->forceFill([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
        ]);

        $conversation = new Conversation;
        $conversation->forceFill([
            'id' => 77,
        ]);

        $note = app(CrmDecisionNoteBuilderService::class)->build(
            customer: $customer,
            conversation: $conversation,
            intentResult: [
                'intent' => 'booking',
                'confidence' => 0.91,
                'reasoning_short' => 'Customer wants to continue booking.',
            ],
            summaryResult: [
                'summary' => 'Customer asks for route and booking continuation.',
            ],
            finalReply: [
                'text' => 'Baik, saya bantu lanjutkan bookingnya ya.',
                'meta' => [
                    'source' => 'llm_reply_with_crm_context',
                    'action' => 'offer_next_step',
                ],
            ],
            contextSnapshot: [
                'crm_context' => [
                    'lead_pipeline' => [
                        'stage' => 'engaged',
                    ],
                    'hubspot' => [
                        'lifecycle_stage' => 'lead',
                    ],
                    'conversation' => [
                        'status' => 'active',
                        'needs_human' => false,
                    ],
                    'business_flags' => [
                        'bot_paused' => false,
                    ],
                    'escalation' => [
                        'has_open_escalation' => false,
                    ],
                ],
            ],
        );

        $this->assertStringContainsString('=== AI Decision Snapshot ===', $note);
        $this->assertStringContainsString('Pelanggan   : Nerry', $note);
        $this->assertStringContainsString('Intent      : booking', $note);
        $this->assertStringContainsString('Confidence  : 0.91', $note);
        $this->assertStringContainsString('ReplySource : llm_reply_with_crm_context', $note);
        $this->assertStringContainsString('Lead Stage  : engaged', $note);
        $this->assertStringContainsString('Lifecycle   : lead', $note);
        $this->assertStringContainsString('Balasan Final:', $note);
        $this->assertStringContainsString('Baik, saya bantu lanjutkan bookingnya ya.', $note);
    }
}
