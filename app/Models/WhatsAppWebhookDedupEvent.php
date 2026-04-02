<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class WhatsAppWebhookDedupEvent extends Model
{
    use HasFactory;

    protected $table = 'whatsapp_webhook_dedup_events';

    protected $fillable = [
        'event_type',
        'dedup_key',
        'wa_call_id',
        'payload_hash',
        'trace_id',
        'received_at',
    ];

    protected $casts = [
        'received_at' => 'datetime',
    ];
}
