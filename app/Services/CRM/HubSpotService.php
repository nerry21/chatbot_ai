<?php

namespace App\Services\CRM;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotService
{
    private const BASE_URL = 'https://api.hubapi.com/crm/v3';

    // HubSpot association type ID for contact -> note (HUBSPOT_DEFINED).
    private const ASSOCIATION_NOTE_TO_CONTACT = 202;

    private readonly string $token;

    private readonly bool $enabled;

    public function __construct()
    {
        $this->token = (string) config('chatbot.crm.hubspot.token', '');
        $this->enabled = (bool) config('chatbot.crm.hubspot.enabled', false);
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
     * @param  array<string, mixed>  $data
     * @return array{status: string, contact_id?: string, data?: array, error?: string, reason?: string}
     */
    public function upsertContact(array $data): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        try {
            $existingId = $this->findContactIdByPhoneOrEmail(
                phone: isset($data['phone']) ? (string) $data['phone'] : null,
                email: isset($data['email']) ? (string) $data['email'] : null,
            );

            if ($existingId !== null) {
                $response = Http::withToken($this->token)
                    ->timeout(10)
                    ->patch(self::BASE_URL.'/objects/contacts/'.$existingId, [
                        'properties' => $data,
                    ]);

                if ($response->successful()) {
                    return [
                        'status' => 'success',
                        'contact_id' => $existingId,
                        'data' => $response->json(),
                    ];
                }

                Log::warning('[HubSpot] update contact failed', [
                    'contact_id' => $existingId,
                    'http_status' => $response->status(),
                    'body' => $response->body(),
                ]);

                return ['status' => 'failed', 'error' => $response->body()];
            }

            $response = Http::withToken($this->token)
                ->timeout(10)
                ->post(self::BASE_URL.'/objects/contacts', [
                    'properties' => $data,
                ]);

            if ($response->successful()) {
                return [
                    'status' => 'success',
                    'contact_id' => (string) $response->json('id'),
                    'data' => $response->json(),
                ];
            }

            Log::warning('[HubSpot] create contact failed', [
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['status' => 'failed', 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] upsertContact exception', [
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status: string, data?: array, error?: string, reason?: string}
     */
    public function getContactById(string $contactId, array $properties = []): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        try {
            $propertyList = $properties !== []
                ? implode(',', $properties)
                : implode(',', $this->defaultContextProperties());

            $response = Http::withToken($this->token)
                ->timeout(10)
                ->get(self::BASE_URL.'/objects/contacts/'.$contactId, [
                    'properties' => $propertyList,
                ]);

            if ($response->successful()) {
                return [
                    'status' => 'success',
                    'data' => $response->json(),
                ];
            }

            Log::warning('[HubSpot] getContactById failed', [
                'contact_id' => $contactId,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['status' => 'failed', 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] getContactById exception', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

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
                ->post(self::BASE_URL.'/objects/notes', [
                    'properties' => [
                        'hs_note_body' => $note,
                        'hs_timestamp' => now()->toIso8601String(),
                    ],
                    'associations' => [
                        [
                            'to' => ['id' => $externalContactId],
                            'types' => [
                                [
                                    'associationCategory' => 'HUBSPOT_DEFINED',
                                    'associationTypeId' => self::ASSOCIATION_NOTE_TO_CONTACT,
                                ],
                            ],
                        ],
                    ],
                ]);

            if ($response->successful()) {
                return [
                    'status' => 'success',
                    'note_id' => (string) $response->json('id'),
                ];
            }

            Log::warning('[HubSpot] appendNote failed', [
                'contact_id' => $externalContactId,
                'http_status' => $response->status(),
                'body' => $response->body(),
            ]);

            return ['status' => 'failed', 'error' => $response->body()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] appendNote exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function extractAiContextFromPayload(array $payload): array
    {
        $properties = is_array($payload['properties'] ?? null)
            ? $payload['properties']
            : [];

        return array_filter([
            'contact_id' => $payload['id'] ?? null,
            'firstname' => $this->normalizeText($properties['firstname'] ?? null),
            'lastname' => $this->normalizeText($properties['lastname'] ?? null),
            'email' => $this->normalizeText($properties['email'] ?? null),
            'phone' => $this->normalizeText($properties['phone'] ?? null),
            'mobilephone' => $this->normalizeText($properties['mobilephone'] ?? null),
            'company' => $this->normalizeText($properties['company'] ?? null),
            'jobtitle' => $this->normalizeText($properties['jobtitle'] ?? null),
            'lifecyclestage' => $this->normalizeText($properties['lifecyclestage'] ?? null),
            'hs_lead_status' => $this->normalizeText($properties['hs_lead_status'] ?? null),
            'createdate' => $this->normalizeText($properties['createdate'] ?? null),
            'lastmodifieddate' => $this->normalizeText($properties['lastmodifieddate'] ?? null),
            'hubspotscore' => $this->normalizeText($properties['hubspotscore'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    private function findContactIdByPhoneOrEmail(?string $phone, ?string $email): ?string
    {
        $candidates = array_values(array_filter([
            ['propertyName' => 'email', 'value' => $email],
            ['propertyName' => 'phone', 'value' => $phone],
            ['propertyName' => 'mobilephone', 'value' => $phone],
        ], static fn (array $item): bool => filled($item['value'])));

        foreach ($candidates as $candidate) {
            try {
                $response = Http::withToken($this->token)
                    ->timeout(10)
                    ->post(self::BASE_URL.'/objects/contacts/search', [
                        'filterGroups' => [
                            [
                                'filters' => [
                                    [
                                        'propertyName' => $candidate['propertyName'],
                                        'operator' => 'EQ',
                                        'value' => (string) $candidate['value'],
                                    ],
                                ],
                            ],
                        ],
                        'limit' => 1,
                        'properties' => $this->defaultContextProperties(),
                    ]);

                if ($response->successful()) {
                    $first = $response->json('results.0.id');

                    if (filled($first)) {
                        return (string) $first;
                    }
                }
            } catch (\Throwable $e) {
                Log::warning('[HubSpot] findContactIdByPhoneOrEmail candidate failed', [
                    'property' => $candidate['propertyName'],
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function defaultContextProperties(): array
    {
        return [
            'firstname',
            'lastname',
            'email',
            'phone',
            'mobilephone',
            'company',
            'jobtitle',
            'lifecyclestage',
            'hs_lead_status',
            'createdate',
            'lastmodifieddate',
            'hubspotscore',
        ];
    }

    /**
     * @return array{status: string, reason: string}
     */
    private function skipped(string $reason): array
    {
        return ['status' => 'skipped', 'reason' => $reason];
    }

    private function normalizeText(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
}
