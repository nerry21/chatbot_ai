<?php

namespace App\Services\CRM;

use App\Models\Customer;
use App\Support\WaLog;
use Illuminate\Support\Facades\Log;

class JetCrmService
{
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Upsert a Customer record using phone_e164 (or email) as the lookup key.
     * Maps incoming HubSpot-style keys (firstname/lastname/phone/email/...)
     * onto Customer fillable columns. Unknown keys are stashed under
     * sync_payload on the related CrmContact for downstream context.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function upsertContact(array $data): array
    {
        $properties = $this->sanitizeProperties($data);

        if ($properties === []) {
            return $this->skipped('empty_properties');
        }

        try {
            $customer = $this->findCustomer($properties);
            $mapped = $this->mapToCustomerColumns($properties);

            if ($customer === null) {
                if ($mapped === []) {
                    return $this->skipped('no_lookup_key');
                }

                $customer = Customer::create($mapped + ['status' => 'active']);
            } elseif ($mapped !== []) {
                $customer->fill($mapped)->save();
            }

            $customer->refresh();

            return [
                'status' => 'success',
                'id' => $customer->id,
                'contact_id' => (string) $customer->id,
                'properties' => $customer->toArray(),
                'data' => $customer->toArray(),
                'applied_properties' => $mapped,
            ];
        } catch (\Throwable $e) {
            Log::error('[JetCrm] upsertContact exception', ['error' => $e->getMessage()]);

            return [
                'status' => 'non_retryable_failure',
                'error' => $e->getMessage(),
                'reason_code' => 'unexpected_exception',
                'retryable' => false,
            ];
        }
    }

    /**
     * @param  array<int, string>  $properties
     * @return array<string, mixed>
     */
    public function getContactById(string $contactId, array $properties = []): array
    {
        $contactId = trim($contactId);

        if ($contactId === '') {
            return $this->skipped('empty_contact_id');
        }

        $customer = Customer::find($contactId);

        if ($customer === null) {
            return [
                'status' => 'failed',
                'error' => 'customer_not_found',
            ];
        }

        $full = $customer->toArray();
        $filtered = $properties === []
            ? $full
            : array_intersect_key($full, array_flip(array_filter($properties, 'is_string')));

        return [
            'status' => 'success',
            'id' => $customer->id,
            'properties' => $filtered,
            'data' => [
                'id' => (string) $customer->id,
                'properties' => $filtered,
            ],
        ];
    }

