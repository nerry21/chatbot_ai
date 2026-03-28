<?php

namespace App\Services\AI\Evaluation;

use App\Models\Conversation;
use App\Services\Chatbot\ConversationStateService;
use Carbon\Carbon;

class InMemoryConversationStateService extends ConversationStateService
{
    /**
     * @var array<int, array<string, mixed>>
     */
    private array $storage = [];

    public function get(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        return $this->storage[$this->conversationKey($conversation)][$key] ?? $default;
    }

    public function put(
        Conversation $conversation,
        string $key,
        mixed $value,
        ?Carbon $expiresAt = null,
    ): void {
        $this->storage[$this->conversationKey($conversation)][$key] = $value;
    }

    public function forget(Conversation $conversation, string $key): void
    {
        unset($this->storage[$this->conversationKey($conversation)][$key]);
    }

    public function has(Conversation $conversation, string $key): bool
    {
        return array_key_exists($key, $this->storage[$this->conversationKey($conversation)] ?? []);
    }

    /**
     * @return array<string, mixed>
     */
    public function allActive(Conversation $conversation): array
    {
        return $this->storage[$this->conversationKey($conversation)] ?? [];
    }

    private function conversationKey(Conversation $conversation): int
    {
        return spl_object_id($conversation);
    }
}
