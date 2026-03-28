<?php

namespace App\Data\AI;

use App\Enums\GroundedResponseMode;

final readonly class GroundedResponseResult
{
    public function __construct(
        public string $text,
        public GroundedResponseMode $mode,
        public bool $isFallback,
    ) {
    }

    /**
     * @return array{text: string, mode: string, is_fallback: bool}
     */
    public function toArray(): array
    {
        return [
            'text' => $this->text,
            'mode' => $this->mode->value,
            'is_fallback' => $this->isFallback,
        ];
    }
}
