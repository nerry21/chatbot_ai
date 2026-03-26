<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiLog extends Model
{
    use HasFactory;

    protected $table = 'ai_logs';

    protected $fillable = [
        'conversation_id',
        'message_id',
        'provider',
        'model',
        'task_type',
        'prompt_snapshot',
        'response_snapshot',
        'parsed_output',
        'latency_ms',
        'token_input',
        'token_output',
        'status',
        'error_message',
        // Tahap 10: quality tracking
        'quality_label',
        'knowledge_hits',
    ];

    protected $casts = [
        'parsed_output'  => 'array',
        'knowledge_hits' => 'array',
        'latency_ms'     => 'integer',
        'token_input'    => 'integer',
        'token_output'   => 'integer',
    ];

    // -------------------------------------------------------------------------
    // Relations
    // -------------------------------------------------------------------------

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(ConversationMessage::class, 'message_id');
    }

    // -------------------------------------------------------------------------
    // Scopes
    // -------------------------------------------------------------------------

    public function scopeSuccessful(
        \Illuminate\Database\Eloquent\Builder $query,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('status', 'success');
    }

    public function scopeFailed(
        \Illuminate\Database\Eloquent\Builder $query,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('status', 'failed');
    }

    public function scopeByTask(
        \Illuminate\Database\Eloquent\Builder $query,
        string $taskType,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('task_type', $taskType);
    }

    public function scopeByProvider(
        \Illuminate\Database\Eloquent\Builder $query,
        string $provider,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('provider', $provider);
    }

    public function scopeForConversation(
        \Illuminate\Database\Eloquent\Builder $query,
        int $conversationId,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('conversation_id', $conversationId);
    }

    // Tahap 10: quality-aware scopes

    public function scopeWithQualityLabel(
        \Illuminate\Database\Eloquent\Builder $query,
        string $label,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('quality_label', $label);
    }

    public function scopeWithKnowledgeHits(
        \Illuminate\Database\Eloquent\Builder $query,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->whereNotNull('knowledge_hits');
    }

    public function scopeLowConfidence(
        \Illuminate\Database\Eloquent\Builder $query,
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->where('quality_label', 'low_confidence');
    }

    // -------------------------------------------------------------------------
    // Static factory helper
    // -------------------------------------------------------------------------

    /**
     * Convenience wrapper for creating log entries without repeating
     * the task_type / status boilerplate in every call site.
     *
     * @param  array<string, mixed>  $attributes  Any fillable AiLog column.
     */
    public static function writeLog(string $taskType, string $status, array $attributes = []): self
    {
        return static::create(array_merge(
            ['task_type' => $taskType, 'status' => $status],
            $attributes,
        ));
    }
}
