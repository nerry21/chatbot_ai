<?php

namespace App\Models;

use App\Enums\LearningFailureType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ChatbotLearningSignal extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'inbound_message_id',
        'outbound_message_id',
        'user_message',
        'context_summary',
        'context_snapshot',
        'understanding_result',
        'chosen_action',
        'grounded_facts',
        'final_response',
        'final_response_meta',
        'resolution_status',
        'fallback_used',
        'handoff_happened',
        'admin_takeover_active',
        'outbound_sent',
        'failure_type',
        'failure_signals',
        'corrected_by_admin',
        'corrected_at',
    ];

    protected $casts = [
        'context_snapshot' => 'array',
        'understanding_result' => 'array',
        'grounded_facts' => 'array',
        'final_response_meta' => 'array',
        'failure_signals' => 'array',
        'fallback_used' => 'boolean',
        'handoff_happened' => 'boolean',
        'admin_takeover_active' => 'boolean',
        'outbound_sent' => 'boolean',
        'corrected_by_admin' => 'boolean',
        'corrected_at' => 'datetime',
        'failure_type' => LearningFailureType::class,
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function inboundMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'inbound_message_id');
    }

    public function outboundMessage(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'outbound_message_id');
    }

    public function adminCorrections(): HasMany
    {
        return $this->hasMany(ChatbotAdminCorrection::class, 'learning_signal_id');
    }
}
