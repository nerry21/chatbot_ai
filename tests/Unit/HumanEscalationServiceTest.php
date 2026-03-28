<?php

namespace Tests\Unit;

use App\Enums\ConversationStatus;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Chatbot\AdminHandoffFormatterService;
use App\Services\Chatbot\ConversationStateService;
use App\Services\Chatbot\HumanEscalationService;
use App\Services\WhatsApp\WhatsAppSenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class HumanEscalationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_forwards_a_confirmed_booking_to_admin_only_once_per_booking(): void
    {
        config(['chatbot.jet.admin_phone' => '6281267975175']);

        [$customer, $conversation] = $this->makeConversation();
        $booking = BookingRequest::create([
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'pickup_location' => 'Pasir Pengaraian',
            'pickup_full_address' => 'Jl Sudirman No 1',
            'destination' => 'Pekanbaru',
            'destination_full_address' => 'Jl Tuanku Tambusai No 5',
            'passenger_name' => 'Andi',
            'passenger_names' => ['Andi', 'Budi'],
            'passenger_count' => 2,
            'departure_date' => '2026-03-28',
            'departure_time' => '08:00',
            'selected_seats' => ['CC', 'BS'],
            'contact_number' => '+6281234567890',
            'price_estimate' => 300000,
            'booking_status' => 'confirmed',
        ]);

        $sender = Mockery::mock(WhatsAppSenderService::class);
        $sender->shouldReceive('sendText')
            ->once()
            ->with(
                '6281267975175',
                Mockery::on(fn (string $text): bool => str_contains($text, 'Forward booking baru JET dari WhatsApp AI.')
                    && str_contains($text, 'Status tindak lanjut : Pending admin')
                    && str_contains($text, 'No customer          : +6281234567890')
                    && str_contains($text, 'Alamat tujuan antar  : Jl Tuanku Tambusai No 5')
                    && str_contains($text, 'Total ongkos         : Rp 300.000')),
                Mockery::on(fn (array $meta): bool => ($meta['context'] ?? null) === 'booking_forward'
                    && ($meta['booking_id'] ?? null) === $booking->id),
            )
            ->andReturn([
                'status' => 'sent',
                'provider' => 'whatsapp',
                'response' => null,
                'error' => null,
            ]);

        $service = new HumanEscalationService(
            $sender,
            app(ConversationStateService::class),
            app(AdminHandoffFormatterService::class),
        );

        $service->forwardBooking($conversation, $customer, $booking);
        $service->forwardBooking($conversation->fresh(), $customer->fresh(), $booking->fresh());

        $forwardState = app(ConversationStateService::class)->get($conversation->fresh(), 'admin_booking_forward');
        $adminForwarded = app(ConversationStateService::class)->get($conversation->fresh(), 'admin_forwarded');
        $adminForwardHash = app(ConversationStateService::class)->get($conversation->fresh(), 'admin_forward_hash');

        $this->assertIsArray($forwardState);
        $this->assertSame($booking->id, $forwardState['booking_id'] ?? null);
        $this->assertSame('sent', $forwardState['status'] ?? null);
        $this->assertTrue($adminForwarded);
        $this->assertIsString($adminForwardHash);
        $this->assertNotSame('', $adminForwardHash);
        $this->assertSame($adminForwardHash, $forwardState['admin_forward_hash'] ?? null);
    }

    public function test_it_sends_unanswered_question_escalation_only_once_while_takeover_is_active(): void
    {
        Queue::fake();
        config(['chatbot.jet.admin_phone' => '6281267975175']);

        [$customer, $conversation] = $this->makeConversation();

        $sender = Mockery::mock(WhatsAppSenderService::class);
        $sender->shouldReceive('sendText')
            ->once()
            ->with(
                '6281267975175',
                'Bos, ini ada pertanyaan dari nomor 6281234567890, bisa bantu jawab bos?',
                Mockery::on(fn (array $meta): bool => ($meta['context'] ?? null) === 'question_escalation'),
            )
            ->andReturn([
                'status' => 'sent',
                'provider' => 'whatsapp',
                'response' => null,
                'error' => null,
            ]);

        $service = new HumanEscalationService(
            $sender,
            app(ConversationStateService::class),
            app(AdminHandoffFormatterService::class),
        );

        $service->escalateQuestion($conversation, $customer, 'Pertanyaan customer perlu bantuan admin.');
        $service->escalateQuestion($conversation->fresh(), $customer->fresh(), 'Pertanyaan customer perlu bantuan admin.');

        $this->assertTrue($conversation->fresh()->isAdminTakeover());
        Queue::assertPushed(EscalateConversationToAdminJob::class, 1);
    }

    /**
     * @return array{0: Customer, 1: Conversation}
     */
    private function makeConversation(string $phone = '+6281234567890'): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => $phone,
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => 'whatsapp',
            'status' => ConversationStatus::Active,
            'handoff_mode' => 'bot',
            'started_at' => now(),
            'last_message_at' => now(),
        ]);

        return [$customer, $conversation];
    }
}
