<?php

namespace App\Services\CRM;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class HubSpotService
{
    private const BASE_URL = 'https://api.hubapi.com/crm/v3';
    private const ASSOCIATION_NOTE_TO_CONTACT = 202;

    private readonly string $token;
    private readonly bool $enabled;

    public function __construct()
    {
        $this->token = (string) config('chatbot.crm.hubspot.token', '');
        $this->enabled = (bool) config('chatbot.crm.hubspot.enabled', false);
    }

    public function isEnabled(): bool
    {
        return $this->enabled && $this->token !== '';
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{status:string, contact_id?:string, data?:array<string,mixed>, error?:string, reason?:string, http_status?:int, unknown_properties?:array<int,string>}
     */
    public function upsertContact(array $data): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        $properties = $this->sanitizeProperties($data);

        if ($properties === []) {
            return $this->skipped('empty_properties');
        }

        try {
            $existingId = $this->findContactIdByPhoneOrEmail(
                phone: isset($properties['phone']) ? (string) $properties['phone'] : null,
                email: isset($properties['email']) ? (string) $properties['email'] : null,
            );

            if ($existingId !== null) {
                return $this->patchContact($existingId, $properties);
            }

            $response = $this->http()->post(self::BASE_URL.'/objects/contacts', [
                'properties' => $properties,
            ]);

            return $this->normalizeContactWriteResponse($response, null, $properties, 'create_contact');
        } catch (ConnectionException $e) {
            Log::error('[HubSpot] upsertContact connection exception', ['error' => $e->getMessage()]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] upsertContact exception', ['error' => $e->getMessage()]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status:string, data?:array<string,mixed>, error?:string, reason?:string, http_status?:int}
     */
    public function getContactById(string $contactId, array $properties = []): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        $contactId = trim($contactId);

        if ($contactId === '') {
            return $this->skipped('empty_contact_id');
        }

        try {
            $propertyList = $properties !== []
                ? implode(',', array_values(array_unique(array_filter($properties, 'is_string'))))
                : implode(',', $this->defaultContextProperties());

            $response = $this->http()->get(self::BASE_URL.'/objects/contacts/'.$contactId, [
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

            return [
                'status' => 'failed',
                'error' => $response->body(),
                'http_status' => $response->status(),
            ];
        } catch (ConnectionException $e) {
            Log::error('[HubSpot] getContactById connection exception', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] getContactById exception', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status:string, note_id?:string, error?:string, reason?:string, http_status?:int}
     */
    public function appendNote(string $externalContactId, string $note): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        $externalContactId = trim($externalContactId);
        $note = trim($note);

        if ($externalContactId === '') {
            return $this->skipped('empty_contact_id');
        }

        if ($note === '') {
            return $this->skipped('empty_note');
        }

        try {
            $response = $this->http()->post(self::BASE_URL.'/objects/notes', [
                'properties' => [
                    'hs_note_body' => $note,
                    'hs_timestamp' => now()->toIso8601String(),
                ],
                'associations' => [[
                    'to' => ['id' => $externalContactId],
                    'types' => [[
                        'associationCategory' => 'HUBSPOT_DEFINED',
                        'associationTypeId' => self::ASSOCIATION_NOTE_TO_CONTACT,
                    ]],
                ]],
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

            return [
                'status' => 'failed',
                'error' => $response->body(),
                'http_status' => $response->status(),
            ];
        } catch (ConnectionException $e) {
            Log::error('[HubSpot] appendNote connection exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] appendNote exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{status:string, data?:array<string,mixed>, error?:string, reason?:string, http_status?:int, unknown_properties?:array<int,string>, applied_properties?:array<string,mixed>, removed_properties?:array<int,string>}
     */
    public function updateContactProperties(string $externalContactId, array $properties): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        $externalContactId = trim($externalContactId);
        $payload = $this->sanitizeProperties($properties);

        if ($externalContactId === '') {
            return $this->skipped('empty_contact_id');
        }

        if ($payload === []) {
            return $this->skipped('empty_properties');
        }

        try {
            $initial = $this->patchContact($externalContactId, $payload);

            if (($initial['status'] ?? null) !== 'failed') {
                return $initial;
            }

            $unknownProperties = is_array($initial['unknown_properties'] ?? null)
                ? array_values($initial['unknown_properties'])
                : [];

            if ($unknownProperties === []) {
                return $initial;
            }

            $filteredPayload = Arr::except($payload, $unknownProperties);

            if ($filteredPayload === []) {
                return [
                    'status' => 'skipped',
                    'reason' => 'all_properties_unknown',
                    'unknown_properties' => $unknownProperties,
                    'removed_properties' => $unknownProperties,
                ];
            }

            $retry = $this->patchContact($externalContactId, $filteredPayload);

            if (($retry['status'] ?? null) === 'success') {
                $retry['status'] = 'success';
                $retry['unknown_properties'] = $unknownProperties;
                $retry['applied_properties'] = $filteredPayload;
                $retry['removed_properties'] = $unknownProperties;

                Log::warning('[HubSpot] updateContactProperties succeeded after filtering unknown properties', [
                    'contact_id' => $externalContactId,
                    'removed_properties' => $unknownProperties,
                    'applied_properties' => array_keys($filteredPayload),
                ]);
            }

            return $retry;
        } catch (ConnectionException $e) {
            Log::error('[HubSpot] updateContactProperties connection exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] updateContactProperties exception', [
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
            'last_ai_intent' => $this->normalizeText($properties['last_ai_intent'] ?? null),
            'last_ai_summary' => $this->normalizeText($properties['last_ai_summary'] ?? null),
            'customer_interest_topic' => $this->normalizeText($properties['customer_interest_topic'] ?? null),
            'ai_sentiment' => $this->normalizeText($properties['ai_sentiment'] ?? null),
            'needs_human_followup' => $this->normalizeText($properties['needs_human_followup'] ?? null),
            'last_whatsapp_interaction_at' => $this->normalizeText($properties['last_whatsapp_interaction_at'] ?? null),
            'admin_takeover_active' => $this->normalizeText($properties['admin_takeover_active'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function patchContact(string $contactId, array $properties): array
    {
        $response = $this->http()->patch(self::BASE_URL.'/objects/contacts/'.$contactId, [
            'properties' => $properties,
        ]);

        return $this->normalizeContactWriteResponse($response, $contactId, $properties, 'patch_contact');
    }

    private function normalizeContactWriteResponse(
        Response $response,
        ?string $contactId,
        array $properties,
        string $operation,
    ): array {
        if ($response->successful()) {
            return [
                'status' => 'success',
                'contact_id' => $contactId ?? (string) $response->json('id'),
                'data' => $response->json(),
                'applied_properties' => $properties,
            ];
        }

        $unknownProperties = $this->extractUnknownProperties($response);
        Log::warning('[HubSpot] '.$operation.' failed', [
            'contact_id' => $contactId,
            'http_status' => $response->status(),
            'unknown_properties' => $unknownProperties,
            'body' => $response->body(),
        ]);

        return [
            'status' => 'failed',
            'error' => $response->body(),
            'http_status' => $response->status(),
            'unknown_properties' => $unknownProperties,
        ];
    }

    private function findContactIdByPhoneOrEmail(?string $phone, ?string $email): ?string
    {
        $candidates = array_values(array_filter([
            ['propertyName' => 'email', 'value' => $email],
            ['propertyName' => 'phone', 'value' => $phone],
            ['propertyName' => 'mobilephone', 'value' => $phone],
        ], static fn (array $item): bool => filled($item['value'])));

        foreach ($candidates as $candidate) {
            try {
                $response = $this->http()->post(self::BASE_URL.'/objects/contacts/search', [
                    'filterGroups' => [[
                        'filters' => [[
                            'propertyName' => $candidate['propertyName'],
                            'operator' => 'EQ',
                            'value' => (string) $candidate['value'],
                        ]],
                    ]],
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

    private function http(): PendingRequest
    {
        return Http::withToken($this->token)
            ->acceptJson()
            ->asJson()
            ->timeout(12)
            ->retry(2, 500, throw: false);
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function sanitizeProperties(array $properties): array
    {
        $out = [];

        foreach ($properties as $key => $value) {
            if (! is_string($key) || trim($key) === '') {
                continue;
            }

            $normalized = $this->normalizePropertyValue($value);

            if ($normalized === null || $normalized === '') {
                continue;
            }

            $out[$key] = $normalized;
        }

        return $out;
    }

    private function normalizePropertyValue(mixed $value): string|int|float|bool|null
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_scalar($value)) {
            $text = trim((string) $value);

            return $text !== '' ? $text : null;
        }

        return null;
    }

    /**
     * @return array<int, string>
     */
    private function extractUnknownProperties(Response $response): array
    {
        $body = $response->body();
        $json = $response->json();

        $candidates = [];

        foreach ((array) ($json['errors'] ?? []) as $error) {
            if (! is_array($error)) {
                continue;
            }

            foreach (['context.propertyName', 'context.properties', 'message'] as $path) {
                $value = data_get($error, $path);

                if (is_string($value) && $value !== '') {
                    $candidates[] = $value;
                }

                if (is_array($value)) {
                    foreach ($value as $item) {
                        if (is_string($item) && trim($item) !== '') {
                            $candidates[] = trim($item);
                        }
                    }
                }
            }
        }

        if (preg_match_all('/Property\s+"([^"]+)"/i', $body, $matches)) {
            $candidates = array_merge($candidates, $matches[1]);
        }

        if (preg_match_all('/property(?:\s+name)?\s*[:=]\s*([a-zA-Z0-9_]+)/i', $body, $matches)) {
            $candidates = array_merge($candidates, $matches[1]);
        }

        $out = [];
        foreach ($candidates as $candidate) {
            $candidate = trim((string) $candidate);
            if ($candidate !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $candidate)) {
                $out[] = $candidate;
            }
        }

        return array_values(array_unique($out));
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
            'last_ai_intent',
            'last_ai_summary',
            'customer_interest_topic',
            'ai_sentiment',
            'needs_human_followup',
            'last_whatsapp_interaction_at',
            'admin_takeover_active',
        ];
    }

    /**
     * @return array{status:string, reason:string}
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
