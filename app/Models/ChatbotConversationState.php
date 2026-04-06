<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * ChatbotConversationState
 *
 * Stores the rule-based chatbot flow state for a customer phone number.
 * This is separate from ConversationState (which is keyed to a Conversation model
 * and used by the LLM pipeline).  This table is used by TravelMessageRouterService
 * and TravelConversationStateService for the simplified Travel booking flow.
 *
 * @property int         $id
 * @property string      $channel
 * @property string      $customer_phone
 * @property string|null $customer_name
 * @property string      $status
 * @property string|null $current_step
 * @property array       $booking_data
 * @property array       $schedule_change_data
 * @property array       $meta
 * @property string|null $last_intent
 * @property string|null $last_admin_notification_key
 * @property \Carbon\Carbon|null $last_customer_message_at
 * @property \Carbon\Carbon|null $last_bot_message_at
 * @property \Carbon\Carbon|null $first_follow_up_sent_at
 * @property \Carbon\Carbon|null $second_follow_up_sent_at
 * @property \Carbon\Carbon|null $last_completed_booking_at
 * @property \Carbon\Carbon|null $departure_datetime
 * @property bool        $is_waiting_customer_reply
 * @property bool        $is_cancelled
 * @property bool        $is_active
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ChatbotConversationState extends Model
{
    protected $table = 'chatbot_conversation_states';

    protected $fillable = [
        'channel',
        'customer_phone',
        'customer_name',
        'status',
        'current_step',
        'booking_data',
        'schedule_change_data',
        'meta',
        'last_intent',
        'last_admin_notification_key',
        'last_customer_message_at',
        'last_bot_message_at',
        'first_follow_up_sent_at',
        'second_follow_up_sent_at',
        'last_completed_booking_at',
        'departure_datetime',
        'is_waiting_customer_reply',
        'is_cancelled',
        'is_active',
    ];

    protected $casts = [
        'booking_data'              => 'array',
        'schedule_change_data'      => 'array',
        'meta'                      => 'array',
        'last_customer_message_at'  => 'datetime',
        'last_bot_message_at'       => 'datetime',
        'first_follow_up_sent_at'   => 'datetime',
        'second_follow_up_sent_at'  => 'datetime',
        'last_completed_booking_at' => 'datetime',
        'departure_datetime'        => 'datetime',
        'is_waiting_customer_reply' => 'boolean',
        'is_cancelled'              => 'boolean',
        'is_active'                 => 'boolean',
    ];

    // ─── Attribute helpers ────────────────────────────────────────────────────
    // Explicit accessors guard against null when the column was never set
    // (e.g. freshly created row before any booking data arrives).

    public function getBookingDataAttribute(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) ($value ?? '[]'), true) ?: [];
    }

    public function getScheduleChangeDataAttribute(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) ($value ?? '[]'), true) ?: [];
    }

    public function getMetaAttribute(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return json_decode((string) ($value ?? '[]'), true) ?: [];
    }

    // ─── Scopes ───────────────────────────────────────────────────────────────

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_cancelled', false);
    }

    public function scopePendingFollowUp(Builder $query): Builder
    {
        return $query
            ->where('is_active', true)
            ->where('is_cancelled', false)
            ->where('is_waiting_customer_reply', true);
    }

    public function scopeForPhone(Builder $query, string $phone, string $channel = 'whatsapp'): Builder
    {
        return $query->where('channel', $channel)->where('customer_phone', $phone);
    }
}
