<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationHandoff extends Model
{
    protected $fillable = [
        'conversation_id',
        'actor_user_id',
        'assigned_admin_id',
        'action',
        'from_mode',
        'to_mode',
        'reason',
        'snapshot',
        'happened_at',
    ];

    protected $casts = [
        'snapshot' => 'array',
        'happened_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function assignedAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_admin_id');
    }
}
