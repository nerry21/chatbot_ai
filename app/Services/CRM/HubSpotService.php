<?php

namespace App\Services\CRM;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Support\WaLog;

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
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function providerResult(
        string $status,
        ?string $reason = null,
        array $details = [],
    ): array {
        return [
            'status' => $status,
            'reason' => $reason,
            'details' => $details,
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function successResult(?string $reason = null, array $details = []): array
    {
        return $this->providerResult('success', $reason, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function skippedResult(?string $reason = null, array $details = []): array
    {
        return $this->providerResult('skipped', $reason, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function retryableFailureResult(?string $reason = null, array $details = []): array
    {
        return $this->providerResult('retryable_failure', $reason, $details);
    }

    /**
     * @param  array<string, mixed>  $details
     */
    private function nonRetryableFailureResult(?string $reason = null, array $details = []): array
    {
        return $this->providerResult('non_retryable_failure', $reason, $details);
    }

    /**
     * @return array<string, mixed>
     */
    private function classifyHubSpotException(\Throwable $e): array
    {
        $message = strtolower($e->getMessage());

        $retryableNeedles = [
            'timeout',
            'timed out',
            'temporarily unavailable',
            'temporary failure',
            'rate limit',
            '429',
            '502',
            '503',
            '504',
            'connection reset',
            'could not connect',
            'network',
        ];

        foreach ($retryableNeedles as $needle) {
            if (str_contains($message, $needle)) {
                return [
                    'status' => 'retryable_failure',
                    'error_type' => 'retryable_provider_error',
                ];
            }
        }

        $invalidPropertyNeedles = [
            'property does not exist',
            'invalid property',
            'unknown property',
        ];

        foreach ($invalidPropertyNeedles as $needle) {
            if (str_contains($message, $needle)) {
                return [
                    'status' => 'non_retryable_failure',
                    'error_type' => 'invalid_property',
                ];
            }
        }

        $authNeedles = [
            '401',
            '403',
            'unauthorized',
            'forbidden',
            'invalid authentication',
            'access denied',
        ];

        foreach ($authNeedles as $needle) {
            if (str_contains($message, $needle)) {
                return [
                    'status' => 'non_retryable_failure',
                    'error_type' => 'auth_error',
                ];
            }
        }

        $validationNeedles = [
            'validation',
            'invalid input',
            'bad request',
            '400',
        ];

        foreach ($validationNeedles as $needle) {
            if (str_contains($message, $needle)) {
                return [
                    'status' => 'non_retryable_failure',
                    'error_type' => 'validation_error',
                ];
            }
        }

        return [
            'status' => 'non_retryable_failure',
            'error_type' => 'unknown_provider_error',
        ];
    }

    /**
     * @param  array<string, mixed>  $details
     * @return array<string, mixed>
     */
    private function finalizeProviderResult(
        string $status,
        ?string $reason = null,
        array $details = [],
    ): array {
        return match ($status) {
            'success' => $this->successResult($reason, $details),
            'skipped' => $this->skippedResult($reason, $details),
            'retryable_failure' => $this->retryableFailureResult($reason, $details),
            default => $this->nonRetryableFailureResult($reason, $details),
        };
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

            return [
                'status' => 'retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => 'connection_exception',
                'retryable' => true,
            ];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] upsertContact exception', ['error' => $e->getMessage()]);

            $classified = $this->classifyHubSpotException($e);

            return [
                'status' => $classified['status'] ?? 'retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => $classified['error_type'] ?? 'unexpected_exception',
                'retryable' => ($classified['status'] ?? 'retryable_failure') === 'retryable_failure',
            ];
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

            return [
                'status' => 'retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => 'connection_exception',
                'retryable' => true,
            ];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] getContactById exception', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);

            $classified = $this->classifyHubSpotException($e);

            return [
                'status' => $classified['status'] ?? 'non_retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => $classified['error_type'] ?? 'unexpected_exception',
                'retryable' => ($classified['status'] ?? 'non_retryable_failure') === 'retryable_failure',
            ];
        }
    }

    /**
     * @return array{status:string, note_id?:string, error?:string, reason?:string, reason_code?:string, http_status?:int, retryable?:bool}
     */
    public function appendNote(string $externalContactId, string $note): array
    {
        if (! $this->isEnabled()) {
            return $this->skipped('hubspot_disabled');
        }

        $externalContactId = trim($externalContactId);
        $noteBody = $this->normalizeText($note);

        if ($externalContactId === '') {
            return $this->skipped('empty_contact_id');
        }

        if ($noteBody === null) {
            return $this->skipped('empty_note');
        }

        try {
            $response = $this->http()->post(self::BASE_URL.'/objects/notes', [
                'properties' => [
                    'hs_note_body' => $noteBody,
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
                WaLog::info('[HubSpotService] Note appended', [
                    'contact_id' => $externalContactId,
                    'note_length' => mb_strlen($noteBody),
                ]);

                return [
                    'status' => 'success',
                    'note_id' => (string) $response->json('id'),
                    'retryable' => false,
                ];
            }

            $reasonCode = $this->classifyNoteWriteFailure($response);
            $retryable = $this->isRetryableHttpStatus($response->status());

            Log::warning('[HubSpot] appendNote failed', [
                'contact_id' => $externalContactId,
                'http_status' => $response->status(),
                'reason_code' => $reasonCode,
                'retryable' => $retryable,
                'body' => $response->body(),
            ]);

            WaLog::error('[HubSpotService] Note append failed', [
                'contact_id' => $externalContactId,
                'error_type' => $reasonCode,
                'message' => $response->body(),
            ]);

            return [
                'status' => $retryable ? 'retryable_failure' : 'non_retryable_failure',
                'error' => $response->body(),
                'http_status' => $response->status(),
                'reason_code' => $reasonCode,
                'retryable' => $retryable,
            ];
        } catch (ConnectionException $e) {
            Log::error('[HubSpot] appendNote connection exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            WaLog::error('[HubSpotService] Note append failed', [
                'contact_id' => $externalContactId,
                'error_type' => 'connection_exception',
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => 'retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => 'connection_exception',
                'retryable' => true,
            ];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] appendNote exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            $classified = $this->classifyHubSpotException($e);

            WaLog::error('[HubSpotService] Note append failed', [
                'contact_id' => $externalContactId,
                'error_type' => $classified['error_type'] ?? 'unexpected_exception',
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => $classified['status'] ?? 'retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => $classified['error_type'] ?? 'unexpected_exception',
                'retryable' => ($classified['status'] ?? 'retryable_failure') === 'retryable_failure',
            ];
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
        [$payload, $droppedProperties] = $this->filterUnsupportedProperties($payload);

        if ($externalContactId === '') {
            return $this->skipped('empty_contact_id');
        }

        if ($payload === []) {
            return $this->skipped('empty_properties');
        }

        try {
            $initial = $this->patchContact($externalContactId, $payload);

            if (($initial['status'] ?? null) !== 'failed') {
                if (($initial['status'] ?? null) === 'success') {
                    WaLog::info('[HubSpotService] Contact properties updated', [
                        'contact_id' => $externalContactId,
                        'property_keys' => array_keys($payload),
                        'dropped_properties' => $droppedProperties,
                    ]);
                }
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

                WaLog::info('[HubSpotService] Contact properties updated', [
                    'contact_id' => $externalContactId,
                    'property_keys' => array_keys($filteredPayload),
                    'dropped_properties' => $unknownProperties,
                ]);
            }

            return $retry;
        } catch (ConnectionException $e) {
            Log::error('[HubSpot] updateContactProperties connection exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            WaLog::error('[HubSpotService] Contact properties update failed', [
                'contact_id' => $externalContactId,
                'error_type' => 'connection_exception',
                'message' => $e->getMessage(),
            ]);

            return ['status' => 'retryable_failure', 'error' => $e->getMessage(), 'retryable' => true];
        } catch (\Throwable $e) {
            Log::error('[HubSpot] updateContactProperties exception', [
                'contact_id' => $externalContactId,
                'error' => $e->getMessage(),
            ]);

            $classified = $this->classifyHubSpotException($e);

            WaLog::error('[HubSpotService] Contact properties update failed', [
                'contact_id' => $externalContactId,
                'error_type' => $classified['error_type'] ?? null,
                'message' => $e->getMessage(),
            ]);

            return [
                'status' => $classified['status'] ?? 'retryable_failure',
                'error' => $e->getMessage(),
                'retryable' => ($classified['status'] ?? 'retryable_failure') === 'retryable_failure',
            ];
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

        $statusCode = $response->status();
        $retryable = ($statusCode >= 500) || in_array($statusCode, [408, 429], true);
        $classification = $retryable
            ? 'retryable_failure'
            : (in_array($statusCode, [400, 401, 403, 404, 422], true) ? 'non_retryable_failure' : 'non_retryable_failure');

        return [
            'status' => $classification,
            'error' => $response->body(),
            'http_status' => $statusCode,
            'unknown_properties' => $unknownProperties,
            'retryable' => $retryable,
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
        $sanitized = [];

        foreach ($properties as $key => $value) {
            $normalizedKey = $this->normalizeText($key);

            if ($normalizedKey === null) {
                continue;
            }

            if (is_string($value)) {
                $value = trim($value);
                if ($value === '') {
                    continue;
                }
            }

            if ($value === null) {
                continue;
            }

            if (is_array($value) || is_object($value)) {
                continue;
            }

            $sanitized[$normalizedKey] = $value;
        }

        return $sanitized;
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array{0: array<string, mixed>, 1: array<int, string>}
     */
    private function filterUnsupportedProperties(array $properties): array
    {
        $filtered = [];
        $dropped = [];

        foreach ($properties as $key => $value) {
            $normalizedKey = $this->normalizeText($key);

            if ($normalizedKey === null) {
                continue;
            }

            if (! preg_match('/^[a-zA-Z0-9_]+$/', $normalizedKey)) {
                $dropped[] = $normalizedKey;
                continue;
            }

            $filtered[$normalizedKey] = $value;
        }

        return [$filtered, array_values(array_unique($dropped))];
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

    private function classifyNoteWriteFailure(Response $response): string
    {
        $status = $response->status();
        $body = strtolower($response->body());

        if ($status === 404) {
            return 'contact_not_found';
        }

        if ($status === 409) {
            return 'conflict';
        }

        if ($status === 429) {
            return 'rate_limited';
        }

        if ($status >= 500) {
            return 'hubspot_server_error';
        }

        if (str_contains($body, 'association')) {
            return 'association_failed';
        }

        if (str_contains($body, 'object not found')) {
            return 'contact_not_found';
        }

        if (str_contains($body, 'rate limit')) {
            return 'rate_limited';
        }

        return 'append_note_failed';
    }

    private function isRetryableHttpStatus(int $status): bool
    {
        return $status === 429 || $status >= 500;
    }

    /**
     * @return array{status:string, reason:string}
     */
    private function skipped(string $reason): array
    {
        return ['status' => 'skipped', 'reason' => $reason, 'retryable' => false];
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
