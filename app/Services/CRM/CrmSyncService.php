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
                // HubSpot disabled — keep the record locally.
                $crmContact->markLocalOnly();

                return ['status' => 'local_only'];
            }

            $crmContact->markFailed($result['error'] ?? 'unknown');

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncCustomer failed', [
                'customer_id' => $customer->id,
                'error'       => $e->getMessage(),
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
                return ['status' => 'skipped', 'reason' => 'no_summary'];
            }

            $crmContact = CrmContact::where('customer_id', $customer->id)->first();

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            $note   = $this->buildSummaryNote($customer, $conversation);
            $result = $this->hubspot->appendNote($crmContact->external_contact_id, $note);

            if (in_array($result['status'], ['success', 'skipped'], true)) {
                return ['status' => $result['status']];
            }

            Log::warning('[CrmSync] syncConversationSummary note failed', [
                'customer_id'     => $customer->id,
                'conversation_id' => $conversation->id,
                'result'          => $result,
            ]);

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] syncConversationSummary exception', [
                'customer_id'     => $customer->id,
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);

            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    /**
     * Append decision snapshot AI sebagai note ke CRM contact.
     *
     * @return array{status: string, reason?: string, error?: string}
     */
    public function appendConversationDecisionNote(Customer $customer, string $note): array
    {
        try {
            if (trim($note) === '') {
                return ['status' => 'skipped', 'reason' => 'empty_note'];
            }

            $crmContact = CrmContact::where('customer_id', $customer->id)->first();

            if ($crmContact === null || empty($crmContact->external_contact_id)) {
                return ['status' => 'skipped', 'reason' => 'no_crm_contact'];
            }

            $result = $this->hubspot->appendNote($crmContact->external_contact_id, $note);

            if (in_array($result['status'], ['success', 'skipped'], true)) {
                return ['status' => $result['status']];
            }

            return ['status' => 'failed', 'error' => $result['error'] ?? 'unknown'];
        } catch (\Throwable $e) {
            Log::error('[CrmSync] appendConversationDecisionNote exception', [
                'customer_id' => $customer->id,
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
     * @return array<string, mixed>
     */
    private function buildContactPayload(Customer $customer): array
    {
        $payload = ['phone' => $customer->phone_e164];

        if (! empty($customer->name)) {
            $payload['firstname'] = $customer->name;
        }

        if (! empty($customer->email)) {
            $payload['email'] = $customer->email;
        }

        return $payload;
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
}
