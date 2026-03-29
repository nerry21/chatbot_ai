<?php

namespace App\Services\WhatsApp;

class WhatsAppMessageParser
{
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

    public function isValidWebhookPayload(array $payload): bool
    {
        return isset($payload['object'])
            && $payload['object'] === 'whatsapp_business_account'
            && isset($payload['entry'])
            && is_array($payload['entry']);
    }

    public function extractText(array $msg, ?array $interactiveReply = null): ?string
    {
        $type = $msg['type'] ?? null;

        return match($type) {
            'text' => $msg['text']['body'] ?? null,
            'button' => $msg['button']['text'] ?? null,
            'interactive' => $this->interactiveReplyText($interactiveReply ?? $this->extractInteractiveReply($msg)),
            'audio' => '[Voice note]',
            default => null,
        };
    }

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

    private function interactiveReplyText(?array $interactiveReply): ?string
    {
        $id = trim((string) ($interactiveReply['id'] ?? ''));

        if ($id !== '') {
            $normalizedById = match (true) {
                $id === 'booking_confirm' => 'benar',
                $id === 'booking_change' => 'ubah data',
                str_starts_with($id, 'departure_time:') => (string) substr($id, strlen('departure_time:')),
                str_starts_with($id, 'pickup_location:') => $this->humanizeSelectionId((string) substr($id, strlen('pickup_location:'))),
                str_starts_with($id, 'dropoff_location:') => $this->humanizeSelectionId((string) substr($id, strlen('dropoff_location:'))),
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

        return $id !== '' ? $this->humanizeSelectionId($id) : null;
    }

    private function humanizeSelectionId(string $value): string
    {
        return mb_convert_case(
            trim(str_replace(['_', '-'], ' ', $value)),
            MB_CASE_TITLE,
            'UTF-8',
        );
    }

    private function buildRawPayload(array $msg, array $metadata, ?array $interactiveReply): array
    {
        $payload = $msg;

        if (($msg['type'] ?? null) === 'audio') {
            $payload['audio_url'] = null;
        }

        if ($metadata !== []) {
            $payload['_webhook_metadata'] = $metadata;
        }

        if ($interactiveReply !== null) {
            $payload['_interactive_selection'] = $interactiveReply;
        }

        return $payload;
    }
}
