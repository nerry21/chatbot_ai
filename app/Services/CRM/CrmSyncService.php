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

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Ensure a CrmContact record exists for the customer and attempt to sync
     * it to the external CRM provider (HubSpot).
     *
     * @return array{status: string, external_id?: string, error?: string}
     */
    public function syncCustomer(Customer $customer): array
    {
        try {
            $crmContact = CrmContact::firstOrCreate(
                ['customer_id' => $customer->id],
                ['provider' => 'hubspot', 'sync_status' => 'pending'],
            );

            $result = $this->hubspot->upsertContact(
                $this->buildContactPayload($customer)
            );

            if ($result['status'] === 'success') {
                $crmContact->markSynced(
                    $result['contact_id'],
                    $result['data'] ?? [],
                );

                return ['status' => 'synced', 'external_id' => $result['contact_id']];
            }

            if ($result['status'] === 'skipped') {
                $crmContact->markLocalOnly();

                return ['status' => 'local_only'];
            }

            $crmContact->markFailed($result['error'] ?? 'unknown');

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncCustomer failed', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Sync customer contact plus AI/CRM runtime properties to the external CRM.
     *
     * @param  array<string, mixed>  $context
     * @return array{status: string, reason?: string, error?: string}
     */
    public function syncCustomerSnapshot(Customer $customer, array $context = []): array
    {
        try {
            $properties = $this->buildContactPayload($customer, $context);

            if ($properties === []) {
                Log::info('[CrmSync] syncCustomerSnapshot result', [
                    'customer_id' => $customer->id,
                    'status' => 'skipped',
                    'reason' => 'empty_properties',
                ]);

                return ['status' => 'skipped', 'reason' => 'empty_properties'];
            }

            $crmContact = CrmContact::where('customer_id', $customer->id)->first();

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                $seedResult = $this->syncCustomer($customer);
                $crmContact = CrmContact::where('customer_id', $customer->id)->first();

                if ($crmContact === null || empty($crmContact->external_contact_id)) {
                    Log::info('[CrmSync] syncCustomerSnapshot result', [
                        'customer_id' => $customer->id,
                        'status' => 'skipped',
                        'reason' => 'no_crm_contact',
                        'seed_status' => $seedResult['status'] ?? null,
                    ]);

                    return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
                }
            }

            if (! method_exists($this->hubspot, 'updateContactProperties')) {
                Log::info('[CrmSync] syncCustomerSnapshot result', [
                    'customer_id' => $customer->id,
                    'status' => 'skipped',
                    'reason' => 'update_contact_properties_not_supported',
                ]);

                return ['status' => 'skipped', 'reason' => 'update_contact_properties_not_supported'];
            }

            $result = $this->hubspot->updateContactProperties(
                $crmContact->external_contact_id,
                $properties,
            );

            if (($result['status'] ?? null) === 'success') {
                $crmContact->markSynced(
                    $crmContact->external_contact_id,
                    is_array($result['data'] ?? null) ? $result['data'] : ($crmContact->sync_payload ?? []),
                );

                Log::info('[CrmSync] syncCustomerSnapshot success', [
                    'customer_id' => $customer->id,
                    'property_keys' => array_keys($properties),
                ]);

                return ['status' => 'success'];
            }

            Log::info('[CrmSync] syncCustomerSnapshot result', [
                'customer_id' => $customer->id,
                'status' => $result['status'] ?? null,
            ]);

            if (($result['status'] ?? null) === 'skipped') {
                return ['status' => 'skipped', 'reason' => $result['reason'] ?? 'unknown'];
            }

            $crmContact->markFailed($result['error'] ?? 'unknown');

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncCustomerSnapshot exception', [
                'customer_id' => $customer->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Append the conversation summary as a note on the customer's CRM contact.
     * Skipped gracefully when HubSpot is disabled or the contact has not been
     * synced yet.
     *
     * @return array{status: string, reason?: string, error?: string}
     */
    public function syncConversationSummary(Customer $customer, Conversation $conversation): array
    {
        try {
            if (empty($conversation->summary)) {
                Log::info('[CrmSync] syncConversationSummary skipped', [
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation->id,
                    'reason' => 'no_summary',
                ]);

                return ['status' => 'skipped', 'reason' => 'no_summary'];
            }

            $crmContact = CrmContact::where('customer_id', $customer->id)->first();

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                Log::info('[CrmSync] syncConversationSummary skipped', [
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation->id,
                    'reason' => 'no_crm_contact',
                ]);

                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            if (! method_exists($this->hubspot, 'appendNote')) {
                Log::info('[CrmSync] syncConversationSummary skipped', [
                    'customer_id' => $customer->id,
                    'conversation_id' => $conversation->id,
                    'reason' => 'append_note_not_supported',
                ]);

                return ['status' => 'skipped', 'reason' => 'append_note_not_supported'];
            }

            $note = $this->buildSummaryNote($customer, $conversation);
            $result = $this->hubspot->appendNote($crmContact->external_contact_id, $note);

            if (in_array($result['status'] ?? null, ['success', 'skipped'], true)) {
                if (($result['status'] ?? null) === 'success') {
                    Log::info('[CrmSync] syncConversationSummary success', [
                        'customer_id' => $customer->id,
                        'conversation_id' => $conversation->id,
                    ]);
                } else {
                    Log::info('[CrmSync] syncConversationSummary skipped', [
                        'customer_id' => $customer->id,
                        'conversation_id' => $conversation->id,
                        'reason' => $result['reason'] ?? 'unknown',
                    ]);
                }

                return ['status' => $result['status']];
            }

            Log::warning('[CrmSync] syncConversationSummary note failed', [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'result' => $result,
            ]);

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncConversationSummary exception', [
                'customer_id' => $customer->id,
                'conversation_id' => $conversation->id,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Append decision snapshot AI sebagai note ke CRM contact.
     *
     * @return array{status: string, reason?: string, error?: string}
     */
    public function appendConversationDecisionNote(
        Customer $customer,
        string $note,
        array $decisionTrace = [],
    ): array {
        try {
            if (trim($note) === '') {
                Log::info('[CrmSync] appendConversationDecisionNote result', [
                    'customer_id' => $customer->id,
                    'status' => 'skipped',
                    'reason' => 'empty_note',
                    'trace_id' => $decisionTrace['trace_id'] ?? null,
                ]);

                return ['status' => 'skipped', 'reason' => 'empty_note'];
            }

            $crmContact = CrmContact::where('customer_id', $customer->id)->first();

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                Log::info('[CrmSync] appendConversationDecisionNote result', [
                    'customer_id' => $customer->id,
                    'status' => 'skipped',
                    'reason' => 'no_crm_contact',
                    'trace_id' => $decisionTrace['trace_id'] ?? null,
                ]);

                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            if (! method_exists($this->hubspot, 'appendNote')) {
                Log::info('[CrmSync] appendConversationDecisionNote result', [
                    'customer_id' => $customer->id,
                    'status' => 'skipped',
                    'reason' => 'append_note_not_supported',
                    'trace_id' => $decisionTrace['trace_id'] ?? null,
                ]);

                return ['status' => 'skipped', 'reason' => 'append_note_not_supported'];
            }

            $result = $this->hubspot->appendNote($crmContact->external_contact_id, $note);

            Log::info('[CrmSync] appendConversationDecisionNote result', [
                'customer_id' => $customer->id,
                'status' => $result['status'] ?? null,
                'trace_id' => $decisionTrace['trace_id'] ?? null,
                'final_decision' => $decisionTrace['outcome']['final_decision'] ?? null,
                'used_crm_facts' => $decisionTrace['outcome']['used_crm_facts'] ?? [],
            ]);

            if (in_array($result['status'] ?? null, ['success', 'skipped'], true)) {
                return ['status' => $result['status']];
            }

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] appendConversationDecisionNote exception', [
                'customer_id' => $customer->id,
                'trace_id' => $decisionTrace['trace_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Build the HubSpot property map for a customer contact.
     *
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
        ], static fn ($value) => $value !== null && $value !== '');
    }

    private function buildSummaryNote(Customer $customer, Conversation $conversation): string
    {
        return implode("\n", array_filter([
            '=== Ringkasan Percakapan ===',
            'Pelanggan : ' . ($customer->name ?? 'Tidak diketahui'),
            'Nomor     : ' . $customer->phone_e164,
            'Percakapan: #' . $conversation->id,
            'Waktu     : ' . ($conversation->last_message_at?->format('d M Y H:i') . ' WIB'),
            '',
            $conversation->summary,
        ]));
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
