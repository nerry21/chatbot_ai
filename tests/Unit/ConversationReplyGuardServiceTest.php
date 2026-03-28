<?php

namespace Tests\Unit;

use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Services\Chatbot\ConversationReplyGuardService;
use App\Services\Chatbot\ConversationStateService;
use App\Services\Chatbot\Guardrails\ReplyLoopGuardService;
use App\Services\Chatbot\Guardrails\UnavailableReplyGuardService;
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
        $conversation = new Conversation();

        $latestOutbound = new ConversationMessage([
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Baik, kalau ingin saya cek lagi, silakan kirim rute baru.',
        ]);

        $reply = [
            'text' => 'baik kalau ingin saya cek lagi silakan kirim rute baru',
            'is_fallback' => false,
            'message_type' => 'text',
            'meta' => [
                'source' => 'guard.unavailable_followup',
                'action' => 'request_new_booking_data',
            ],
        ];

        $this->assertTrue($service->shouldSkipRepeat(
            $conversation,
            $latestOutbound,
            $reply,
            $service->buildReplyIdentity($conversation, $reply),
        ));
    }

    public function test_it_rewrites_repeated_booking_prompt_in_same_state_to_short_reminder(): void
    {
        $bootstrapService = $this->makeServiceWithState(null);
        $conversation = new Conversation();
        $repeatReply = [
            'text' => 'Izin Bapak/Ibu, untuk keberangkatan ini ada berapa orang penumpangnya?',
            'is_fallback' => false,
            'message_type' => 'interactive',
            'outbound_payload' => ['interactive' => ['type' => 'list']],
            'meta' => [
                'source' => 'booking_engine',
                'action' => 'collect_passenger_count',
            ],
        ];
        $recentIdentity = $bootstrapService->buildReplyIdentity($conversation, $repeatReply);

        $service = $this->makeServiceWithState(
            unavailableState: null,
            recentReplyIdentity: $recentIdentity,
        );

        $result = $service->guardReply(
            conversation: $conversation,
            messageText: 'oke',
            entityResult: [],
            reply: $repeatReply,
        );

        $this->assertTrue($result['state_repeat_rewritten']);
        $this->assertSame('guard.state_repeat', $result['reply']['meta']['source']);
        $this->assertSame('short_pending_reminder', $result['reply']['meta']['action']);
        $this->assertStringContainsString('jumlah penumpangnya', mb_strtolower($result['reply']['text'], 'UTF-8'));
        $this->assertStringNotContainsString('untuk keberangkatan ini ada berapa orang penumpangnya', mb_strtolower($result['reply']['text'], 'UTF-8'));
    }

    public function test_it_does_not_skip_repeat_when_inbound_context_has_changed(): void
    {
        $service = $this->makeServiceWithState(null);
        $conversation = new Conversation();
        $reply = [
            'text' => 'Baik, saya bantu cek lagi ya.',
            'is_fallback' => false,
            'message_type' => 'text',
            'meta' => [
                'source' => 'ai_reply',
                'action' => 'pass_through',
            ],
        ];

        $previousInboundFingerprint = $service->buildInboundContextFingerprint(
            messageText: 'besok jam 10 ada?',
            intentResult: ['intent' => 'tanya_jam'],
            entityResult: ['destination' => 'Pekanbaru'],
            resolvedContext: ['last_destination' => 'Pekanbaru'],
        );
        $candidateInboundFingerprint = $service->buildInboundContextFingerprint(
            messageText: 'besok jam 10 ada?',
            intentResult: ['intent' => 'tanya_jam'],
            entityResult: ['destination' => 'Bangkinang'],
            resolvedContext: ['last_destination' => 'Bangkinang'],
        );

        $latestIdentity = $service->buildReplyIdentity($conversation, $reply, $previousInboundFingerprint);
        $latestOutbound = new ConversationMessage([
            'direction' => MessageDirection::Outbound,
            'sender_type' => SenderType::Bot,
            'message_type' => 'text',
            'message_text' => 'Baik, saya bantu cek lagi ya.',
            'raw_payload' => $latestIdentity,
        ]);
        $candidateIdentity = $service->buildReplyIdentity($conversation, $reply, $candidateInboundFingerprint);

        $this->assertFalse($service->shouldSkipRepeat(
            $conversation,
            $latestOutbound,
            $reply,
            $candidateIdentity,
            $candidateInboundFingerprint,
        ));
    }

    private function makeServiceWithState(
        ?array $unavailableState,
        ?array $recentReplyIdentity = null,
        string $bookingState = 'asking_passenger_count',
        ?string $expectedInput = 'passenger_count',
    ): ConversationReplyGuardService
    {
        $stateService = $this->createMock(ConversationStateService::class);
        $stateService->method('get')->willReturnCallback(
            function (Conversation $conversation, string $key, mixed $default = null) use ($unavailableState, $recentReplyIdentity, $bookingState, $expectedInput) {
                return match ($key) {
                    'route_unavailable_context' => $unavailableState,
                    'recent_bot_reply_identity' => $recentReplyIdentity,
                    'booking_intent_status' => $bookingState,
                    'booking_expected_input' => $expectedInput,
                    default => $default,
                };
            }
        );

        return new ConversationReplyGuardService(
            new UnavailableReplyGuardService($stateService),
            new ReplyLoopGuardService($stateService),
        );
    }
}
