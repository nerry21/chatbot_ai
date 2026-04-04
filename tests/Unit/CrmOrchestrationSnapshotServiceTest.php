<?php

namespace Tests\Unit;

use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\CRM\CRMContextService;
use App\Services\CRM\CrmOrchestrationSnapshotService;
use Mockery;
use Tests\TestCase;

class CrmOrchestrationSnapshotServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_builds_a_single_clean_crm_orchestration_snapshot(): void
    {
        $customer = new Customer;
        $customer->forceFill([
            'id' => 12,
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
        ]);

        $conversation = new Conversation;
        $conversation->forceFill([
            'id' => 88,
            'handoff_mode' => 'admin',
            'bot_paused' => true,
            'needs_human' => false,
        ]);

        $booking = new BookingRequest;
        $booking->forceFill([
            'id' => 44,
        ]);

        $crmContextService = Mockery::mock(CRMContextService::class);
        $crmContextService->shouldReceive('build')
            ->once()
            ->andReturn([
                'customer' => [
                    'customer_id' => 12,
                    'name' => 'Nerry',
                ],
                'hubspot' => [
                    'lifecycle_stage' => 'lead',
                ],
                'lead_pipeline' => [
                    'stage' => 'engaged',
                ],
                'conversation' => [
                    'status' => 'active',
                    'current_intent' => 'booking',
                ],
                'booking' => [
                    'booking_status' => 'draft',
                    'missing_fields' => ['pickup_location'],
                ],
                'escalation' => [
                    'has_open_escalation' => true,
                ],
                'business_flags' => [],
            ]);

        $service = new CrmOrchestrationSnapshotService($crmContextService);

        $snapshot = $service->build(
            customer: $customer,
            conversation: $conversation,
            booking: $booking,
            contextPayload: [
                'conversation_state' => [
                    'booking_expected_input' => 'pickup_location',
                ],
                'resolved_context' => [
                    'current_topic' => 'booking_follow_up',
                ],
                'known_entities' => [
                    'destination' => 'Pekanbaru',
                ],
                'customer_memory' => [
                    'relationship_memory' => [
                        'is_returning_customer' => true,
                    ],
                ],
                'conversation_summary' => 'Customer is continuing previous booking.',
                'latest_message_text' => 'Saya mau lanjut booking.',
                'admin_takeover' => true,
            ],
            intentResult: [
                'intent' => 'booking',
                'confidence' => 0.82,
                'reasoning_short' => 'Booking continuation detected.',
            ],
            entityResult: [
                'destination' => 'Pekanbaru',
            ],
            bookingDecision: [
                'action' => 'ask_missing_data',
                'booking_status' => 'draft',
            ],
        );

        $this->assertSame(1, $snapshot['snapshot_version']);
        $this->assertNotEmpty($snapshot['generated_at']);
        $this->assertSame('lead', $snapshot['hubspot']['lifecycle_stage']);
        $this->assertSame('engaged', $snapshot['lead_pipeline']['stage']);
        $this->assertTrue($snapshot['conversation']['admin_takeover']);
        $this->assertTrue($snapshot['conversation']['bot_paused']);
        $this->assertSame('ask_missing_data', $snapshot['booking']['decision']['action']);
        $this->assertTrue($snapshot['business_flags']['admin_takeover_active']);
        $this->assertTrue($snapshot['business_flags']['needs_human_followup']);
        $this->assertSame('Saya mau lanjut booking.', $snapshot['runtime']['latest_message_text']);
        $this->assertSame('booking', $snapshot['ai_decision']['intent']);
        $this->assertSame(0.82, $snapshot['ai_decision']['confidence']);
        $this->assertArrayNotHasKey('clarification_question', $snapshot['ai_decision']);
    }
}
