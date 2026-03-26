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

                    $messages[] = [
                        'wa_message_id'  => $msg['id'] ?? null,
                        'from_wa_id'     => $waId,
                        'from_name'      => $contact['profile']['name'] ?? null,
                        'message_type'   => $msg['type'] ?? 'unknown',
                        'message_text'   => $this->extractText($msg),
                        'timestamp'      => isset($msg['timestamp'])
                                            ? \Carbon\Carbon::createFromTimestamp((int) $msg['timestamp'])
                                            : null,
                        'raw_message'    => $msg,
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
     */
    public function extractText(array $msg): ?string
    {
        $type = $msg['type'] ?? null;

        return match($type) {
            'text'     => $msg['text']['body'] ?? null,
            'button'   => $msg['button']['text'] ?? null,
            'interactive' => $msg['interactive']['button_reply']['title']
                             ?? $msg['interactive']['list_reply']['title']
                             ?? null,
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
}
