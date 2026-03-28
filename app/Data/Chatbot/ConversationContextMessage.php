<?php

namespace App\Data\Chatbot;

final readonly class ConversationContextMessage
{
    public function __construct(
        public string $role,
        public string $direction,
        public string $text,
        public ?string $sentAt,
    ) {
    }

    /**
     * @return array{
     *     role: string,
     *     direction: string,
     *     text: string,
     *     sent_at: string|null
     * }
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role,
            'direction' => $this->direction,
            'text' => $this->text,
            'sent_at' => $this->sentAt,
        ];
    }

    /**
     * @return array{
     *     role: string,
     *     text: string,
     *     sent_at: string|null
     * }
     */
    public function toUnderstandingArray(): array
    {
        return [
            'role' => $this->role,
            'text' => $this->text,
            'sent_at' => $this->sentAt,
        ];
    }

    /**
     * @return array{
     *     direction: string,
     *     text: string,
     *     sent_at: string|null
     * }
     */
    public function toLegacyArray(): array
    {
        return [
            'direction' => $this->direction,
            'text' => $this->text,
            'sent_at' => $this->sentAt,
        ];
    }
}
