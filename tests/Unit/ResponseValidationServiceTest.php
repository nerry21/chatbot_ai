<?php

namespace Tests\Unit;

use App\Services\AI\ResponseValidationService;
use Tests\TestCase;

class ResponseValidationServiceTest extends TestCase
{
    public function test_it_validates_reply_and_blocks_operational_claims(): void
    {
        $service = new ResponseValidationService;

        $result = $service->validateAndFinalize(
            replyResult: [
                'reply' => 'Booking Anda sudah dikonfirmasi dan pasti tersedia.',
                'tone' => 'ramah',
                'should_escalate' => false,
                'next_action' => 'answer_question',
                'data_requests' => [],
                'safety_notes' => ['draft_reply'],
                'meta' => [
                    'source' => 'llm_reply_with_crm_context',
                ],
            ],
            context: [
                'crm_context' => [
                    'conversation' => [
                        'needs_human' => false,
                    ],
                    'business_flags' => [
                        'admin_takeover_active' => false,
                    ],
                    'booking' => [
                        'missing_fields' => ['pickup_location'],
                    ],
                ],
            ],
            intentResult: [
                'intent' => 'booking',
                'should_escalate' => false,
            ],
            ruleEvaluation: [
                'rule_hits' => ['booking_missing_fields', 'overclaim_operational_certainty'],
                'actions' => [
                    'force_handoff' => false,
                    'block_claims' => ['booking_confirmation', 'operational_certainty'],
                ],
            ],
        );

        $this->assertSame('ask_missing_data', $result['next_action']);
        $this->assertSame(['pickup_location'], $result['data_requests']);
        $this->assertStringContainsString('booking Anda akan saya bantu proses setelah data lengkap', $result['reply']);
        $this->assertStringContainsString('akan saya bantu cek ketersediaannya', $result['reply']);
        $this->assertContains('booking_missing_fields', $result['safety_notes']);
        $this->assertContains('overclaim_operational_certainty', $result['safety_notes']);
        $this->assertSame($result['reply'], $result['text']);
    }

    public function test_it_forces_handoff_for_admin_takeover(): void
    {
        $service = new ResponseValidationService;

        $result = $service->validateAndFinalize(
            replyResult: [
                'reply' => 'Baik, saya bantu cek.',
                'meta' => [],
            ],
            context: [
                'crm_context' => [
                    'business_flags' => [
                        'admin_takeover_active' => true,
                    ],
                ],
            ],
            intentResult: [],
            ruleEvaluation: [
                'actions' => [
                    'force_handoff' => false,
                ],
                'rule_hits' => ['admin_takeover_active'],
            ],
        );

        $this->assertTrue($result['should_escalate']);
        $this->assertTrue($result['meta']['force_handoff']);
        $this->assertSame('Admin takeover active', $result['handoff_reason']);
    }
}
