<?php

namespace Tests\Unit;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Chatbot\ConversationReplyGuardService;
use App\Services\Chatbot\ConversationStateService;
use Illuminate\Config\Repository as ConfigRepository;
use Illuminate\Container\Container;
use PHPUnit\Framework\TestCase;

class ConversationReplyGuardServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $container = new Container();
        $container->instance('config', new ConfigRepository([
            'chatbot.guards.close_intents' => [
                'oke',
                'ok',
                'baik',
                'siap',
                'tidak ada',
                'ga ada',
                'nggak ada',
                'tidak',
                'ya sudah',
                'makasih',
                'terima kasih',
            ],
            'chatbot.guards.close_intent_courtesy_tails' => [
                'ya',
                'kak',
                'admin',
            ],
            'chatbot.guards.unavailable_state_key' => 'route_unavailable_context',
            'chatbot.guards.unavailable_state_ttl_hours' => 24,
            'chatbot.guards.unavailable_followup_reply' => 'Baik, kalau ingin saya cek lagi, silakan kirim rute atau detail perjalanan baru yang ingin dicoba.',
            'chatbot.guards.unavailable_close_reply' => 'Baik, terima kasih. Jika nanti Anda ingin cek rute atau jadwal lain, silakan kirim detail barunya ya.',
        ]));

        Container::setInstance($container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);

        parent::tearDown();
    }

    public function test_it_closes_politely_when_close_intent_is_detected_after_unavailable_state(): void
    {
        $service = $this->makeServiceWithState([
            'action' => 'unsupported_route',
            'source' => 'booking_engine',
        ]);

        $result = $service->guardReply(
            conversation: new Conversation(),
            messageText: 'makasih ya',
            entityResult: [],
            reply: [
                'text' => 'Rute belum tersedia.',
                'is_fallback' => false,
                'meta' => [
                    'source' => 'booking_engine',
                    'action' => 'unsupported_route',
                ],
            ],
        );

        $this->assertTrue($result['close_intent_detected']);
        $this->assertTrue($result['close_conversation']);
        $this->assertSame('guard.close_intent', $result['reply']['meta']['source']);
        $this->assertSame('close_after_unavailable', $result['reply']['meta']['action']);
    }

    public function test_it_blocks_repeated_unavailable_reply_when_no_new_booking_data_is_present(): void
    {
        $service = $this->makeServiceWithState([
            'action' => 'unsupported_route',
            'source' => 'booking_engine',
        ]);

        $result = $service->guardReply(
            conversation: new Conversation(),
            messageText: 'gimana ya?',
            entityResult: [],
            reply: [
                'text' => 'Rute belum tersedia.',
                'is_fallback' => false,
                'meta' => [
                    'source' => 'booking_engine',
                    'action' => 'unsupported_route',
                ],
            ],
        );

        $this->assertFalse($result['close_intent_detected']);
        $this->assertTrue($result['unavailable_repeat_blocked']);
        $this->assertSame('guard.unavailable_followup', $result['reply']['meta']['source']);
        $this->assertSame('request_new_booking_data', $result['reply']['meta']['action']);
    }

    public function test_it_allows_unavailable_reply_when_new_relevant_booking_data_exists(): void
    {
        $service = $this->makeServiceWithState([
            'action' => 'unsupported_route',
            'source' => 'booking_engine',
        ]);

        $reply = [
            'text' => 'Rute Medan ke Padang belum tersedia.',
            'is_fallback' => false,
            'meta' => [
                'source' => 'booking_engine',
                'action' => 'unsupported_route',
            ],
        ];

        $result = $service->guardReply(
            conversation: new Conversation(),
            messageText: 'Medan ke Padang',
            entityResult: [
                'pickup_location' => 'Medan',
                'destination' => 'Padang',
            ],
            reply: $reply,
        );

        $this->assertFalse($result['close_intent_detected']);
        $this->assertFalse($result['unavailable_repeat_blocked']);
        $this->assertTrue($result['has_relevant_booking_update']);
        $this->assertSame($reply, $result['reply']);
    }

    public function test_it_skips_repeat_when_latest_outbound_has_same_normalized_text(): void
    {
        $service = $this->makeServiceWithState(null);

        $latestOutbound = new ConversationMessage([
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Baik, kalau ingin saya cek lagi, silakan kirim rute baru.',
        ]);

        $this->assertTrue($service->shouldSkipRepeat(
            $latestOutbound,
            'baik kalau ingin saya cek lagi silakan kirim rute baru',
        ));
    }

    private function makeServiceWithState(?array $state): ConversationReplyGuardService
    {
        $stateService = $this->createMock(ConversationStateService::class);
        $stateService->method('get')->willReturn($state);

        return new ConversationReplyGuardService($stateService);
    }
}
