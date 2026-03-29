<?php

namespace App\Enums;

enum ConversationChannel: string
{
    case WhatsApp = 'whatsapp';
    case MobileLiveChat = 'mobile_live_chat';

    public function label(): string
    {
        return match ($this) {
            self::WhatsApp => 'WhatsApp',
            self::MobileLiveChat => 'Mobile Live Chat',
        };
    }
}
