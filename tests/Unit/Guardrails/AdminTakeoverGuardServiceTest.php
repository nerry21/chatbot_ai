<?php

namespace Tests\Unit\Guardrails;

use App\Enums\ConversationStatus;
use App\Models\Conversation;
use App\Services\Chatbot\Guardrails\AdminTakeoverGuardService;
use Tests\TestCase;

class AdminTakeoverGuardServiceTest extends TestCase
{
    public function test_it_suppresses_automation_for_manual_human_takeover(): void
    {
        $conversation = new Conversation([
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'admin',
            'handoff_admin_id' => 5,
            'assigned_admin_id' => 5,
            'bot_paused' => true,
            'bot_paused_reason' => 'human_takeover',
        ]);

        $guard = new AdminTakeoverGuardService();
        $context = $guard->context($conversation);

        $this->assertTrue($guard->shouldSuppressAutomation($conversation));
        $this->assertTrue($context['admin_takeover']);
        $this->assertSame('human_takeover', $context['operational_mode']);
        $this->assertSame(5, $context['assigned_admin_id']);
    }

    public function test_it_suppresses_automation_for_escalation_pause_without_claiming_human_takeover(): void
    {
        $conversation = new Conversation([
            'status' => ConversationStatus::Escalated,
            'handoff_mode' => 'bot',
            'assigned_admin_id' => null,
            'bot_paused' => true,
            'bot_paused_reason' => 'customer_needs_admin',
            'needs_human' => true,
        ]);

        $guard = new AdminTakeoverGuardService();
        $context = $guard->context($conversation);

        $this->assertTrue($guard->shouldSuppressAutomation($conversation));
        $this->assertFalse($conversation->isAdminTakeover());
        $this->assertSame('escalated', $context['operational_mode']);
        $this->assertTrue($context['bot_paused']);
    }
}
