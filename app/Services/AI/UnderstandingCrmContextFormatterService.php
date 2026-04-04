<?php

namespace App\Services\AI;

class UnderstandingCrmContextFormatterService
{
    /**
     * @param  array<string, mixed>  $crmContext
     */
    public function formatForUnderstanding(
        array $crmContext = [],
        ?string $conversationSummary = null,
        bool $adminTakeover = false,
    ): string {
        $crm = $crmContext;

        if ($crm === []) {
            $lines = [];

            if ($conversationSummary !== null && trim($conversationSummary) !== '') {
                $lines[] = '=== RINGKASAN PERCAKAPAN BISNIS ===';
                $lines[] = trim($conversationSummary);
                $lines[] = '';
            }

            if ($adminTakeover) {
                $lines[] = '=== STATUS OPERASIONAL ===';
                $lines[] = 'Admin takeover aktif: ya';
                $lines[] = '';
            }

            return trim(implode("\n", $lines));
        }

        $customer = is_array($crm['customer'] ?? null) ? $crm['customer'] : [];
        $hubspot = is_array($crm['hubspot'] ?? null) ? $crm['hubspot'] : [];
        $lead = is_array($crm['lead_pipeline'] ?? null) ? $crm['lead_pipeline'] : [];
        $conversation = is_array($crm['conversation'] ?? null) ? $crm['conversation'] : [];
        $booking = is_array($crm['booking'] ?? null) ? $crm['booking'] : [];
        $escalation = is_array($crm['escalation'] ?? null) ? $crm['escalation'] : [];
        $flags = is_array($crm['business_flags'] ?? null) ? $crm['business_flags'] : [];

        $lines = [];

        $lines[] = '=== HIRARKI KEPUTUSAN WAJIB ===';
        $lines[] = '1. Gunakan fakta CRM, booking, escalation, dan conversation state sebagai sumber kebenaran utama.';
        $lines[] = '2. Gunakan riwayat percakapan hanya untuk melengkapi pemahaman.';
        $lines[] = '3. Gunakan reasoning LLM hanya untuk memahami maksud user, bukan untuk mengarang fakta.';
        $lines[] = '4. Jika CRM menunjukkan konteks aktif, prioritaskan kesinambungan konteks tersebut.';
        $lines[] = '';

        if ($conversationSummary !== null && trim($conversationSummary) !== '') {
            $lines[] = '=== RINGKASAN PERCAKAPAN BISNIS ===';
            $lines[] = trim($conversationSummary);
            $lines[] = '';
        }

        $lines[] = '=== KONTEKS CRM TERPADU (FAKTA BISNIS) ===';

        if (!empty($customer['name'])) {
            $lines[] = 'Nama pelanggan: '.$customer['name'];
        }

        if (!empty($customer['phone_e164'])) {
            $lines[] = 'Nomor pelanggan: '.$customer['phone_e164'];
        }

        if (!empty($customer['tags']) && is_array($customer['tags'])) {
            $lines[] = 'Tag pelanggan: '.implode(', ', $customer['tags']);
        }

        if (array_key_exists('total_bookings', $customer) && $customer['total_bookings'] !== null && $customer['total_bookings'] !== '') {
            $lines[] = 'Total booking pelanggan: '.$customer['total_bookings'];
        }

        if (!empty($hubspot['lifecycle_stage'])) {
            $lines[] = 'Lifecycle HubSpot: '.$hubspot['lifecycle_stage'];
        }

        if (!empty($hubspot['lead_status'])) {
            $lines[] = 'Lead status HubSpot: '.$hubspot['lead_status'];
        }

        if (!empty($hubspot['company'])) {
            $lines[] = 'Perusahaan: '.$hubspot['company'];
        }

        if (!empty($hubspot['source'])) {
            $lines[] = 'Sumber lead CRM: '.$hubspot['source'];
        }

        $hubspotAiMemory = is_array($hubspot['ai_memory'] ?? null) ? $hubspot['ai_memory'] : [];

        if (!empty($hubspotAiMemory['last_ai_intent'])) {
            $lines[] = 'AI memory intent terakhir: '.$hubspotAiMemory['last_ai_intent'];
        }

        if (!empty($hubspotAiMemory['customer_interest_topic'])) {
            $lines[] = 'Topik minat pelanggan CRM: '.$hubspotAiMemory['customer_interest_topic'];
        }

        if (array_key_exists('needs_human_followup', $hubspotAiMemory)) {
            $lines[] = 'Follow-up human dari CRM: '.($hubspotAiMemory['needs_human_followup'] ? 'ya' : 'tidak');
        }

        if (!empty($lead['stage'])) {
            $lines[] = 'Stage pipeline internal: '.$lead['stage'];
        }

        if (!empty($conversation['current_intent'])) {
            $lines[] = 'Intent percakapan sebelumnya: '.$conversation['current_intent'];
        }

        if (!empty($conversation['summary'])) {
            $lines[] = 'Ringkasan percakapan CRM: '.$conversation['summary'];
        }

        if (array_key_exists('needs_human', $conversation)) {
            $lines[] = 'Perlu human follow-up: '.($conversation['needs_human'] ? 'ya' : 'tidak');
        }

        if (!empty($booking['booking_status'])) {
            $lines[] = 'Status booking: '.$booking['booking_status'];
        }

        if (!empty($booking['pickup_location'])) {
            $lines[] = 'Pickup booking: '.$booking['pickup_location'];
        }

        if (!empty($booking['destination'])) {
            $lines[] = 'Tujuan booking: '.$booking['destination'];
        }

        if (!empty($booking['departure_date'])) {
            $lines[] = 'Tanggal keberangkatan: '.$booking['departure_date'];
        }

        if (!empty($booking['departure_time'])) {
            $lines[] = 'Jam keberangkatan: '.$booking['departure_time'];
        }

        if (array_key_exists('ready_for_confirmation', $booking)) {
            $lines[] = 'Siap untuk konfirmasi: '.($booking['ready_for_confirmation'] ? 'ya' : 'tidak');
        }

        if (!empty($booking['missing_fields']) && is_array($booking['missing_fields'])) {
            $lines[] = 'Data booking yang masih kurang: '.implode(', ', $booking['missing_fields']);
        }

        if (($escalation['has_open_escalation'] ?? false) === true) {
            $lines[] = 'Ada escalation terbuka: ya';

            if (!empty($escalation['priority'])) {
                $lines[] = 'Prioritas escalation: '.$escalation['priority'];
            }

            if (!empty($escalation['reason'])) {
                $lines[] = 'Alasan escalation: '.$escalation['reason'];
            }
        } else {
            $lines[] = 'Ada escalation terbuka: tidak';
        }

        $effectiveAdminTakeover = $adminTakeover || (($flags['admin_takeover_active'] ?? false) === true);

        $lines[] = '=== STATUS OPERASIONAL ===';
        $lines[] = 'Admin takeover aktif: '.($effectiveAdminTakeover ? 'ya' : 'tidak');
        $lines[] = 'Bot sedang pause: '.((($flags['bot_paused'] ?? false) === true) ? 'ya' : 'tidak');
        $lines[] = 'Perlu human follow-up: '.((($flags['needs_human_followup'] ?? false) === true) ? 'ya' : 'tidak');

        $lines[] = '';
        $lines[] = '=== CARA MEMAKAI KONTEKS DI ATAS ===';
        $lines[] = '- Jika pesan user tampak ambigu, gunakan konteks CRM aktif untuk memilih intent yang paling nyambung.';
        $lines[] = '- Jika ada booking aktif, prioritaskan interpretasi sebagai kelanjutan booking tersebut bila masuk akal.';
        $lines[] = '- Jika ada escalation atau needs_human_followup, pertimbangkan handoff_recommended=true bila pesan memperkuat kebutuhan tersebut.';
        $lines[] = '- Jangan bertentangan dengan fakta CRM yang sudah ada.';
        $lines[] = '- Jangan mengarang data baru di luar fakta CRM, riwayat, dan pesan user terbaru.';

        return trim(implode("\n", $lines));
    }
}
