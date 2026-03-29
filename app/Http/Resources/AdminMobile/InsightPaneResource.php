<?php

namespace App\Http\Resources\AdminMobile;

use App\Enums\ConversationStatus;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

/**
 * @mixin array{
 *     conversation: \App\Models\Conversation,
 *     conversation_tags: \Illuminate\Support\Collection,
 *     customer_tags: \Illuminate\Support\Collection,
 *     internal_notes: \Illuminate\Support\Collection,
 *     audit_trail: \Illuminate\Support\Collection,
 *     booking_summary: \Illuminate\Support\Collection,
 *     state_summary: \Illuminate\Support\Collection,
 *     latest_escalation: mixed,
 *     last_inbound: mixed,
 *     last_outbound: mixed
 * }
 */
class InsightPaneResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $conversation = $this['conversation'];
        $latestEscalation = $this['latest_escalation'];
        $lastInbound = $this['last_inbound'];
        $lastOutbound = $this['last_outbound'];
        $status = is_string($conversation->status) ? $conversation->status : $conversation->status?->value;

        return [
            'customer_profile' => $conversation->customer !== null
                ? new CustomerProfileResource($conversation->customer)
                : null,
            'conversation_tags' => collect($this['conversation_tags'] ?? [])
                ->map(fn ($tag): array => [
                    'id' => $tag->id,
                    'tag' => $tag->tag,
                    'created_by' => $tag->created_by,
                    'created_at' => $tag->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'customer_tags' => collect($this['customer_tags'] ?? [])
                ->map(fn ($tag): array => [
                    'id' => $tag->id,
                    'tag' => $tag->tag,
                    'created_at' => $tag->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'quick_details' => [
                'channel' => [
                    'value' => $conversation->channel,
                    'label' => $conversation->channel_label,
                ],
                'source_app' => $conversation->source_app,
                'source_label' => $conversation->source_label,
                'assigned_admin' => $conversation->assignedAdmin?->name,
                'conversation_mode' => [
                    'value' => $conversation->currentOperationalMode(),
                    'label' => $conversation->currentOperationalModeLabel(),
                    'palette' => $conversation->currentOperationalModePalette(),
                ],
                'status' => [
                    'value' => $status,
                    'label' => ConversationStatus::tryFrom((string) $status)?->label() ?? Str::headline((string) $status),
                ],
                'urgency' => $conversation->is_urgent ? 'urgent' : 'normal',
                'bot_paused' => (bool) $conversation->bot_paused,
                'bot_paused_reason' => $conversation->bot_paused_reason,
                'needs_human' => (bool) $conversation->needs_human,
                'last_inbound_at' => $lastInbound?->sent_at?->toIso8601String(),
                'last_outbound_at' => $lastOutbound?->sent_at?->toIso8601String(),
                'last_admin_intervention_at' => $conversation->last_admin_intervention_at?->toIso8601String(),
                'last_read_at_customer' => $conversation->last_read_at_customer?->toIso8601String(),
                'last_read_at_admin' => $conversation->last_read_at_admin?->toIso8601String(),
                'latest_escalation_status' => $latestEscalation?->status,
            ],
            'booking_summary' => collect($this['booking_summary'] ?? [])
                ->map(fn (array $slot): array => [
                    'label' => $slot['label'],
                    'value' => $slot['value'],
                    'palette' => $slot['palette'] ?? null,
                ])
                ->values()
                ->all(),
            'internal_notes' => collect($this['internal_notes'] ?? [])
                ->map(fn ($note): array => [
                    'id' => $note->id,
                    'target' => class_basename((string) $note->noteable_type) === 'Customer'
                        ? 'customer'
                        : 'conversation',
                    'body' => $note->body,
                    'is_pinned' => (bool) $note->is_pinned,
                    'author' => $note->author !== null
                        ? [
                            'id' => $note->author->id,
                            'name' => $note->author->name,
                        ]
                        : null,
                    'created_at' => $note->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'audit_trail' => collect($this['audit_trail'] ?? [])
                ->map(fn ($entry): array => [
                    'id' => $entry->id,
                    'action_type' => $entry->action_type,
                    'message' => $entry->message,
                    'reason' => data_get($entry->context, 'reason'),
                    'tag' => data_get($entry->context, 'tag'),
                    'actor' => $entry->actor !== null
                        ? [
                            'id' => $entry->actor->id,
                            'name' => $entry->actor->name,
                        ]
                        : null,
                    'context' => $entry->context,
                    'created_at' => $entry->created_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'state_summary' => collect($this['state_summary'] ?? [])
                ->values()
                ->all(),
            'latest_escalation' => $latestEscalation !== null
                ? [
                    'id' => $latestEscalation->id,
                    'status' => $latestEscalation->status,
                    'priority' => $latestEscalation->priority,
                    'reason' => $latestEscalation->reason,
                    'summary' => $latestEscalation->summary,
                    'assigned_admin_id' => $latestEscalation->assigned_admin_id,
                    'resolved_at' => $latestEscalation->resolved_at?->toIso8601String(),
                    'created_at' => $latestEscalation->created_at?->toIso8601String(),
                ]
                : null,
        ];
    }
}