    /**
     * Append a timestamped note to customers.notes. Newest entry first.
     *
     * @return array<string, mixed>
     */
    public function appendNote(string $externalContactId, string $note): array
    {
        $externalContactId = trim($externalContactId);
        $noteBody = $this->normalizeText($note);

        if ($externalContactId === '') {
            return $this->skipped('empty_contact_id');
        }

        if ($noteBody === null) {
            return $this->skipped('empty_note');
        }

        $customer = Customer::find($externalContactId);

        if ($customer === null) {
            WaLog::error('[JetCrmService] Note append failed', [
                'contact_id' => $externalContactId,
                'error_type' => 'contact_not_found',
            ]);

            return [
                'status' => 'non_retryable_failure',
                'error' => 'customer_not_found',
                'reason_code' => 'contact_not_found',
                'retryable' => false,
            ];
        }

        $entry = '['.now()->format('Y-m-d H:i').'] '.$noteBody."\n";
        $existing = (string) ($customer->notes ?? '');
        $customer->notes = $entry.$existing;
        $customer->save();

        $noteId = 'local_note_'.time();

        WaLog::info('[JetCrmService] Note appended', [
            'contact_id' => $externalContactId,
            'note_length' => mb_strlen($noteBody),
        ]);

        return [
            'status' => 'success',
            'id' => $noteId,
            'note_id' => $noteId,
            'note' => $noteBody,
            'customer_id' => $customer->id,
            'retryable' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public function updateContactProperties(string $externalContactId, array $properties): array
    {
        $externalContactId = trim($externalContactId);
        $payload = $this->sanitizeProperties($properties);

        if ($externalContactId === '') {
            return $this->skipped('empty_contact_id');
        }

        if ($payload === []) {
            return $this->skipped('empty_properties');
        }

        $customer = Customer::find($externalContactId);

        if ($customer === null) {
            return [
                'status' => 'non_retryable_failure',
                'error' => 'customer_not_found',
                'reason_code' => 'contact_not_found',
                'retryable' => false,
            ];
        }

        $mapped = $this->mapToCustomerColumns($payload);
        $applied = [];
        $dropped = [];

        foreach ($payload as $key => $value) {
            if (! array_key_exists($key, $mapped) && ! in_array($key, array_keys($mapped), true)) {
                $dropped[] = $key;
            }
        }

        if ($mapped !== []) {
            try {
                $customer->fill($mapped)->save();
                $applied = $mapped;

                WaLog::info('[JetCrmService] Contact properties updated', [
                    'contact_id' => $externalContactId,
                    'property_keys' => array_keys($mapped),
                    'dropped_properties' => $dropped,
                ]);
            } catch (\Throwable $e) {
                WaLog::error('[JetCrmService] Contact properties update failed', [
                    'contact_id' => $externalContactId,
                    'error_type' => 'unexpected_exception',
                    'message' => $e->getMessage(),
                ]);

                return [
                    'status' => 'non_retryable_failure',
                    'error' => $e->getMessage(),
                    'reason_code' => 'unexpected_exception',
                    'retryable' => false,
                ];
            }
        }

        $customer->refresh();

        return [
            'status' => 'success',
            'id' => $customer->id,
            'contact_id' => (string) $customer->id,
            'properties' => $customer->toArray(),
            'data' => $customer->toArray(),
            'applied_properties' => $applied,
            'unknown_properties' => array_values(array_unique($dropped)),
            'removed_properties' => array_values(array_unique($dropped)),
        ];
    }

    /**
     * Mirrors the prior shape: pull a flat AI-relevant slice out of
     * a CRM payload (legacy HubSpot-shaped or local sync_payload).
     *
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

    /**
     * @param  array<string, mixed>  $properties
     */
    private function findCustomer(array $properties): ?Customer
    {
        $phone = isset($properties['phone']) ? $this->normalizeText($properties['phone']) : null;
        $phone ??= isset($properties['phone_e164']) ? $this->normalizeText($properties['phone_e164']) : null;
        $phone ??= isset($properties['mobilephone']) ? $this->normalizeText($properties['mobilephone']) : null;
        $email = isset($properties['email']) ? $this->normalizeText($properties['email']) : null;

        if ($phone !== null) {
            $found = Customer::where('phone_e164', $phone)->first();
            if ($found !== null) {
                return $found;
            }
        }

        if ($email !== null) {
            return Customer::where('email', $email)->first();
        }

        return null;
    }

    /**
     * Map HubSpot-style and direct keys onto the Customer fillable columns.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    private function mapToCustomerColumns(array $properties): array
    {
        $allowed = [
            'name',
            'phone_e164',
            'email',
            'mobile_user_id',
            'mobile_device_id',
            'preferred_channel',
            'avatar_url',
            'preferred_pickup',
            'preferred_destination',
            'preferred_departure_time',
            'total_bookings',
            'total_spent',
            'last_interaction_at',
            'crm_contact_id',
            'notes',
            'status',
        ];

        $out = [];

        foreach ($allowed as $column) {
            if (array_key_exists($column, $properties)) {
                $out[$column] = $properties[$column];
            }
        }

        if (! isset($out['phone_e164'])) {
            $phone = $properties['phone'] ?? $properties['mobilephone'] ?? null;
            if ($this->normalizeText($phone) !== null) {
                $out['phone_e164'] = $this->normalizeText($phone);
            }
        }

        if (! isset($out['name'])) {
            $first = $this->normalizeText($properties['firstname'] ?? null);
            $last = $this->normalizeText($properties['lastname'] ?? null);
            $composed = trim((string) $first.' '.(string) $last);
            if ($composed !== '') {
                $out['name'] = $composed;
            }
        }

        return $out;
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
     * @return array{status:string, reason:string, retryable:bool}
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
