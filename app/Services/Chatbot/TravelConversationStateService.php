<?php

namespace App\Services\Chatbot;

use App\Models\ChatbotConversationState;
use Carbon\Carbon;

/**
 * TravelConversationStateService
 *
 * Manages persistence of the TravelMessageRouter flow state in the
 * chatbot_conversation_states table.
 *
 * Responsibilities:
 *  - Find or create the state row for a given phone + channel
 *  - Convert DB row → router input array  (toRouterState)
 *  - Apply router output back to DB row   (applyRouterResult)
 *  - Track incoming / outgoing message timestamps
 *  - Drive the 15-minute abandoned-booking follow-up logic
 *
 * This service is intentionally free of WhatsApp-sending logic.
 * The caller (job / controller) handles the actual message dispatch.
 */
class TravelConversationStateService
{
    private function timezone(): string
    {
        return (string) config('chatbot.jet.timezone', 'Asia/Jakarta');
    }

    // ─── Find / Create ────────────────────────────────────────────────────────

    public function findOrCreate(
        string $customerPhone,
        string $channel = 'whatsapp',
        ?string $customerName = null,
    ): ChatbotConversationState {
        $phone = $this->normalizePhone($customerPhone);

        /** @var ChatbotConversationState $state */
        $state = ChatbotConversationState::firstOrCreate(
            [
                'channel'        => $channel,
                'customer_phone' => $phone,
            ],
            [
                'customer_name'              => $customerName,
                'status'                     => 'idle',
                'current_step'               => null,
                'booking_data'               => [],
                'schedule_change_data'       => [],
                'meta'                       => [
                    'history_visible_from' => now($this->timezone())->toDateTimeString(),
                    'conversation_domain' => 'travel',
                ],
                'is_waiting_customer_reply'  => false,
                'is_cancelled'               => false,
                'is_active'                  => true,
            ],
        );

        // Update name if it arrived for the first time
        if ($customerName !== null && $customerName !== '' && $state->customer_name !== $customerName) {
            $state->customer_name = $customerName;
            $state->save();
        }

        return $state;
    }

    // ─── Router bridge ────────────────────────────────────────────────────────

    /**
     * Convert a DB state row into the array format expected by TravelMessageRouterService.
     *
     * @return array<string, mixed>
     */
    public function toRouterState(ChatbotConversationState $state): array
    {
        return [
            'status'                      => $state->status ?: 'idle',
            'current_step'                => $state->current_step,
            'booking_data'                => $state->booking_data ?: [],
            'paket_data'                  => ($state->meta ?? [])['paket_data'] ?? [],
            'schedule_change_data'        => $state->schedule_change_data ?: [],
            'last_admin_notification_key' => $state->last_admin_notification_key,
            'last_completed_booking_at'   => $state->last_completed_booking_at?->toDateTimeString(),
            'departure_datetime'          => $state->departure_datetime?->toDateTimeString(),
            'first_follow_up_sent_at'     => $state->first_follow_up_sent_at?->toDateTimeString(),
            'second_follow_up_sent_at'    => $state->second_follow_up_sent_at?->toDateTimeString(),
            'meta'                        => $state->meta ?: [],
        ];
    }

    /**
     * Write the router result back to the DB row and persist.
     */
    public function applyRouterResult(
        ChatbotConversationState $state,
        array $routerResult,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now    ??= now($this->timezone());
        $newState = (array) ($routerResult['new_state'] ?? []);
        $intent   = (string) ($routerResult['intent'] ?? '');

        $state->status                     = (string) ($newState['status'] ?? $state->status ?? 'idle');
        $state->current_step               = $newState['current_step'] ?? null;
        $state->booking_data               = (array) ($newState['booking_data'] ?? $state->booking_data ?? []);
        $state->schedule_change_data       = (array) ($newState['schedule_change_data'] ?? $state->schedule_change_data ?? []);
        $state->last_admin_notification_key = $newState['last_admin_notification_key'] ?? $state->last_admin_notification_key;
        $state->last_intent                = $intent !== '' ? $intent : $state->last_intent;
        $state->last_bot_message_at        = $now;

        // Store paket_data inside meta (no DB migration needed)
        $existingMeta = $state->meta ?? [];
        if (isset($newState['paket_data']) && is_array($newState['paket_data'])) {
            $existingMeta['paket_data'] = $newState['paket_data'];
        }

        // Merge meta: existing + new_state.meta + result.meta (later keys win)
        $state->meta = array_merge(
            $existingMeta,
            (array) ($newState['meta'] ?? []),
            (array) ($routerResult['meta'] ?? []),
        );

        if (! empty($newState['last_completed_booking_at'])) {
            $state->last_completed_booking_at = Carbon::parse(
                $newState['last_completed_booking_at'],
                $this->timezone(),
            );
        }

        if (! empty($newState['departure_datetime'])) {
            $state->departure_datetime = Carbon::parse(
                $newState['departure_datetime'],
                $this->timezone(),
            );
        }

        $state->is_waiting_customer_reply = $this->shouldWaitForCustomerReply($state);
        $state->is_cancelled              = (bool) ($newState['is_cancelled'] ?? false);

        $state->save();

        return $state->fresh();
    }

