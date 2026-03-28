@extends('admin.chatbot.layouts.app')

@section('title', 'Settings')
@section('page-subtitle', 'Snapshot konfigurasi operasional chatbot untuk audit cepat. Tahap ini masih read-only dan aman untuk production.')

@section('content')
@php
    $settingCards = [
        ['label' => 'WhatsApp Sender', 'value' => $settings['whatsapp_enabled'] ?? false, 'hint' => 'Koneksi outbound WhatsApp'],
        ['label' => 'LLM Engine', 'value' => $settings['llm_enabled'] ?? false, 'hint' => 'Understanding dan grounded composer'],
        ['label' => 'Knowledge Base', 'value' => $settings['knowledge_enabled'] ?? false, 'hint' => 'Grounded facts retrieval'],
        ['label' => 'CRM Sync', 'value' => $settings['crm_enabled'] ?? false, 'hint' => 'Sinkronisasi customer/lead'],
        ['label' => 'Chatbot Admin Guard', 'value' => $settings['require_chatbot_admin'] ?? false, 'hint' => 'Proteksi akses console'],
        ['label' => 'Operator Actions', 'value' => $settings['allow_operator_actions'] ?? false, 'hint' => 'Aksi operator di dashboard'],
        ['label' => 'Repair Corrupted State', 'value' => $settings['repair_corrupted_state'] ?? false, 'hint' => 'Self-healing booking state'],
        ['label' => 'Notifications', 'value' => $settings['notifications_enabled'] ?? false, 'hint' => 'Admin notifications pipeline'],
    ];
@endphp

<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Configuration Snapshot"
        title="Chatbot settings overview"
        description="Halaman ini menampilkan snapshot konfigurasi penting agar tim admin cepat melihat guard dan feature flag yang sedang aktif."
    />

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        @foreach ($settingCards as $setting)
            <x-admin.chatbot.panel padding="sm" class="h-full">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <div class="text-sm font-semibold text-slate-900">{{ $setting['label'] }}</div>
                        <div class="mt-1 text-sm text-slate-500">{{ $setting['hint'] }}</div>
                    </div>
                    <x-admin.chatbot.status-badge :value="$setting['value'] ? 'enabled' : 'disabled'" :palette="$setting['value'] ? 'green' : 'slate'" />
                </div>
            </x-admin.chatbot.panel>
        @endforeach
    </div>

    <x-admin.chatbot.panel title="Tahap 1 scope" description="Settings masih placeholder read-only agar aman. Edit form, audit setting changes, dan permission detail bisa ditambahkan pada tahap lanjutan.">
        <div class="grid gap-4 lg:grid-cols-2">
            <div class="rounded-[24px] border border-slate-100 bg-slate-50 p-5">
                <div class="text-sm font-semibold text-slate-900">Yang sudah tersedia</div>
                <ul class="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                    <li>Snapshot flag inti untuk WhatsApp, LLM, knowledge, CRM, notification, dan guard.</li>
                    <li>Route admin console yang konsisten di prefix <code class="rounded bg-white px-2 py-1 text-xs text-slate-700">/admin/chatbot</code>.</li>
                    <li>Layout premium yang siap dipakai ulang untuk modul live chat interaktif berikutnya.</li>
                </ul>
            </div>

            <div class="rounded-[24px] border border-slate-100 bg-slate-50 p-5">
                <div class="text-sm font-semibold text-slate-900">Tahap berikutnya</div>
                <ul class="mt-4 space-y-3 text-sm leading-6 text-slate-600">
                    <li>Form konfigurasi operasional dengan audit trail perubahan setting.</li>
                    <li>Pengaturan polling/realtime hook untuk live chat panel.</li>
                    <li>Permission split lebih detail antara admin penuh dan operator.</li>
                </ul>
            </div>
        </div>
    </x-admin.chatbot.panel>
</div>
@endsection
