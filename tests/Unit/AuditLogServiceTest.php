<?php

namespace Tests\Unit;

use App\Enums\AuditActionType;
use App\Models\AuditLog;
use App\Services\Support\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_ai_orchestration_snapshot(): void
    {
        $service = app(AuditLogService::class);

        $audit = $service->recordAiOrchestration(
            conversationId: 77,
            message: 'AI orchestration final snapshot recorded.',
            snapshot: [
                'intent' => 'booking_inquiry',
                'reply_source' => 'llm_reply_with_crm_context',
                'rule_hits' => ['booking_missing_fields'],
            ],
        );

        $this->assertInstanceOf(AuditLog::class, $audit);
        $this->assertSame(AuditActionType::BotReplyGenerated->value, $audit->action_type);
        $this->assertSame(77, $audit->conversation_id);
        $this->assertSame('AI orchestration final snapshot recorded.', $audit->message);
        $this->assertTrue($audit->context['ai_orchestration']);
        $this->assertSame('booking_inquiry', $audit->context['snapshot']['intent']);
        $this->assertSame(['booking_missing_fields'], $audit->context['snapshot']['rule_hits']);
    }
}