    // ─── Message timestamps ───────────────────────────────────────────────────

    public function markIncomingMessage(
        ChatbotConversationState $state,
        ?string $messageText = null,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $meta = $state->meta ?? [];
        $meta['last_incoming_text'] = $messageText;
        $meta['last_incoming_at']   = $now->toDateTimeString();

        $state->last_customer_message_at = $now;
        $state->is_waiting_customer_reply = false;
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    public function markBotSent(
        ChatbotConversationState $state,
        string $replyText,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $meta = $state->meta ?? [];
        $meta['last_bot_text']    = $replyText;
        $meta['last_bot_sent_at'] = $now->toDateTimeString();

        $state->last_bot_message_at       = $now;
        $state->is_waiting_customer_reply = $this->shouldWaitForCustomerReply($state);
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    // ─── Follow-up / abandonment ──────────────────────────────────────────────

    /**
     * Whether it is time to send the first 15-minute follow-up.
     */
    public function shouldSendFirstFollowUp(ChatbotConversationState $state, ?Carbon $now = null): bool
    {
        if (! $state->is_active || $state->is_cancelled) {
            return false;
        }

        if (! $state->is_waiting_customer_reply) {
            return false;
        }

        if ($state->first_follow_up_sent_at !== null) {
            return false;
        }

        $reference = $state->last_customer_message_at ?? $state->last_bot_message_at;

        if ($reference === null) {
            return false;
        }

        $now     ??= now($this->timezone());
        $minutes   = (int) config('chatbot.jet.seat_hold_minutes', 30);

        // Use the configured seat_hold as the first follow-up window.
        // Fallback to 15 minutes when not configured.
        if ($minutes <= 0) {
            $minutes = 15;
        }

        return $reference->diffInMinutes($now) >= $minutes;
    }

    public function markFirstFollowUpSent(
        ChatbotConversationState $state,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $meta = $state->meta ?? [];
        $meta['first_follow_up_sent']    = true;
        $meta['first_follow_up_sent_at'] = $now->toDateTimeString();

        $state->first_follow_up_sent_at = $now;
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    /**
     * Whether it is time to auto-cancel after the second timeout (15 min after first follow-up).
     */
    public function shouldCancelAfterSecondTimeout(ChatbotConversationState $state, ?Carbon $now = null): bool
    {
        if (! $state->is_active || $state->is_cancelled) {
            return false;
        }

        if ($state->first_follow_up_sent_at === null) {
            return false;
        }

        if ($state->second_follow_up_sent_at !== null) {
            return false;
        }

        $now     ??= now($this->timezone());
        $minutes   = 15;

        return $state->first_follow_up_sent_at->diffInMinutes($now) >= $minutes;
    }

    public function markSecondFollowUpAndCancel(
        ChatbotConversationState $state,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $meta = $state->meta ?? [];
        $meta['cancelled_due_to_timeout'] = true;
        $meta['cancelled_at']             = $now->toDateTimeString();

        $state->second_follow_up_sent_at  = $now;
        $state->is_cancelled              = true;
        $state->status                    = 'idle';
        $state->current_step              = null;
        $state->booking_data              = [];
        $state->schedule_change_data      = [];
        $state->is_waiting_customer_reply = false;
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    // ─── Admin notification dedup ─────────────────────────────────────────────

    public function shouldAvoidDuplicateAdminNotification(
        ChatbotConversationState $state,
        string $notificationKey,
    ): bool {
        return $state->last_admin_notification_key !== null
            && $state->last_admin_notification_key === $notificationKey;
    }

    public function markAdminNotified(
        ChatbotConversationState $state,
        string $notificationKey,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $meta = $state->meta ?? [];
        $meta['last_admin_notified_at']        = $now->toDateTimeString();
        $meta['last_admin_notification_key']   = $notificationKey;

        $state->last_admin_notification_key = $notificationKey;
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    // ─── Booking completion ───────────────────────────────────────────────────

    public function markBookingCompleted(
        ChatbotConversationState $state,
        ?string $departureDatetime = null,
        ?Carbon $now = null,
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $state->status                    = 'booking_confirmed';
        $state->current_step              = null;
        $state->last_completed_booking_at = $now;
        $state->is_waiting_customer_reply = false;
        $state->is_cancelled              = false;

        if ($departureDatetime !== null && $departureDatetime !== '') {
            $state->departure_datetime = Carbon::parse($departureDatetime, $this->timezone());
        }

        $state->save();

        return $state->fresh();
    }

    // ─── Conversation reset ───────────────────────────────────────────────────

    public function resetForNewConversation(ChatbotConversationState $state): ChatbotConversationState
    {
        $meta = $state->meta ?? [];
        $meta['reset_for_new_conversation'] = true;
        $meta['reset_reason']               = 'customer_replied_after_timeout_cancellation';
        $meta['reset_at']                   = now($this->timezone())->toDateTimeString();

        $state->status                      = 'idle';
        $state->current_step                = null;
        $state->booking_data                = [];
        $state->schedule_change_data        = [];
        $state->last_admin_notification_key = null;
        $state->first_follow_up_sent_at     = null;
        $state->second_follow_up_sent_at    = null;
        $state->is_waiting_customer_reply   = false;
        $state->is_cancelled                = false;
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    public function shouldStartFreshSession(
        ChatbotConversationState $state,
        ?Carbon $now = null,
        ?string $latestMessage = null,
    ): bool {
        $now ??= now($this->timezone());

        $status = (string) ($state->status ?? 'idle');
        $lastCustomerAt = $state->last_customer_message_at;
        $lastCompletedAt = $state->last_completed_booking_at;

        $latestMessage = trim((string) $latestMessage);
        $normalized = mb_strtolower($latestMessage, 'UTF-8');

        $isShortFreshOpen = in_array($normalized, [
            'assalamualaikum',
            'halo',
            'hallo',
            'hai',
            'hi',
            'hello',
            'pagi',
            'siang',
            'sore',
            'malam',
            'selamat pagi',
            'selamat siang',
            'selamat sore',
            'selamat malam',
            'halo selamat pagi',
            'halo selamat siang',
            'halo selamat sore',
            'halo selamat malam',
            'hallo selamat pagi',
            'hallo selamat siang',
            'hallo selamat sore',
            'hallo selamat malam',
            'hallo selamat pagi.',
            'assalamualaikum selamat pagi',
        ], true);

        // Reset ANY state (including mid-booking) after 2 hours of no activity.
        if ($lastCustomerAt !== null && $lastCustomerAt->diffInHours($now) >= 2) {
            return true;
        }

        // Reset when greeting is sent during active booking/schedule_change.
        if (in_array($status, ['booking', 'schedule_change'], true) && $isShortFreshOpen) {
            return true;
        }

        // Reset completed booking immediately when greeting is sent.
        if ($status === 'booking_confirmed' && $isShortFreshOpen) {
            return true;
        }

        // Reset completed booking after 2 hours.
        if ($lastCompletedAt !== null && $lastCompletedAt->diffInHours($now) >= 2) {
            return true;
        }

        return false;
    }

    public function resetForFreshSession(
        ChatbotConversationState $state,
        ?Carbon $now = null,
        string $reason = 'fresh_session_cutoff',
    ): ChatbotConversationState {
        $now ??= now($this->timezone());

        $meta = $state->meta ?? [];
        $meta['history_visible_from'] = $now->toDateTimeString();
        $meta['fresh_session_started_at'] = $now->toDateTimeString();
        $meta['fresh_session_reason'] = $reason;

        $state->status = 'idle';
        $state->current_step = null;
        $state->booking_data = [];
        $state->schedule_change_data = [];
        $state->last_admin_notification_key = null;
        $state->first_follow_up_sent_at = null;
        $state->second_follow_up_sent_at = null;
        $state->is_waiting_customer_reply = false;
        $state->is_cancelled = false;
        $state->meta = $meta;
        $state->save();

        return $state->fresh();
    }

    // ─── Private ──────────────────────────────────────────────────────────────

    private function shouldWaitForCustomerReply(ChatbotConversationState $state): bool
    {
        if (in_array($state->status, ['booking', 'schedule_change'], true)) {
            return $state->current_step !== null && $state->current_step !== '';
        }

        return false;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/\s+/', '', trim($phone)) ?? trim($phone);

        // Strip leading + but keep the digits (e.g. +6281… → 6281…)
        return ltrim($phone, '+');
    }
}