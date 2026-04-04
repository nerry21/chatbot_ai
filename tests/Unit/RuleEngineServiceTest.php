<?php

namespace Tests\Unit;

use App\Services\AI\RuleEngineService;
use Tests\TestCase;

class RuleEngineServiceTest extends TestCase
{
    public function test_it_evaluates_operational_rules_and_detects_forcing_actions(): void
    {
        $service = new RuleEngineService;

        $result = $service->evaluateOperationalRules(
            context: [
                'crm_context' => [
                    'conversation' => [
                        'needs_human' => true,
                    ],
                    'business_flags' => [
                        'admin_takeover_active' => true,
                    ],
                    'booking' => [
                        'missing_fields' => ['pickup_location', 'destination'],
                    ],
                    'escalation' => [
                        'has_open_escalation' => true,
                    ],
                ],
            ],
            intentResult: [
                'intent' => 'unknown',
            ],
            replyResult: [
                'reply' => 'Booking Anda sudah dikonfirmasi dan pasti tersedia.',
            ],
        );

        $this->assertContains('admin_takeover_active', $result['rule_hits']);
        $this->assertContains('conversation_needs_human', $result['rule_hits']);
        $this->assertContains('open_escalation_exists', $result['rule_hits']);
        $this->assertContains('booking_missing_fields', $result['rule_hits']);
        $this->assertContains('overclaim_operational_certainty', $result['rule_hits']);
        $this->assertTrue($result['actions']['force_handoff']);
        $this->assertTrue($result['actions']['force_ask_missing_data']);
        $this->assertContains('booking_confirmation', $result['actions']['block_claims']);
        $this->assertContains('operational_certainty', $result['actions']['block_claims']);
    }

    public function test_it_builds_missing_data_fallback_from_rules(): void
    {
        $service = new RuleEngineService;

        $result = $service->buildSafeFallbackFromRules(
            context: [
                'crm_context' => [
                    'booking' => [
                        'missing_fields' => ['pickup_location', 'destination'],
                    ],
                ],
            ],
            ruleEvaluation: [
                'rule_hits' => ['booking_missing_fields'],
                'actions' => [
                    'force_ask_missing_data' => true,
                ],
            ],
        );

        $this->assertSame('ask_missing_data', $result['next_action']);
        $this->assertSame(['pickup_location', 'destination'], $result['data_requests']);
        $this->assertSame('rule_engine_missing_data_fallback', $result['meta']['source']);
    }
}
