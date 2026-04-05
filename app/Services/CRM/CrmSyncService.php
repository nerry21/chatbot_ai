<?php

namespace App\Services\CRM;

use App\Models\Conversation;
use App\Models\CrmContact;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

class CrmSyncService
{
    public function __construct(
        private readonly HubSpotService $hubspot,
    ) {}

    /**
     * @return array{status:string, external_id?:string, error?:string, reason?:string}
     */
    public function syncCustomer(Customer $customer): array
    {
        try {
            $crmContact = CrmContact::firstOrCreate(
                ['customer_id' => $customer->id],
                ['provider' => 'hubspot', 'sync_status' => 'pending'],
            );

            if (! $this->hubspot->isEnabled()) {
                $crmContact->markLocalOnly();

                return ['status' => 'local_only', 'reason' => 'hubspot_disabled'];
            }

            $payload = $this->buildContactPayload($customer);
            $result = $this->hubspot->upsertContact($payload);

            return $this->handleContactSyncResult(
                customer: $customer,
                crmContact: $crmContact,
                result: $result,
                fallbackPayload: ['properties' => $payload],
            );
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncCustomer failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{status:string, reason?:string, error?:string, unknown_properties?:array<int,string>, applied_properties?:array<string,mixed>, removed_properties?:array<int,string>}
     */
    public function syncCustomerSnapshot(Customer $customer, array $context = []): array
    {
        try {
            $properties = $this->buildContactPayload($customer, $context);

            if ($properties === []) {
                return ['status' => 'skipped', 'reason' => 'empty_properties'];
            }

            $crmContact = $this->ensureCrmContact($customer);

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            $result = $this->hubspot->updateContactProperties(
                (string) $crmContact->external_contact_id,
                $properties,
            );

            if (($result['status'] ?? null) === 'success') {
                $this->persistSuccessfulSnapshot(
                    crmContact: $crmContact,
                    resultData: is_array($result['data'] ?? null) ? $result['data'] : [],
                    appliedProperties: is_array($result['applied_properties'] ?? null) ? $result['applied_properties'] : $properties,
                );

                return [
                    'status' => 'success',
                    'unknown_properties' => is_array($result['unknown_properties'] ?? null) ? $result['unknown_properties'] : [],
                    'applied_properties' => is_array($result['applied_properties'] ?? null) ? $result['applied_properties'] : $properties,
                    'removed_properties' => is_array($result['removed_properties'] ?? null) ? $result['removed_properties'] : [],
                ];
            }

            if (($result['status'] ?? null) === 'skipped') {
                return [
                    'status' => 'skipped',
                    'reason' => $result['reason'] ?? 'unknown',
                    'unknown_properties' => is_array($result['unknown_properties'] ?? null) ? $result['unknown_properties'] : [],
                    'removed_properties' => is_array($result['removed_properties'] ?? null) ? $result['removed_properties'] : [],
                ];
            }

            $crmContact->markFailed($result['error'] ?? 'unknown');

            return [
                'status' => 'failed',
                'error' => $result['error'] ?? 'unknown',
                'unknown_properties' => is_array($result['unknown_properties'] ?? null) ? $result['unknown_properties'] : [],
            ];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncCustomerSnapshot exception', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * @return array{status:string, reason?:string, error?:string, retryable?:bool}
     */
    public function syncConversationSummary(Customer $customer, Conversation $conversation): array
    {
        try {
            if (trim((string) $conversation->summary) === '') {
                return ['status' => 'skipped', 'reason' => 'no_summary'];
            }

            $crmContact = $this->resolveCrmContactForWriteback($customer);

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            $note = $this->buildSummaryNote($customer, $conversation);
            $result = $this->hubspot->appendNote((string) $crmContact->external_contact_id, $note);

            return $this->normalizeNoteWriteResult(
                result: $result,
                customerId: $customer->id,
                context: [
                    'conversation_id' => $conversation->id,
                    'write_type' => 'summary',
                ],
            );
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncConversationSummary exception', [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage(), 'retryable' => true];
        }
    }

    /**
     * @param  array<string, mixed>  $decisionTrace
     * @return array{status:string, reason?:string, error?:string, retryable?:bool, note_id?:string}
     */
    public function appendConversationDecisionNote(
        Customer $customer,
        string $note,
        array $decisionTrace = [],
    ): array {
        try {
            $note = trim($note);

            if ($note === '') {
                return ['status' => 'skipped', 'reason' => 'empty_note'];
            }

            $crmContact = $this->resolveCrmContactForWriteback($customer);

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            $traceId = is_scalar($decisionTrace['trace_id'] ?? null)
                ? trim((string) $decisionTrace['trace_id'])
                : '';

            if ($traceId !== '' && ! str_contains($note, '[trace:'.$traceId.']')) {
                $note = "[trace:{$traceId}]\n".$note;
            }

            $result = $this->hubspot->appendNote((string) $crmContact->external_contact_id, $note);

            Log::info('[CrmSync] appendConversationDecisionNote result', [
                'customer_id' => $customer->id,
                'status' => $result['status'] ?? null,
                'trace_id' => $traceId,
                'final_decision' => $decisionTrace['outcome']['final_decision'] ?? null,
                'retryable' => $result['retryable'] ?? null,
                'runtime_health' => $decisionTrace['llm_runtime']['overall']['health'] ?? $decisionTrace['runtime_health'] ?? null,
            ]);

            return $this->normalizeNoteWriteResult(
                result: $result,
                customerId: $customer->id,
                context: [
                    'trace_id' => $traceId,
                    'final_decision' => $decisionTrace['outcome']['final_decision'] ?? null,
                    'write_type' => 'decision_note',
                    'runtime_health' => $decisionTrace['runtime_health']
                        ?? $decisionTrace['llm_runtime']['overall']['health']
                        ?? null,
                ],
            );
        } catch (\Throwable $e) {
            Log::error('[CrmSync] appendConversationDecisionNote exception', [
                'customer_id' => $customer->id,
                'trace_id' => $decisionTrace['trace_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage(), 'retryable' => true];
        }
    }

    private function resolveCrmContactForWriteback(Customer $customer): ?CrmContact
    {
        $crmContact = $this->ensureCrmContact($customer, seedIfMissing: true);

        if ($crmContact !== null && filled($crmContact->external_contact_id)) {
            return $crmContact;
        }

        $seedResult = $this->syncCustomer($customer);

        Log::info('[CrmSync] resolveCrmContactForWriteback seed retry', [
            'customer_id' => $customer->id,
            'status' => $seedResult['status'] ?? null,
        ]);

        $crmContact = CrmContact::where('customer_id', $customer->id)->first();

        return ($crmContact !== null && filled($crmContact->external_contact_id))
            ? $crmContact
            : null;
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function normalizeNoteWriteResult(array $result, int $customerId, array $context = []): array
    {
        $status = (string) ($result['status'] ?? 'failed');

        if (in_array($status, ['success', 'skipped'], true)) {
            return [
                'status' => $status,
                'reason' => $result['reason'] ?? null,
                'note_id' => $result['note_id'] ?? null,
                'retryable' => (bool) ($result['retryable'] ?? false),
                'runtime_health' => $context['runtime_health'] ?? null,
            ];
        }

        Log::warning('[CrmSync] note write failed', [
            'customer_id' => $customerId,
            'status' => $status,
            'context' => $context,
            'http_status' => $result['http_status'] ?? null,
            'reason_code' => $result['reason_code'] ?? null,
            'retryable' => $result['retryable'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        return [
            'status' => $status === 'error' ? 'error' : 'failed',
            'error' => $result['error'] ?? 'unknown',
            'reason' => $result['reason'] ?? null,
            'reason_code' => $result['reason_code'] ?? null,
            'http_status' => $result['http_status'] ?? null,
            'retryable' => (bool) ($result['retryable'] ?? false),
            'runtime_health' => $context['runtime_health'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    private function buildContactPayload(Customer $customer, array $context = []): array
    {
        return array_filter([
            'firstname' => $customer->name ?: null,
            'phone' => $customer->phone_e164 ?: null,
            'email' => $customer->email ?: null,
            'last_ai_intent' => $this->normalizeCrmValue($context['last_ai_intent'] ?? null),
            'last_ai_summary' => $this->normalizeCrmValue($context['last_ai_summary'] ?? null),
            'customer_interest_topic' => $this->normalizeCrmValue($context['customer_interest_topic'] ?? null),
            'ai_sentiment' => $this->normalizeCrmValue($context['ai_sentiment'] ?? null),
            'needs_human_followup' => $this->normalizeBooleanString($context['needs_human_followup'] ?? null),
            'admin_takeover_active' => $this->normalizeBooleanString($context['admin_takeover_active'] ?? null),
            'last_whatsapp_interaction_at' => $this->normalizeCrmValue($context['last_whatsapp_interaction_at'] ?? null),

            // LLM runtime quality fields
            'ai_runtime_health' => $this->normalizeCrmValue($context['ai_runtime_health'] ?? null),
            'ai_runtime_overall' => $this->normalizeCrmValue($context['ai_runtime_overall'] ?? null),
            'ai_runtime_understanding_health' => $this->normalizeCrmValue($context['ai_runtime_understanding_health'] ?? null),
            'ai_runtime_reply_draft_health' => $this->normalizeCrmValue($context['ai_runtime_reply_draft_health'] ?? null),
            'ai_runtime_grounded_health' => $this->normalizeCrmValue($context['ai_runtime_grounded_health'] ?? null),
            'ai_runtime_understanding_model' => $this->normalizeCrmValue($context['ai_runtime_understanding_model'] ?? null),
            'ai_runtime_reply_draft_model' => $this->normalizeCrmValue($context['ai_runtime_reply_draft_model'] ?? null),
            'ai_runtime_grounded_model' => $this->normalizeCrmValue($context['ai_runtime_grounded_model'] ?? null),
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function buildSummaryNote(Customer $customer, Conversation $conversation): string
    {
        $lastMessageAt = $conversation->last_message_at?->format('d M Y H:i').' WIB';

        return implode("\n", array_filter([
            '=== Ringkasan Percakapan ===',
            'Pelanggan : '.($customer->name ?? 'Tidak diketahui'),
            'Nomor     : '.($customer->phone_e164 ?? '-'),
            'Percakapan: #'.$conversation->id,
            'Waktu     : '.($lastMessageAt ?: '-'),
            '',
            trim((string) $conversation->summary),
        ], static fn (mixed $value): bool => $value !== null && $value !== ''));
    }

    private function ensureCrmContact(Customer $customer, bool $seedIfMissing = true): ?CrmContact
    {
        $crmContact = CrmContact::where('customer_id', $customer->id)->first();

        if ($crmContact !== null && filled($crmContact->external_contact_id)) {
            return $crmContact;
        }

        if (! $seedIfMissing) {
            return $crmContact;
        }

        $seedResult = $this->syncCustomer($customer);

        Log::info('[CrmSync] ensureCrmContact seed result', [
            'customer_id' => $customer->id,
            'status' => $seedResult['status'] ?? null,
        ]);

        return CrmContact::where('customer_id', $customer->id)->first();
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $fallbackPayload
     * @return array{status:string, external_id?:string, error?:string, reason?:string}
     */
    private function handleContactSyncResult(
        Customer $customer,
        CrmContact $crmContact,
        array $result,
        array $fallbackPayload = [],
    ): array {
        if (($result['status'] ?? null) === 'success') {
            $crmContact->markSynced(
                (string) ($result['contact_id'] ?? ''),
                is_array($result['data'] ?? null) ? $result['data'] : $fallbackPayload,
            );

            return [
                'status' => 'synced',
                'external_id' => (string) ($result['contact_id'] ?? ''),
            ];
        }

        if (($result['status'] ?? null) === 'skipped') {
            $crmContact->markLocalOnly();

            return [
                'status' => 'local_only',
                'reason' => $result['reason'] ?? 'skipped',
            ];
        }

        $crmContact->markFailed($result['error'] ?? 'unknown');

        Log::warning('[CrmSync] contact sync failed', [
            'customer_id' => $customer->id,
            'status' => $result['status'] ?? null,
            'error' => $result['error'] ?? null,
        ]);

        return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
    }

    /**
     * @param  array<string, mixed>  $resultData
     * @param  array<string, mixed>  $appliedProperties
     */
    private function persistSuccessfulSnapshot(
        CrmContact $crmContact,
        array $resultData = [],
        array $appliedProperties = [],
    ): void {
        $payload = is_array($crmContact->sync_payload ?? null) ? $crmContact->sync_payload : [];

        $payload['properties'] = array_merge(
            is_array($payload['properties'] ?? null) ? $payload['properties'] : [],
            $appliedProperties,
            is_array($resultData['properties'] ?? null) ? $resultData['properties'] : [],
        );

        if (($resultData['id'] ?? null) !== null) {
            $payload['id'] = $resultData['id'];
        }

        $crmContact->markSynced(
            (string) $crmContact->external_contact_id,
            $payload,
        );
    }

    private function normalizeCrmValue(mixed $value): string|bool|int|float|null
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

    private function normalizeBooleanString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return (bool) $value ? 'true' : 'false';
    }
}
