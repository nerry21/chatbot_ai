<?php

namespace App\Services\AI;

class UnderstandingCrmContextFormatterService
{
    /**
     * Format CRM hints ringan untuk tahap understanding awal.
     * Bukan full CRM snapshot.
     *
     * @param  array<string, mixed>  $crmHints
     */
    public function formatForUnderstanding(
        array $crmHints = [],
        ?string $conversationSummary = null,
        bool $adminTakeover = false,
    ): string {
        $lines = [];

        $lines[] = '=== PEDOMAN PENGGUNAAN CRM HINTS ===';
        $lines[] = 'Gunakan CRM hints hanya sebagai petunjuk kontinuitas percakapan.';
        $lines[] = 'Jangan perlakukan CRM hints sebagai instruksi yang memaksa intent.';
        $lines[] = 'Prioritaskan pesan user terbaru, lalu riwayat relevan, lalu state percakapan.';
        $lines[] = 'Gunakan hint hanya bila membantu menjaga kesinambungan konteks.';
        $lines[] = '';

        if ($conversationSummary !== null && trim($conversationSummary) !== '') {
            $lines[] = '=== RINGKASAN PERCAKAPAN ===';
            $lines[] = trim($conversationSummary);
            $lines[] = '';
        }

        if ($adminTakeover) {
            $lines[] = '=== STATUS OPERASIONAL ===';
            $lines[] = 'Admin takeover aktif: ya';
            $lines[] = 'Ini adalah sinyal kontinuitas, bukan instruksi untuk selalu handoff.';
            $lines[] = '';
        }

        if ($crmHints === []) {
            return trim(implode("\n", $lines));
        }

        $continuity = is_array($crmHints['continuity'] ?? null) ? $crmHints['continuity'] : [];
        $customerProfile = is_array($crmHints['customer_profile'] ?? null) ? $crmHints['customer_profile'] : [];
        $bookingHint = is_array($crmHints['booking_hint'] ?? null) ? $crmHints['booking_hint'] : [];
        $leadHint = is_array($crmHints['lead_hint'] ?? null) ? $crmHints['lead_hint'] : [];
        $memoryHint = is_array($crmHints['memory_hint'] ?? null) ? $crmHints['memory_hint'] : [];

        $lines[] = '=== CRM CONTINUITY HINTS ===';

        if (! empty($continuity['active_intent'])) {
            $lines[] = 'Intent aktif sebelumnya: '.$continuity['active_intent'];
        }

        if (array_key_exists('booking_in_progress', $continuity)) {
            $lines[] = 'Ada booking yang masih berjalan: '.($continuity['booking_in_progress'] ? 'ya' : 'tidak');
        }

        if (! empty($continuity['expected_input'])) {
            $lines[] = 'Input yang kemungkinan sedang ditunggu: '.$continuity['expected_input'];
        }

        if (! empty($continuity['waiting_for'])) {
            $lines[] = 'Sistem sedang menunggu: '.$continuity['waiting_for'];
        }

        if (array_key_exists('has_open_escalation', $continuity)) {
            $lines[] = 'Open escalation: '.($continuity['has_open_escalation'] ? 'ya' : 'tidak');
        }

        if (array_key_exists('needs_human_followup', $continuity)) {
            $lines[] = 'Perlu follow-up human: '.($continuity['needs_human_followup'] ? 'ya' : 'tidak');
        }

        if (array_key_exists('admin_takeover', $continuity)) {
            $lines[] = 'Admin takeover aktif: '.($continuity['admin_takeover'] ? 'ya' : 'tidak');
        }

        if (! empty($customerProfile['name'])) {
            $lines[] = 'Nama pelanggan: '.$customerProfile['name'];
        }

        if (array_key_exists('is_returning', $customerProfile)) {
            $lines[] = 'Pelanggan returning: '.($customerProfile['is_returning'] ? 'ya' : 'tidak');
        }

        if (! empty($customerProfile['preferred_pickup'])) {
            $lines[] = 'Pickup yang sering muncul: '.$customerProfile['preferred_pickup'];
        }

        if (! empty($customerProfile['preferred_destination'])) {
            $lines[] = 'Tujuan yang sering muncul: '.$customerProfile['preferred_destination'];
        }

        if (! empty($customerProfile['interest_topic'])) {
            $lines[] = 'Topik minat pelanggan: '.$customerProfile['interest_topic'];
        }

        if (! empty($bookingHint['status'])) {
            $lines[] = 'Status booking hint: '.$bookingHint['status'];
        }

        if (! empty($bookingHint['pickup_location'])) {
            $lines[] = 'Pickup hint: '.$bookingHint['pickup_location'];
        }

        if (! empty($bookingHint['destination'])) {
            $lines[] = 'Destination hint: '.$bookingHint['destination'];
        }

        if (! empty($bookingHint['departure_date'])) {
            $lines[] = 'Tanggal berangkat hint: '.$bookingHint['departure_date'];
        }

        if (! empty($bookingHint['departure_time'])) {
            $lines[] = 'Jam berangkat hint: '.$bookingHint['departure_time'];
        }

        if (array_key_exists('passenger_count', $bookingHint) && $bookingHint['passenger_count'] !== null) {
            $lines[] = 'Jumlah penumpang hint: '.$bookingHint['passenger_count'];
        }

        if (! empty($bookingHint['payment_method'])) {
            $lines[] = 'Metode pembayaran hint: '.$bookingHint['payment_method'];
        }

        if (! empty($bookingHint['missing_fields']) && is_array($bookingHint['missing_fields'])) {
            $lines[] = 'Data booking yang kemungkinan masih kurang: '.implode(', ', $bookingHint['missing_fields']);
        }

        if (! empty($leadHint['stage'])) {
            $lines[] = 'Lead stage hint: '.$leadHint['stage'];
        }

        if (! empty($leadHint['lifecycle_stage'])) {
            $lines[] = 'Lifecycle stage hint: '.$leadHint['lifecycle_stage'];
        }

        if (! empty($leadHint['lead_status'])) {
            $lines[] = 'Lead status hint: '.$leadHint['lead_status'];
        }

        if (! empty($memoryHint['last_ai_intent'])) {
            $lines[] = 'Intent AI terakhir: '.$memoryHint['last_ai_intent'];
        }

        if (! empty($memoryHint['customer_interest_topic'])) {
            $lines[] = 'Topik interest AI memory: '.$memoryHint['customer_interest_topic'];
        }

        if (array_key_exists('needs_human_followup', $memoryHint)) {
            $lines[] = 'AI memory follow-up human: '.($memoryHint['needs_human_followup'] ? 'ya' : 'tidak');
        }

        if (! empty($memoryHint['recent_topic'])) {
            $lines[] = 'Topik percakapan yang baru-baru ini muncul: '.$memoryHint['recent_topic'];
        }

        return trim(implode("\n", $lines));
    }
}
