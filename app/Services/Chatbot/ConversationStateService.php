<?php

namespace App\Services\Chatbot;

use App\Models\Conversation;
use Carbon\Carbon;

class ConversationStateService
{
    /**
     * Retrieve a state value for a conversation key.
     * Returns $default if the key does not exist or is expired.
     */
    public function get(Conversation $conversation, string $key, mixed $default = null): mixed
    {
        $state = $conversation->states()
            ->active()
            ->byKey($key)
            ->latest('updated_at')
            ->first();

        return $state !== null ? $state->state_value : $default;
    }

    /**
     * Persist a state value. Creates or updates the record for the given key.
     *
     * @param  mixed  $value  Any JSON-serializable value.
     */
    public function put(
        Conversation $conversation,
        string $key,
        mixed $value,
        ?Carbon $expiresAt = null,
    ): void {
        $conversation->states()->updateOrCreate(
            ['state_key' => $key],
            [
                'state_value' => $value,
                'expires_at'  => $expiresAt,
            ],
        );
    }

    /**
     * Delete a state key for a conversation.
     * Silently does nothing if the key does not exist.
     */
    public function forget(Conversation $conversation, string $key): void
    {
        $conversation->states()
            ->byKey($key)
            ->delete();
    }

    /**
     * Check whether a non-expired state key exists.
     */
    public function has(Conversation $conversation, string $key): bool
    {
        return $conversation->states()
            ->active()
            ->byKey($key)
            ->exists();
    }

    /**
     * Return all non-expired states as an associative array keyed by state_key.
     *
     * @return array<string, mixed>
     */
    public function allActive(Conversation $conversation): array
    {
        return $conversation->states()
            ->active()
            ->get(['state_key', 'state_value'])
            ->mapWithKeys(fn ($state) => [$state->state_key => $state->state_value])
            ->all();
    }
}
