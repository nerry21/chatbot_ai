<?php

namespace App\Services\WhatsApp;

class WhatsAppMessageParser
{
    /**
     * Parse a raw Meta Cloud API webhook payload and extract message entries.
     *
     * @param  array<string, mixed>  $payload
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

                $value        = $change['value'] ?? [];
                $rawMessages  = $value['messages'] ?? [];
                $contacts     = $value['contacts'] ?? [];
                $metadata     = $value['metadata'] ?? [];

                $contactMap = $this->indexContactsByWaId($contacts);

                foreach ($rawMessages as $msg) {
                    $waId    = $msg['from'] ?? null;
                    $contact = $contactMap[$waId] ?? null;
                    $interactiveReply = $this->extractInteractiveReply($msg);

                    $messages[] = [
                        'wa_message_id'  => $msg['id'] ?? null,
                        'from_wa_id'     => $waId,
                        'from_name'      => $contact['profile']['name'] ?? null,
                        'message_type'   => $msg['type'] ?? 'unknown',
                        'message_text'   => $this->extractText($msg, $interactiveReply),
                        'timestamp'      => isset($msg['timestamp'])
                                            ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp'])
                                            : null,
                        'interactive_reply' => $interactiveReply,
                        'raw_message'    => $msg,
                        'raw_payload'    => $this->buildRawPayload($msg, $metadata, $interactiveReply),
                        'metadata'       => $metadata,
                    ];
                }
            }
        }

        return $messages;
    }

    /**
     * Determine if the payload is a valid WhatsApp Cloud API webhook event.
     *
     * @param  array<string, mixed>  $payload
     */
    public function isValidWebhookPayload(array $payload): bool
    {
        return isset($payload['object'])
            && $payload['object'] === 'whatsapp_business_account'
            && isset($payload['entry'])
            && is_array($payload['entry']);
    }

    /**
     * Extract plain text content from a message node.
     *
     * @param  array<string, mixed>  $msg
     * @param  array<string, mixed>|null  $interactiveReply
     */
    public function extractText(array $msg, ?array $interactiveReply = null): ?string
    {
        $type = $msg['type'] ?? null;

        return match($type) {
            'text'     => $msg['text']['body'] ?? null,
            'button'   => $msg['button']['text'] ?? null,
            'interactive' => $this->interactiveReplyText($interactiveReply ?? $this->extractInteractiveReply($msg)),
            default    => null,
        };
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * @param  array<int, array<string, mixed>>  $contacts
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
     * @param  array<string, mixed>  $msg
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
     * @param  array<string, mixed>|null  $interactiveReply
     */
    private function interactiveReplyText(?array $interactiveReply): ?string
    {
        $title = trim((string) ($interactiveReply['title'] ?? ''));

        if ($title !== '') {
            return $title;
        }

        $id = trim((string) ($interactiveReply['id'] ?? ''));

        if ($id === '') {
            return null;
        }

        if (preg_match('/^passenger_count_(\d+)$/', $id, $matches)) {
            return (string) ($matches[1] ?? $id);
        }

        return match (true) {
            $id === 'contact_same' => 'sama',
            $id === 'contact_diff' => 'berbeda',
            $id === 'booking_confirm' => 'benar',
            $id === 'booking_change' => 'ubah data',
            str_starts_with($id, 'departure_time:') => (string) substr($id, strlen('departure_time:')),
            str_starts_with($id, 'pickup_location:') => $this->humanizeSelectionId((string) substr($id, strlen('pickup_location:'))),
            str_starts_with($id, 'dropoff_location:') => $this->humanizeSelectionId((string) substr($id, strlen('dropoff_location:'))),
            default => $this->humanizeSelectionId($id),
        };
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
     * @param  array<string, mixed>  $msg
     * @param  array<string, mixed>  $metadata
     * @param  array<string, mixed>|null  $interactiveReply
     * @return array<string, mixed>
     */
    private function buildRawPayload(array $msg, array $metadata, ?array $interactiveReply): array
    {
        $payload = $msg;

        if ($metadata !== []) {
            $payload['_webhook_metadata'] = $metadata;
        }

        if ($interactiveReply !== null) {
            $payload['_interactive_selection'] = $interactiveReply;
        }

        return $payload;
    }
}
