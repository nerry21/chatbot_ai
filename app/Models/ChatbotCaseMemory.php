<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotCaseMemory extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'learning_signal_id',
        'admin_correction_id',
        'source_type',
        'intent',
        'sub_intent',
        'user_message',
        'context_summary',
        'successful_response',
        'example_payload',
        'tags',
        'is_active',
        'usage_count',
        'last_used_at',
    ];

    protected $casts = [
        'example_payload' => 'array',
        'tags' => 'array',
        'is_active' => 'boolean',
        'usage_count' => 'integer',
        'last_used_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function learningSignal(): BelongsTo
    {
        return $this->belongsTo(ChatbotLearningSignal::class, 'learning_signal_id');
    }

    public function adminCorrection(): BelongsTo
    {
        return $this->belongsTo(ChatbotAdminCorrection::class, 'admin_correction_id');
    }
}
