<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotService
{
    private const BASE_URL = 'https://api.hubapi.com/crm/v3';

    // HubSpot association type ID for contact → note (HUBSPOT_DEFINED).
    private const ASSOCIATION_NOTE_TO_CONTACT = 202;

    private readonly string $token;
    private readonly bool   $enabled;

    public function __construct()
    {
        $this->token   = (string) config('chatbot.crm.hubspot.token', '');
        $this->enabled = (bool)   config('chatbot.crm.hubspot.enabled', false);
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Returns true only when the integration is switched on AND a token exists.
     */
    public function isEnabled(): bool
    {
        return $this->enabled && $this->token !== '';
    }

    /**
     * Create or update a contact in HubSpot.
     *
     * @param  array<string, mixed>  $data  HubSpot property map (phone, firstname, email, …)
     * @return array{status: string, contact_id?: string, data?: array, error?: string, reason?: string}
     */
    public function upsertContact(array $data): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->post(self::BASE_URL . '/objects/contacts', [
                    'properties' => $data,
                ]);

            if ($response->successful()) {
                return [
                    'status'     => 'success',
                    'contact_id' => (string) $response->json('id'),
                    'data'       => $response->json(),
                ];
            }

            Log::warning('[HubSpot] upsertContact failed', [
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            return ['status' => 'failed', 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] upsertContact exception: ' . $e->getMessage());

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Append a note to an existing HubSpot contact.
     *
     * @return array{status: string, note_id?: string, error?: string, reason?: string}
     */
    public function appendNote(string $externalContactId, string $note): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        try {
            $response = Http::withToken($this->token)
                ->timeout(10)
                ->post(self::BASE_URL . '/objects/notes', [
                    'properties'   => [
                        'hs_note_body' => $note,
                        'hs_timestamp' => now()->toIso8601String(),
                    ],
                    'associations' => [
                        [
                            'to'    => ['id' => $externalContactId],
                            'types' => [
                                [
                                    'associationCategory' => 'HUBSPOT_DEFINED',
                                    'associationTypeId'   => self::ASSOCIATION_NOTE_TO_CONTACT,
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                return [
                    'status'  => 'success',
                    'note_id' => (string) $response->json('id'),
                ];
            }

            Log::warning('[HubSpot] appendNote failed', [
                'contact_id'  => $externalContactId,
                'http_status' => $response->status(),
                'body'        => $response->body(),
            ]);

            return ['status' => 'failed', 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] appendNote exception: ' . $e->getMessage());

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /** @return array{status: string, reason: string} */
    private function skipped(string $reason): array
    {
        return ['status' => 'skipped', 'reason' => $reason];
    }
}
