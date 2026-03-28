<?php

namespace App\Models;

use App\Enums\LearningFailureType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotAdminCorrection extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'learning_signal_id',
        'inbound_message_id',
        'bot_message_id',
        'admin_message_id',
        'admin_id',
        'failure_type',
        'reason',
        'customer_message_text',
        'bot_response_text',
        'admin_correction_text',
        'correction_payload',
    ];

    protected $casts = [
        'correction_payload' => 'array',
        'failure_type' => LearningFailureType::class,
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function learningSignal(): BelongsTo
    {
        return $this->belongsTo(ChatbotLearningSignal::class, 'learning_signal_id');
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'inbound_message_id');
    }

    public function botMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'bot_message_id');
    }

    public function adminMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'admin_message_id');
    }
}
