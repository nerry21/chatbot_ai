<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Models\LeadPipeline;
use App\Services\CRM\ContactTaggingService;
use App\Services\CRM\CrmSyncService;
use App\Services\CRM\CRMWritebackService;
use App\Services\CRM\LeadPipelineService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class CRMWritebackServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_syncs_crm_writeback_and_dispatches_escalation_when_forced(): void
    {
        Queue::fake();

        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ])->load('customer');

        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'booking_status' => 'draft',
        ]);

        $lead = new LeadPipeline;
        $lead->id = 55;
        $lead->stage = 'engaged';

        $contactTagging = Mockery::mock(ContactTaggingService::class);
        $contactTagging->shouldReceive('applyBasicTags')
            ->once()
            ->withArgs(function (Customer $argCustomer, ?BookingRequest $argBooking, ?string $argIntent) use ($customer, $booking): bool {
                return $argCustomer->is($customer)
                    && $argBooking?->is($booking)
                    && $argIntent === 'human_handoff';
            })
            ->andReturn(['human_handoff']);

        $leadPipeline = Mockery::mock(LeadPipelineService::class);
        $leadPipeline->shouldReceive('syncFromContext')
            ->once()
            ->withArgs(function (Customer $argCustomer, ?Conversation $argConversation, ?BookingRequest $argBooking, ?string $argIntent) use ($customer, $conversation, $booking): bool {
                return $argCustomer->is($customer)
                    && $argConversation?->is($conversation)
                    && $argBooking?->is($booking)
                    && $argIntent === 'human_handoff';
            })
            ->andReturn($lead);

        $crmSync = Mockery::mock(CrmSyncService::class);
        $crmSync->shouldReceive('syncCustomer')
            ->once()
            ->withArgs(fn (Customer $argCustomer): bool => $argCustomer->is($customer))
            ->andReturn(['status' => 'synced', 'external_id' => 'hs-123']);
        $crmSync->shouldReceive('syncConversationSummary')
            ->once()
            ->withArgs(function (Customer $argCustomer, Conversation $argConversation) use ($customer, $conversation): bool {
                return $argCustomer->is($customer) && $argConversation->is($conversation);
            })
            ->andReturn(['status' => 'success']);

        $service = new CRMWritebackService($contactTagging, $leadPipeline, $crmSync);

        $result = $service->syncDecision(
            conversation: $conversation,
            booking: $booking,
            intentResult: [
                'intent' => 'human_handoff',
                'reasoning_short' => 'Perlu admin.',
            ],
            summaryResult: [
                'summary' => 'Customer meminta admin.',
            ],
            finalReply: [
                'meta' => [
                    'force_handoff' => true,
                ],
            ],
            contextSnapshot: [
                'crm_context' => [
                    'customer' => [
                        'customer_id' => $customer->id,
                    ],
                ],
            ],
        );

        $this->assertSame('ok', $result['status']);
        $this->assertSame(['human_handoff'], $result['tags']);
        $this->assertSame('engaged', $result['lead_stage']);
        $this->assertSame(55, $result['lead_id']);
        $this->assertSame('synced', $result['contact_sync']['status']);
        $this->assertSame('success', $result['summary_sync']['status']);
        $this->assertTrue($result['needs_escalation']);
        $this->assertTrue($result['context_snapshot']['crm_context_present']);

        Queue::assertPushed(EscalateConversationToAdminJob::class, 1);
    }
}
