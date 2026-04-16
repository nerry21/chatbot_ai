<?php

namespace App\Services\WhatsApp;

use Carbon\Carbon;

class WhatsAppMessageParser
{
    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function extractCalls(array $payload): array
    {
        $calls = [];
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = $change['value'] ?? [];
                $metadata = is_array($value['metadata'] ?? null) ? $value['metadata'] : [];
                $rawCalls = $this->extractRawCallEvents($field, is_array($value) ? $value : []);

                foreach ($rawCalls as $rawCall) {
                    if (! is_array($rawCall)) {
                        continue;
                    }

                    $calls[] = [
                        'wa_call_id' => $this->extractCallId($rawCall),
                        'event' => $this->extractCallEventName($rawCall),
                        'direction' => $this->extractCallDirection($rawCall),
                        'from' => $this->extractCallParty($rawCall, [
                            'from',
                            'caller',
                            'caller_id',
                            'source',
                            'initiator',
                        ]),
                        'to' => $this->extractCallParty($rawCall, [
                            'to',
                            'callee',
                            'callee_id',
                            'destination',
                            'recipient',
                        ]),
                        'timestamp' => $this->normalizeTimestamp(
                            $rawCall['timestamp']
                                ?? $rawCall['event_time']
                                ?? $rawCall['time']
                                ?? data_get($rawCall, 'call.timestamp')
                        ),
                        'termination_reason' => $this->extractCallTerminationReason($rawCall),
                        'metadata' => $metadata,
                        'raw' => $this->buildCallRawPayload($rawCall, $metadata, $field),
                    ];
                }
            }
        }

        return $calls;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function extractMessages(array $payload): array
    {
        $messages = [];
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            $changes = $entry['changes'] ?? [];

            foreach ($changes as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $rawMessages = $value['messages'] ?? [];
                $contacts = $value['contacts'] ?? [];
                $metadata = $value['metadata'] ?? [];
                $contactMap = $this->indexContactsByWaId($contacts);

                foreach ($rawMessages as $msg) {
                    $waId = $msg['from'] ?? null;
                    $contact = $contactMap[$waId] ?? null;
                    $interactiveReply = $this->extractInteractiveReply($msg);

                    $messages[] = [
                        'wa_message_id' => $msg['id'] ?? null,
                        'from_wa_id' => $waId,
                        'from_name' => $contact['profile']['name'] ?? null,
                        'message_type' => $msg['type'] ?? 'unknown',
                        'message_text' => $this->extractText($msg, $interactiveReply),
                        'timestamp' => isset($msg['timestamp'])
                            ? Carbon::createFromTimestamp((int) $msg['timestamp'])
                            : null,
                        'interactive_reply' => $interactiveReply,
                        'raw_message' => $msg,
                        'raw_payload' => $this->buildRawPayload($msg, $metadata, $interactiveReply),
                        'metadata' => $metadata,
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, array<string, mixed>>
     */
    public function extractStatuses(array $payload): array
    {
        $statuses = [];
        $entries = $payload['entry'] ?? [];

        foreach ($entries as $entry) {
            foreach (($entry['changes'] ?? []) as $change) {
                if (($change['field'] ?? '') !== 'messages') {
                    continue;
                }

                $value = $change['value'] ?? [];
                $metadata = $value['metadata'] ?? [];

                foreach (($value['statuses'] ?? []) as $status) {
                    $statuses[] = [
                        'wa_message_id' => $status['id'] ?? null,
                        'recipient_id' => $status['recipient_id'] ?? null,
                        'status' => $status['status'] ?? null,
                        'timestamp' => isset($status['timestamp'])
                            ? Carbon::createFromTimestamp((int) $status['timestamp'])
                            : null,
                        'conversation' => is_array($status['conversation'] ?? null) ? $status['conversation'] : null,
                        'pricing' => is_array($status['pricing'] ?? null) ? $status['pricing'] : null,
                        'errors' => is_array($status['errors'] ?? null) ? $status['errors'] : [],
                        'raw_status' => $status,
                        'metadata' => $metadata,
                    ];
                }
            }
        }

        return $statuses;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function isValidWebhookPayload(array $payload): bool
    {
        return isset($payload['object'])
            && $payload['object'] === 'whatsapp_business_account'
            && isset($payload['entry'])
            && is_array($payload['entry']);
    }

    /**
     * @param array<string, mixed> $msg
     * @param array<string, mixed>|null $interactiveReply
     */
    public function extractText(array $msg, ?array $interactiveReply = null): ?string
    {
        $type = $msg['type'] ?? null;

        return match ($type) {
            'text' => $msg['text']['body'] ?? null,
            'button' => $msg['button']['text'] ?? null,
            'interactive' => $this->interactiveReplyText($interactiveReply ?? $this->extractInteractiveReply($msg)),
            'audio' => '[Voice note]',
            'image' => $msg['image']['caption'] ?? '[Gambar]',
            'video' => $msg['video']['caption'] ?? '[Video]',
            'document' => $msg['document']['caption']
                ?? ($msg['document']['filename'] ?? '[Dokumen]'),
            'location' => ($msg['location']['name'] ?? $msg['location']['address'] ?? '[Lokasi]'),
            default => null,
        };
    }

    /**
     * @param array<int, array<string, mixed>> $contacts
     * @return array<string, array<string, mixed>>
     */
    private function indexContactsByWaId(array $contacts): array
    {
        $map = [];

        foreach ($contacts as $contact) {
            $waId = $contact['wa_id'] ?? null;
            if ($waId !== null) {
                $map[$waId] = $contact;
            }
        }

        return $map;
    }

    /**
     * @param array<string, mixed> $msg
     * @return array<string, mixed>|null
     */
    private function extractInteractiveReply(array $msg): ?array
    {
        if (($msg['type'] ?? null) !== 'interactive') {
            return null;
        }

        $interactive = $msg['interactive'] ?? [];

        if (is_array($interactive['button_reply'] ?? null)) {
            return [
                'type' => 'button_reply',
                'id' => $interactive['button_reply']['id'] ?? null,
                'title' => $interactive['button_reply']['title'] ?? null,
                'description' => null,
            ];
        }

        if (is_array($interactive['list_reply'] ?? null)) {
            return [
                'type' => 'list_reply',
                'id' => $interactive['list_reply']['id'] ?? null,
                'title' => $interactive['list_reply']['title'] ?? null,
                'description' => $interactive['list_reply']['description'] ?? null,
            ];
        }

        return null;
    }

    /**
     * @param array<string, mixed>|null $interactiveReply
     */
    private function interactiveReplyText(?array $interactiveReply): ?string
    {
        $id = trim((string) ($interactiveReply['id'] ?? ''));

        if ($id !== '') {
            $normalizedById = match (true) {
                $id === 'booking_confirm' => 'benar',
                $id === 'booking_change' => 'ubah data',
                str_starts_with($id, 'departure_date:') => $id,
                str_starts_with($id, 'departure_time:') => $id,
                str_starts_with($id, 'pickup_location:') => $id,
                str_starts_with($id, 'dropoff_location:') => $id,
                str_starts_with($id, 'change_field:') => $id,
                preg_match('/^passenger_count_(\d+)$/', $id, $matches) === 1 => (string) ($matches[1] ?? $id),
                default => null,
            };

            if ($normalizedById !== null) {
                return $normalizedById;
            }
        }

        $title = trim((string) ($interactiveReply['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        return $id !== '' ? $id : null;
    }

    private function humanizeSelectionId(string $value): string
    {
        return mb_convert_case(
            trim(str_replace(['_', '-'], ' ', $value)),
            MB_CASE_TITLE,
            'UTF-8',
        );
    }

    /**
     * @param array<string, mixed> $msg
     * @param array<string, mixed> $metadata
     * @param array<string, mixed>|null $interactiveReply
     * @return array<string, mixed>
     */
    private function buildRawPayload(array $msg, array $metadata, ?array $interactiveReply): array
    {
        $payload = $msg;
        $type = (string) ($msg['type'] ?? '');

        if ($type === 'audio') {
            $payload['audio_url'] = null;
            $payload['mime_type'] = $msg['audio']['mime_type'] ?? null;
            $payload['media_size_bytes'] = $msg['audio']['file_size'] ?? null;
            $payload['media_original_name'] = $msg['audio']['filename'] ?? null;
        }

        if ($type === 'image') {
            $payload['media_caption'] = $msg['image']['caption'] ?? null;
            $payload['mime_type'] = $msg['image']['mime_type'] ?? null;
            $payload['media_size_bytes'] = $msg['image']['file_size'] ?? null;
            $payload['media_original_name'] = $msg['image']['filename'] ?? null;
        }

        if ($type === 'video') {
            $payload['media_caption'] = $msg['video']['caption'] ?? null;
            $payload['mime_type'] = $msg['video']['mime_type'] ?? null;
            $payload['media_size_bytes'] = $msg['video']['file_size'] ?? null;
            $payload['media_original_name'] = $msg['video']['filename'] ?? null;
            $payload['video_url'] = null;
        }

        if ($type === 'document') {
            $payload['media_caption'] = $msg['document']['caption'] ?? null;
            $payload['mime_type'] = $msg['document']['mime_type'] ?? null;
            $payload['media_size_bytes'] = $msg['document']['file_size'] ?? null;
            $payload['media_original_name'] = $msg['document']['filename'] ?? null;
            $payload['document_url'] = null;
        }

        if ($type === 'location') {
            $payload['location'] = [
                'latitude' => $msg['location']['latitude'] ?? null,
                'longitude' => $msg['location']['longitude'] ?? null,
                'name' => $msg['location']['name'] ?? null,
                'address' => $msg['location']['address'] ?? null,
            ];
        }

        if ($metadata !== []) {
            $payload['_webhook_metadata'] = $metadata;
        }

        if ($interactiveReply !== null) {
            $payload['_interactive_selection'] = $interactiveReply;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<int, mixed>
     */
    private function extractRawCallEvents(string $field, array $value): array
    {
        if ($field === 'calls' && is_array($value['calls'] ?? null)) {
            return array_values($value['calls']);
        }

        if ($field === 'calls' && $this->looksLikeCallEvent($value)) {
            return [$value];
        }

        if (is_array($value['calls'] ?? null)) {
            return array_values($value['calls']);
        }

        if (is_array($value['call'] ?? null)) {
            return [$value['call']];
        }

        if ($field === 'calls' || $this->looksLikeCallEvent($value)) {
            return [$value];
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $rawCall
     */
    private function looksLikeCallEvent(array $rawCall): bool
    {
        return $this->extractCallId($rawCall) !== null
            || $this->extractCallEventName($rawCall) !== null
            || isset($rawCall['termination_reason'])
            || isset($rawCall['call_status']);
    }

    /**
     * @param  array<string, mixed>  $rawCall
     */
    private function extractCallId(array $rawCall): ?string
    {
        foreach ([
            $rawCall['wa_call_id'] ?? null,
            $rawCall['call_id'] ?? null,
            $rawCall['id'] ?? null,
            data_get($rawCall, 'call.id'),
            data_get($rawCall, 'call.call_id'),
            data_get($rawCall, 'data.id'),
            data_get($rawCall, 'data.call_id'),
        ] as $candidate) {
            $normalized = $this->normalizeCallString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawCall
     */
    private function extractCallEventName(array $rawCall): ?string
    {
        foreach ([
            $rawCall['event'] ?? null,
            $rawCall['status'] ?? null,
            $rawCall['call_status'] ?? null,
            $rawCall['state'] ?? null,
            data_get($rawCall, 'call.event'),
            data_get($rawCall, 'call.status'),
            data_get($rawCall, 'data.event'),
            data_get($rawCall, 'data.status'),
        ] as $candidate) {
            $normalized = $this->normalizeCallString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawCall
     */
    private function extractCallDirection(array $rawCall): ?string
    {
        foreach ([
            $rawCall['direction'] ?? null,
            $rawCall['call_direction'] ?? null,
            $rawCall['conversation_direction'] ?? null,
            data_get($rawCall, 'call.direction'),
            data_get($rawCall, 'call.call_direction'),
            data_get($rawCall, 'data.direction'),
        ] as $candidate) {
            $normalized = $this->normalizeCallString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawCall
     * @param  array<int, string>  $keys
     */
    private function extractCallParty(array $rawCall, array $keys): ?string
    {
        foreach ($keys as $key) {
            foreach ([
                data_get($rawCall, $key),
                data_get($rawCall, $key.'.wa_id'),
                data_get($rawCall, $key.'.phone'),
                data_get($rawCall, $key.'.id'),
                data_get($rawCall, $key.'.user'),
            ] as $candidate) {
                $normalized = $this->normalizeCallString($candidate);

                if ($normalized !== null) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawCall
     */
    private function extractCallTerminationReason(array $rawCall): ?string
    {
        foreach ([
            $rawCall['termination_reason'] ?? null,
            $rawCall['reason'] ?? null,
            data_get($rawCall, 'terminate.reason'),
            data_get($rawCall, 'call.termination_reason'),
            data_get($rawCall, 'call.reason'),
            data_get($rawCall, 'error.message'),
            data_get($rawCall, 'errors.0.message'),
            data_get($rawCall, 'errors.0.code'),
        ] as $candidate) {
            $normalized = $this->normalizeCallString($candidate);

            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeTimestamp(mixed $timestamp): ?string
    {
        if ($timestamp instanceof Carbon) {
            return $timestamp->toIso8601String();
        }

        if (is_int($timestamp) || (is_string($timestamp) && ctype_digit($timestamp))) {
            return Carbon::createFromTimestamp((int) $timestamp)->toIso8601String();
        }

        if (is_string($timestamp) && trim($timestamp) !== '') {
            try {
                return Carbon::parse($timestamp)->toIso8601String();
            } catch (\Throwable) {
                return trim($timestamp);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawCall
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function buildCallRawPayload(array $rawCall, array $metadata, string $field): array
    {
        $payload = $rawCall;

        if ($metadata !== []) {
            $payload['_webhook_metadata'] = $metadata;
        }

        $payload['_webhook_field'] = $field;

        return $payload;
    }

    private function normalizeCallString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $normalized = trim((string) $value);

        return $normalized !== '' ? $normalized : null;
    }
}