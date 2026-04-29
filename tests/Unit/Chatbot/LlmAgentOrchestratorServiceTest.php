<?php

namespace Tests\Unit\Chatbot;

use App\Enums\ConversationChannel;
use App\Enums\ConversationStatus;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Models\Conversation;
use App\Models\ConversationMessage;
use App\Models\Customer;
use App\Services\AI\LlmClientService;
use App\Services\Chatbot\LlmAgentOrchestratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LlmAgentOrchestratorServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $customer = Customer::create([
            'name' => 'Nerry',
            'phone_e164' => '+6281234567890',
            'status' => 'active',
        ]);

        $conversation = Conversation::create([
            'customer_id' => $customer->id,
            'channel' => ConversationChannel::WhatsApp,
            'status' => ConversationStatus::Active,
            'started_at' => now(),
        ]);

        $message = ConversationMessage::create([
            'conversation_id' => $conversation->id,
            'direction' => MessageDirection::Inbound,
            'sender_type' => SenderType::Customer,
            'message_type' => 'text',
            'message_text' => 'Tarif Pasir Pengaraian ke Pekanbaru berapa?',
        ]);

        return [$customer, $conversation, $message];
    }

    private function bindLlmStub(array $responses): void
    {
        $stub = new class($responses) extends LlmClientService {
            private array $queue;

            public function __construct(array $responses)
            {
                $this->queue = $responses;
            }

            public function callWithTools(array $messages, array $tools, ?string $model = null): array
            {
                $next = array_shift($this->queue);
                if ($next === null) {
                    return [
                        'finish_reason' => 'stop',
                        'content' => 'fallback',
                        'tool_calls' => [],
                        'usage' => [],
                        'raw_message' => ['role' => 'assistant', 'content' => 'fallback'],
                    ];
                }
                return $next;
            }
        };

        $this->app->instance(LlmClientService::class, $stub);
    }

    public function test_returns_reply_when_llm_responds_with_text(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();

        $this->bindLlmStub([
            [
                'finish_reason' => 'stop',
                'content' => 'Halo kak',
                'tool_calls' => [],
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 3, 'total_tokens' => 13],
                'raw_message' => ['role' => 'assistant', 'content' => 'Halo kak'],
            ],
        ]);

        $result = app(LlmAgentOrchestratorService::class)->handle($message, $conversation, $customer);

        $this->assertSame('Halo kak', $result['reply_text']);
        $this->assertSame(1, $result['iterations']);
        $this->assertFalse($result['should_handoff']);
        $this->assertSame([], $result['tools_called']);
    }

    public function test_executes_tool_when_llm_calls_function(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();

        $this->bindLlmStub([
            [
                'finish_reason' => 'tool_calls',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_1',
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_fare_for_route',
                        'arguments' => json_encode(['pickup' => 'Pasir Pengaraian', 'dropoff' => 'Pekanbaru']),
                    ],
                ]],
                'usage' => [],
                'raw_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_1',
                        'type' => 'function',
                        'function' => [
                            'name' => 'get_fare_for_route',
                            'arguments' => json_encode(['pickup' => 'Pasir Pengaraian', 'dropoff' => 'Pekanbaru']),
                        ],
                    ]],
                ],
            ],
            [
                'finish_reason' => 'stop',
                'content' => 'Tarifnya Rp 150.000',
                'tool_calls' => [],
                'usage' => [],
                'raw_message' => ['role' => 'assistant', 'content' => 'Tarifnya Rp 150.000'],
            ],
        ]);

        $result = app(LlmAgentOrchestratorService::class)->handle($message, $conversation, $customer);

        $this->assertSame('Tarifnya Rp 150.000', $result['reply_text']);
        $this->assertContains('get_fare_for_route', $result['tools_called']);
        $this->assertSame(2, $result['iterations']);
        $this->assertFalse($result['should_handoff']);
    }

    public function test_handoff_signal_when_escalate_tool_called(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();

        $this->bindLlmStub([
            [
                'finish_reason' => 'tool_calls',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_x',
                    'type' => 'function',
                    'function' => [
                        'name' => 'escalate_to_admin',
                        'arguments' => json_encode(['reason' => 'refund']),
                    ],
                ]],
                'usage' => [],
                'raw_message' => [
                    'role' => 'assistant',
                    'content' => null,
                    'tool_calls' => [[
                        'id' => 'call_x',
                        'type' => 'function',
                        'function' => [
                            'name' => 'escalate_to_admin',
                            'arguments' => json_encode(['reason' => 'refund']),
                        ],
                    ]],
                ],
            ],
            [
                'finish_reason' => 'stop',
                'content' => 'Saya teruskan ke admin ya',
                'tool_calls' => [],
                'usage' => [],
                'raw_message' => ['role' => 'assistant', 'content' => 'Saya teruskan ke admin ya'],
            ],
        ]);

        $result = app(LlmAgentOrchestratorService::class)->handle($message, $conversation, $customer);

        $this->assertTrue($result['should_handoff']);
        $this->assertContains('escalate_to_admin', $result['tools_called']);
    }

    public function test_max_iterations_safety_returns_handoff(): void
    {
        [$customer, $conversation, $message] = $this->makeFixture();

        config(['chatbot.agent.max_iterations' => 5]);

        $loopResponse = [
            'finish_reason' => 'tool_calls',
            'content' => null,
            'tool_calls' => [[
                'id' => 'call_loop',
                'type' => 'function',
                'function' => [
                    'name' => 'get_route_info',
                    'arguments' => json_encode(['query_text' => 'Pasir Pengaraian']),
                ],
            ]],
            'usage' => [],
            'raw_message' => [
                'role' => 'assistant',
                'content' => null,
                'tool_calls' => [[
                    'id' => 'call_loop',
                    'type' => 'function',
                    'function' => [
                        'name' => 'get_route_info',
                        'arguments' => json_encode(['query_text' => 'Pasir Pengaraian']),
                    ],
                ]],
            ],
        ];

        $this->bindLlmStub(array_fill(0, 6, $loopResponse));

        $result = app(LlmAgentOrchestratorService::class)->handle($message, $conversation, $customer);

        $this->assertSame(5, $result['iterations']);
        $this->assertTrue($result['should_handoff']);
        $this->assertStringContainsString('teruskan', $result['reply_text']);
    }
}
