<?php

namespace App\Services\Chatbot;

use App\Enums\ConversationChannel;
use App\Enums\MessageDirection;
use App\Enums\SenderType;
use App\Jobs\SendWhatsAppMessageJob;
use App\Models\ConversationMessage;

class ConversationOutboundRouterService
{
    public function dispatch(ConversationMessage $message, string $traceId = ''): void
    {
        $message->loadMissing('conversation.customer');
        $conversation = $message->conversation;

        $direction = is_string($message->direction) ? $message->direction : $message->direction?->value;
        $senderType = is_string($message->sender_type) ? $message->sender_type : $message->sender_type?->value;

        if ($direction !== MessageDirection::Outbound->value) {
            return;
        }

        if (! in_array($senderType, [
            SenderType::Bot->value,
            SenderType::Admin->value,
            SenderType::Agent->value,
        ], true)) {
            return;
        }

        if ($conversation === null) {
            $message->markSkipped('missing_conversation', [
                'channel_delivery' => [
                    'transport' => 'unresolved',
                ],
            ]);

            return;
        }

        match (true) {
            $conversation->isWhatsApp() => SendWhatsAppMessageJob::dispatch($message->id, $traceId),
            $conversation->isMobileLiveChat() => $this->markAsSentForMobilePolling($message),
            default => $message->markSkipped('unsupported_channel:'.$conversation->channel, [
                'channel_delivery' => [
                    'channel' => $conversation->channel,
                    'transport' => 'unsupported',
                ],
            ]),
        };
    }

    public function channelLabel(string $channel): string
    {
        return ConversationChannel::tryFrom($channel)?->label() ?? ucfirst(str_replace('_', ' ', $channel));
    }

    private function markAsSentForMobilePolling(ConversationMessage $message): void
    {
        $message->markSent(null, [
            'channel_delivery' => [
                'channel' => ConversationChannel::MobileLiveChat->value,
                'transport' => 'http_polling',
                'dispatched_at' => now()->toIso8601String(),
            ],
        ]);

        $message->forceFill([
            'channel_message_id' => $message->channel_message_id ?: 'mobile-msg-'.$message->id,
        ])->save();
    }
}
