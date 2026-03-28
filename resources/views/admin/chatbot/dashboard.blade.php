@extends('admin.chatbot.layouts.app')

@section('title', 'Dashboard')
@section('page-subtitle', 'Pusat kontrol operasional chatbot WhatsApp, monitoring AI, dan aktivitas human takeover dalam satu workspace.')

@section('page-actions')
    <div class="hidden items-center gap-2 sm:flex">
        <a href="{{ route('admin.chatbot.live-chats.index') }}" class="inline-flex items-center gap-2 rounded-2xl bg-slate-900 px-4 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
            <x-admin.chatbot.icon name="chat" class="h-4 w-4" />
            Live Chats
        </a>
        <a href="{{ route('admin.chatbot.escalations.index') }}" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
            <x-admin.chatbot.icon name="alert" class="h-4 w-4" />
            Eskalasi
        </a>
    </div>
@endsection

@section('content')
@php
    $healthStatus = $health['status'] ?? 'warning';
    $healthSummary = $health['summary'] ?? [];
    $healthChecks = collect($health['checks'] ?? []);
    $failedQueue = $healthSummary['queue_backlog']['failed'] ?? 0;
    $workspaceInsights = $workspaceInsights ?? [];
    $topAdmins = collect($workspaceInsights['top_admins'] ?? []);
    $conversationByStatus = $workspaceInsights['conversation_by_status'] ?? [];
    $escalationsToday = $workspaceInsights['escalations_today'] ?? [];
    $bookingConversion = $workspaceInsights['booking_conversion'] ?? [];
    $failedMessageInsight = $workspaceInsights['failed_message_insight'] ?? [];
    $recentAdminInterventions = collect($workspaceInsights['recent_admin_interventions'] ?? []);
    $maxTopAdminActions = max(1, (int) $topAdmins->max('actions'));
    $maxConversationBucket = max(1, (int) max($conversationByStatus ?: [0]));

    $heroActions = [
        ['label' => 'Buka Live Chats', 'href' => route('admin.chatbot.live-chats.index'), 'icon' => 'chat'],
        ['label' => 'Lihat Customers', 'href' => route('admin.chatbot.customers.index'), 'icon' => 'users'],
        ['label' => 'Pantau Eskalasi', 'href' => route('admin.chatbot.escalations.index'), 'icon' => 'alert'],
        ['label' => 'Knowledge Base', 'href' => route('admin.chatbot.knowledge.index'), 'icon' => 'book'],
    ];

    $statCards = [
        ['label' => 'Total Customers', 'value' => $stats['total_customers'] ?? 0, 'hint' => 'Seluruh kontak customer yang sudah masuk ke CRM chatbot.', 'icon' => 'users', 'tone' => 'indigo', 'href' => route('admin.chatbot.customers.index')],
        ['label' => 'Total Conversations', 'value' => $stats['total_conversations'] ?? 0, 'hint' => 'Semua thread WhatsApp yang pernah diproses.', 'icon' => 'chat', 'tone' => 'slate', 'href' => route('admin.chatbot.live-chats.index')],
        ['label' => 'Active Conversations', 'value' => $stats['active_conversations'] ?? 0, 'hint' => 'Percakapan yang masih aktif dan belum selesai.', 'icon' => 'activity', 'tone' => 'green', 'href' => route('admin.chatbot.live-chats.index')],
        ['label' => 'Human Takeover Active', 'value' => $stats['human_takeover_active'] ?? 0, 'hint' => 'Chat yang sedang dikendalikan admin.', 'icon' => 'shield', 'tone' => 'amber', 'href' => route('admin.chatbot.live-chats.index')],
        ['label' => 'Open Escalations', 'value' => $stats['open_escalations'] ?? 0, 'hint' => 'Kasus yang butuh respons manual lebih lanjut.', 'icon' => 'alert', 'tone' => 'red', 'href' => route('admin.chatbot.escalations.index')],
        ['label' => 'Total Booking Leads', 'value' => $stats['total_bookings'] ?? 0, 'hint' => 'Permintaan booking yang sedang atau pernah diproses.', 'icon' => 'briefcase', 'tone' => 'blue', 'href' => route('admin.chatbot.bookings.index')],
        ['label' => 'Failed Outbound', 'value' => $stats['failed_outbound_messages'] ?? 0, 'hint' => 'Pesan keluar yang gagal dan perlu perhatian.', 'icon' => 'alert', 'tone' => 'purple', 'href' => route('admin.chatbot.live-chats.index')],
        ['label' => 'AI Logs Today', 'value' => $stats['ai_logs_today'] ?? 0, 'hint' => 'Aktivitas reasoning dan composer hari ini.', 'icon' => 'sparkles', 'tone' => 'slate', 'href' => route('admin.chatbot.ai-logs.index')],
    ];
@endphp

<div class="space-y-8">
    <section class="console-fade-up grid gap-6 xl:grid-cols-[1.4fr_0.9fr]">
        <x-admin.chatbot.panel class="overflow-hidden border-0 bg-gradient-to-br from-slate-950 via-slate-900 to-indigo-950 text-white shadow-[0_30px_80px_-35px_rgba(15,23,42,0.85)]" padding="lg">
            <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(129,140,248,0.25),transparent_35%),radial-gradient(circle_at_bottom_left,rgba(56,189,248,0.12),transparent_30%)]"></div>
            <div class="relative">
                <div class="inline-flex items-center gap-2 rounded-full border border-white/15 bg-white/5 px-3 py-1 text-xs font-medium text-white/80">
                    <span class="h-2 w-2 rounded-full bg-emerald-400"></span>
                    Chatbot Operations Center
                </div>
                <h2 class="mt-5 max-w-3xl text-3xl font-semibold tracking-tight text-white sm:text-4xl">
                    Kelola percakapan, takeover admin, kualitas AI, dan pipeline booking dari satu console.
                </h2>
                <p class="mt-4 max-w-2xl text-sm leading-7 text-slate-300 sm:text-base">
                    Dashboard ini memakai data operasional nyata dari WhatsApp, AI logs, escalation, booking requests, dan message delivery untuk memantau performa chatbot tanpa mengganggu flow existing.
                </p>

                <div class="mt-8 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    @foreach ($heroActions as $action)
                        <a href="{{ $action['href'] }}" class="group rounded-[24px] border border-white/10 bg-white/5 px-4 py-4 transition hover:border-white/20 hover:bg-white/10">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-semibold text-white">{{ $action['label'] }}</div>
                                    <div class="mt-1 text-xs text-slate-400">Buka modul</div>
                                </div>
                                <div class="rounded-2xl bg-white/10 p-2 text-white/90 transition group-hover:bg-white/15">
                                    <x-admin.chatbot.icon :name="$action['icon']" class="h-4 w-4" />
                                </div>
                            </div>
                        </a>
                    @endforeach
                </div>
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="System Health" description="Ringkasan stabilitas sistem pengiriman, escalation, queue, dan status konfigurasi AI.">
            <x-slot:actions>
                <x-admin.chatbot.status-badge :value="$healthStatus" />
            </x-slot:actions>

            <div class="grid grid-cols-2 gap-3">
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Failed 24h</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($healthSummary['failed_messages_24h'] ?? 0) }}</div>
                </div>
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Pending Stale</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($healthSummary['pending_messages_stale'] ?? 0) }}</div>
                </div>
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Active Takeover</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($healthSummary['active_takeovers'] ?? 0) }}</div>
                </div>
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Failed Jobs</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($failedQueue) }}</div>
                </div>
            </div>

            @if ($healthChecks->isNotEmpty())
                <div class="mt-5 space-y-3">
                    @foreach ($healthChecks->take(4) as $check)
                        <div class="flex items-start justify-between gap-4 rounded-[22px] border border-slate-100 bg-white px-4 py-3">
                            <div>
                                <div class="text-sm font-semibold text-slate-800">{{ str_replace('_', ' ', $check['key']) }}</div>
                                <p class="mt-1 text-sm leading-6 text-slate-500">{{ $check['message'] }}</p>
                            </div>
                            <x-admin.chatbot.status-badge :value="$check['status']" size="sm" />
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>
    </section>

    <section class="console-fade-up-delay">
        <x-admin.chatbot.section-heading
            kicker="Overview"
            title="Ringkasan operasional utama"
            description="Seluruh kartu ringkasan di bawah membaca data aktual dari customers, conversations, escalation, booking requests, delivery status, dan AI logs."
        />

        <div class="mt-5 grid gap-4 sm:grid-cols-2 2xl:grid-cols-4">
            @foreach ($statCards as $card)
                <x-admin.chatbot.stat-card
                    :label="$card['label']"
                    :value="$card['value']"
                    :hint="$card['hint']"
                    :icon="$card['icon']"
                    :tone="$card['tone']"
                    :href="$card['href']"
                />
            @endforeach
        </div>
    </section>

    <section class="grid gap-6 xl:grid-cols-2 2xl:grid-cols-4">
        <x-admin.chatbot.panel title="Top Admin / Agent Aktif" description="Berdasarkan aksi takeover, release, reply manual, close, reopen, dan escalation dalam 30 hari terakhir.">
            @if ($topAdmins->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Belum ada data admin aktif"
                    description="Aktivitas admin akan tampil di sini setelah console mulai digunakan rutin."
                    icon="users"
                />
            @else
                <div class="space-y-3">
                    @foreach ($topAdmins as $admin)
                        @php
                            $width = min(100, (int) round(($admin['actions'] / $maxTopAdminActions) * 100));
                        @endphp
                        <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-center justify-between gap-4">
                                <div class="text-sm font-semibold text-slate-900">{{ $admin['name'] }}</div>
                                <div class="text-sm font-medium text-slate-500">{{ number_format($admin['actions']) }} aksi</div>
                            </div>
                            <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-200">
                                <div class="h-full rounded-full bg-slate-900 transition-all duration-500" style="width: {{ $width }}%"></div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Conversation by Status" description="Distribusi mode operasional conversation saat ini.">
            <div class="space-y-3">
                @foreach ([
                    ['label' => 'Bot Active', 'key' => 'bot_active', 'palette' => 'green'],
                    ['label' => 'Human Takeover', 'key' => 'human_takeover', 'palette' => 'orange'],
                    ['label' => 'Escalated', 'key' => 'escalated', 'palette' => 'red'],
                    ['label' => 'Closed', 'key' => 'closed', 'palette' => 'slate'],
                ] as $row)
                    @php
                        $value = (int) ($conversationByStatus[$row['key']] ?? 0);
                        $width = min(100, (int) round(($value / $maxConversationBucket) * 100));
                    @endphp
                    <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                        <div class="flex items-center justify-between gap-4">
                            <x-admin.chatbot.status-badge :value="$row['label']" :palette="$row['palette']" size="sm" />
                            <div class="text-lg font-semibold text-slate-900">{{ number_format($value) }}</div>
                        </div>
                        <div class="mt-3 h-2.5 overflow-hidden rounded-full bg-slate-200">
                            <div class="h-full rounded-full transition-all duration-500 {{ $row['palette'] === 'green' ? 'bg-emerald-500' : ($row['palette'] === 'orange' ? 'bg-orange-500' : ($row['palette'] === 'red' ? 'bg-red-500' : 'bg-slate-500')) }}" style="width: {{ $width }}%"></div>
                        </div>
                    </div>
                @endforeach
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Today Escalations" description="Ringkasan escalation yang dibuat hari ini.">
            <div class="grid grid-cols-3 gap-3">
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Total</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format((int) ($escalationsToday['total'] ?? 0)) }}</div>
                </div>
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Open</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format((int) ($escalationsToday['open'] ?? 0)) }}</div>
                </div>
                <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                    <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Urgent</div>
                    <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format((int) ($escalationsToday['urgent'] ?? 0)) }}</div>
                </div>
            </div>

            <div class="mt-4 rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                <div class="text-sm font-semibold text-slate-900">Failed Message Insight</div>
                <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Failed</div>
                        <div class="mt-2 text-xl font-semibold text-slate-900">{{ number_format((int) ($failedMessageInsight['failed_24h'] ?? 0)) }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Skipped</div>
                        <div class="mt-2 text-xl font-semibold text-slate-900">{{ number_format((int) ($failedMessageInsight['skipped_24h'] ?? 0)) }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Stale Pending</div>
                        <div class="mt-2 text-xl font-semibold text-slate-900">{{ number_format((int) ($failedMessageInsight['pending_stale'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Booking Conversion" description="Rasio sederhana dari total lead ke booking yang benar-benar terkonfirmasi.">
            <div class="rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-5">
                <div class="flex items-end justify-between gap-4">
                    <div>
                        <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Conversion Rate</div>
                        <div class="mt-2 text-3xl font-semibold text-slate-900">{{ number_format((float) ($bookingConversion['conversion_rate'] ?? 0), 1) }}%</div>
                    </div>
                    <x-admin.chatbot.status-badge :value="((int) ($bookingConversion['converted'] ?? 0)).' converted'" palette="green" />
                </div>
                <div class="mt-4 h-2.5 overflow-hidden rounded-full bg-slate-200">
                    <div class="h-full rounded-full bg-emerald-500 transition-all duration-500" style="width: {{ min(100, (float) ($bookingConversion['conversion_rate'] ?? 0)) }}%"></div>
                </div>
                <div class="mt-4 grid grid-cols-3 gap-3 text-center">
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Total Leads</div>
                        <div class="mt-2 text-xl font-semibold text-slate-900">{{ number_format((int) ($bookingConversion['total'] ?? 0)) }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Awaiting</div>
                        <div class="mt-2 text-xl font-semibold text-slate-900">{{ number_format((int) ($bookingConversion['awaiting_confirmation'] ?? 0)) }}</div>
                    </div>
                    <div>
                        <div class="text-xs uppercase tracking-[0.16em] text-slate-400">Draft</div>
                        <div class="mt-2 text-xl font-semibold text-slate-900">{{ number_format((int) ($bookingConversion['draft'] ?? 0)) }}</div>
                    </div>
                </div>
            </div>
        </x-admin.chatbot.panel>
    </section>

    <section class="grid gap-6 xl:grid-cols-[1.2fr_0.8fr]">
        <x-admin.chatbot.panel title="AI Quality & Reliability Summary" description="Status agregat kualitas reasoning, fallback rate, knowledge hits, dan sinyal anomali sistem.">
            <div class="grid gap-4 md:grid-cols-2">
                <div class="rounded-[24px] border border-slate-100 bg-slate-50 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">AI Quality</div>
                            <div class="mt-1 text-sm text-slate-500">Kualitas hasil AI dari log yang tersimpan.</div>
                        </div>
                        <x-admin.chatbot.status-badge :value="$aiQuality['status'] ?? 'n/a'" />
                    </div>

                    @if ($aiQuality)
                        <div class="mt-5 grid grid-cols-2 gap-3">
                            <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Quality Rate</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format((float) ($aiQuality['quality_rate'] ?? 0), 1) }}%</div>
                            </div>
                            <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Window</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-900">{{ $aiQuality['window_days'] ?? 0 }} hari</div>
                            </div>
                            <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Fallback</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($aiQuality['summary']['reply_fallbacks'] ?? 0) }}</div>
                            </div>
                            <div class="rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Low Confidence</div>
                                <div class="mt-2 text-2xl font-semibold text-slate-900">{{ number_format($aiQuality['summary']['low_confidence_intents'] ?? 0) }}</div>
                            </div>
                        </div>
                    @else
                        <div class="mt-5">
                            <x-admin.chatbot.empty-state
                                title="Quality tracking belum tersedia"
                                description="Dashboard tetap aman dirender walau ringkasan kualitas AI belum aktif atau belum ada data."
                                icon="sparkles"
                            />
                        </div>
                    @endif
                </div>

                <div class="rounded-[24px] border border-slate-100 bg-slate-50 p-5">
                    <div class="flex items-center justify-between gap-3">
                        <div>
                            <div class="text-sm font-semibold text-slate-900">Operational Snapshot</div>
                            <div class="mt-1 text-sm text-slate-500">Sinyal cepat untuk backlog dan handoff.</div>
                        </div>
                        <x-admin.chatbot.icon name="activity" class="h-5 w-5 text-slate-400" />
                    </div>

                    <div class="mt-5 space-y-3">
                        <div class="flex items-center justify-between rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                            <span class="text-sm text-slate-600">Pending handoffs</span>
                            <span class="text-lg font-semibold text-slate-900">{{ number_format($stats['pending_handoffs'] ?? 0) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                            <span class="text-sm text-slate-600">Unread notifications</span>
                            <span class="text-lg font-semibold text-slate-900">{{ number_format($healthSummary['unread_notifications'] ?? 0) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                            <span class="text-sm text-slate-600">Queue pending</span>
                            <span class="text-lg font-semibold text-slate-900">{{ number_format($healthSummary['queue_backlog']['pending'] ?? 0) }}</span>
                        </div>
                        <div class="flex items-center justify-between rounded-2xl bg-white px-4 py-3 ring-1 ring-slate-100">
                            <span class="text-sm text-slate-600">Open escalations</span>
                            <span class="text-lg font-semibold text-slate-900">{{ number_format($healthSummary['open_escalations'] ?? 0) }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Quick Actions" description="Akses cepat ke area operasional yang paling sering dipakai tim admin.">
            <div class="grid gap-3">
                @foreach ($heroActions as $action)
                    <a href="{{ $action['href'] }}" class="group flex items-center justify-between rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4 transition hover:border-slate-200 hover:bg-white">
                        <div class="flex items-center gap-3">
                            <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white text-slate-700 ring-1 ring-slate-100">
                                <x-admin.chatbot.icon :name="$action['icon']" class="h-5 w-5" />
                            </div>
                            <div>
                                <div class="text-sm font-semibold text-slate-900">{{ $action['label'] }}</div>
                                <div class="text-xs text-slate-500">Buka modul operasional</div>
                            </div>
                        </div>
                        <x-admin.chatbot.icon name="arrow-up-right" class="h-4 w-4 text-slate-400 transition group-hover:text-slate-700" />
                    </a>
                @endforeach
            </div>
        </x-admin.chatbot.panel>
    </section>

    <section>
        <x-admin.chatbot.panel title="Recent Admin Interventions" description="Jejak aksi admin terbaru dari conversation console.">
            @if ($recentAdminInterventions->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Belum ada intervensi admin"
                    description="Takeover, release, note internal, close/reopen, dan reply manual akan muncul di sini."
                    icon="activity"
                />
            @else
                <div class="grid gap-3 lg:grid-cols-2">
                    @foreach ($recentAdminInterventions as $entry)
                        <div class="rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900">{{ $entry->message ?? str_replace('_', ' ', $entry->action_type) }}</div>
                                    <div class="mt-2 flex flex-wrap items-center gap-2">
                                        <x-admin.chatbot.status-badge :value="$entry->action_type" size="sm" />
                                        @if ($entry->actor?->name)
                                            <x-admin.chatbot.status-badge :value="$entry->actor->name" palette="slate" size="sm" />
                                        @endif
                                        @if ($entry->conversation?->customer?->name)
                                            <x-admin.chatbot.status-badge :value="$entry->conversation->customer->name" palette="indigo" size="sm" />
                                        @endif
                                    </div>
                                    @if (filled(data_get($entry->context, 'reason')))
                                        <div class="mt-2 text-xs text-slate-500">Reason: {{ data_get($entry->context, 'reason') }}</div>
                                    @elseif (filled(data_get($entry->context, 'tag')))
                                        <div class="mt-2 text-xs text-slate-500">Tag: {{ data_get($entry->context, 'tag') }}</div>
                                    @endif
                                </div>
                                <div class="text-right text-xs text-slate-400">
                                    <div>{{ $entry->created_at?->format('d M H:i') ?? '-' }}</div>
                                    <div class="mt-1">{{ $entry->created_at?->diffForHumans() ?? '-' }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>
    </section>

    <section class="grid gap-6 xl:grid-cols-3">
        <x-admin.chatbot.panel title="Recent Active Conversations" description="Percakapan terbaru yang masih bergerak dan paling relevan dipantau.">
            @if ($recentConversations->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Belum ada percakapan"
                    description="Data percakapan akan muncul di sini setelah webhook WhatsApp memproses pesan customer."
                    icon="chat"
                />
            @else
                <div class="space-y-3">
                    @foreach ($recentConversations as $conversation)
                        <a href="{{ route('admin.chatbot.live-chats.show', $conversation) }}" class="console-card-lift flex items-start justify-between gap-4 rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-4 transition hover:border-slate-200 hover:bg-white">
                            <div class="min-w-0">
                                <div class="truncate text-sm font-semibold text-slate-900">{{ $conversation->customer?->name ?? $conversation->customer?->phone_e164 ?? 'Unknown customer' }}</div>
                                <div class="mt-1 text-sm text-slate-500">{{ $conversation->customer?->phone_e164 ?? '-' }}</div>
                                <div class="mt-3 flex flex-wrap items-center gap-2">
                                    <x-admin.chatbot.status-badge :value="is_string($conversation->status) ? $conversation->status : $conversation->status?->value" size="sm" />
                                    @if ($conversation->needs_human)
                                        <x-admin.chatbot.status-badge value="needs_human" palette="red" size="sm" />
                                    @endif
                                </div>
                            </div>
                            <div class="shrink-0 text-right">
                                <div class="text-xs text-slate-400">{{ $conversation->last_message_at?->diffForHumans() ?? '-' }}</div>
                                <div class="mt-3 text-xs font-medium text-slate-600">{{ $conversation->current_intent ?? 'no_intent' }}</div>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Recent Escalations" description="Kasus terbaru yang membutuhkan human handling atau follow-up admin.">
            @if ($recentEscalations->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Tidak ada escalation"
                    description="Saat ada case yang tidak aman atau butuh admin, escalation akan muncul di panel ini."
                    icon="alert"
                />
            @else
                <div class="space-y-3">
                    @foreach ($recentEscalations as $escalation)
                        <div class="rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-slate-900">{{ $escalation->conversation?->customer?->name ?? $escalation->conversation?->customer?->phone_e164 ?? 'Unknown customer' }}</div>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ \Illuminate\Support\Str::limit($escalation->reason ?: ($escalation->summary ?: 'Tidak ada catatan eskalasi.'), 100) }}</p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <x-admin.chatbot.status-badge :value="$escalation->priority" size="sm" />
                                    <x-admin.chatbot.status-badge :value="$escalation->status" size="sm" />
                                </div>
                            </div>

                            <div class="mt-4 flex items-center justify-between">
                                <div class="text-xs text-slate-400">{{ $escalation->created_at?->diffForHumans() ?? '-' }}</div>
                                @if ($escalation->conversation_id)
                                    <a href="{{ route('admin.chatbot.conversations.show', $escalation->conversation_id) }}" class="text-xs font-medium text-slate-700 transition hover:text-slate-900">
                                        Buka percakapan
                                    </a>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Recent Booking Leads" description="Permintaan booking terbaru yang sedang bergerak di pipeline chatbot.">
            @if ($recentBookings->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Belum ada booking lead"
                    description="Saat flow booking berhasil mengumpulkan data customer, lead akan muncul di sini."
                    icon="briefcase"
                />
            @else
                <div class="space-y-3">
                    @foreach ($recentBookings as $booking)
                        <div class="rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="truncate text-sm font-semibold text-slate-900">{{ $booking->customer?->name ?? $booking->passenger_name ?? 'Lead tanpa nama' }}</div>
                                    <div class="mt-1 text-sm text-slate-500">
                                        {{ $booking->pickup_location ?? '-' }} -> {{ $booking->destination ?? '-' }}
                                    </div>
                                </div>
                                <x-admin.chatbot.status-badge :value="is_string($booking->booking_status) ? $booking->booking_status : $booking->booking_status?->value" size="sm" />
                            </div>
                            <div class="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-500">
                                <div>{{ $booking->departure_date?->format('d M Y') ?? '-' }}</div>
                                <div class="text-right">{{ $booking->updated_at?->diffForHumans() ?? '-' }}</div>
                                <div>{{ $booking->passenger_count ?? 0 }} penumpang</div>
                                <div class="text-right">
                                    {{ $booking->price_estimate ? 'Rp ' . number_format((float) $booking->price_estimate, 0, ',', '.') : 'Harga belum ada' }}
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>
    </section>

    <section class="grid gap-6 xl:grid-cols-2">
        <x-admin.chatbot.panel title="Recent AI Anomalies" description="Log AI yang gagal, low confidence, atau jatuh ke fallback.">
            @if ($recentAiIncidents->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Tidak ada AI anomaly"
                    description="Tidak ada incident AI terbaru yang perlu ditindaklanjuti saat ini."
                    icon="sparkles"
                />
            @else
                <div class="space-y-3">
                    @foreach ($recentAiIncidents as $log)
                        <div class="rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900">{{ $log->task_type ?? 'unknown_task' }}</div>
                                    <div class="mt-1 text-xs text-slate-500">
                                        Conversation #{{ $log->conversation_id ?? '-' }}
                                        @if ($log->conversation?->customer?->name)
                                            · {{ $log->conversation->customer->name }}
                                        @endif
                                    </div>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ \Illuminate\Support\Str::limit($log->error_message ?: ($log->quality_label ?: 'Anomali terdeteksi dari AI log.'), 120) }}</p>
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <x-admin.chatbot.status-badge :value="$log->status" size="sm" />
                                    @if ($log->quality_label)
                                        <x-admin.chatbot.status-badge :value="$log->quality_label" size="sm" />
                                    @endif
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Failed / Skipped Outbound Messages" description="Pesan outbound terbaru yang tidak berhasil terkirim ke customer.">
            @if ($recentFailedMessages->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Tidak ada failed outbound"
                    description="Tidak ada pesan keluar yang gagal atau di-skip dalam daftar terbaru."
                    icon="shield"
                />
            @else
                <div class="space-y-3">
                    @foreach ($recentFailedMessages as $message)
                        <div class="rounded-[24px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div class="min-w-0">
                                    <div class="text-sm font-semibold text-slate-900">{{ $message->conversation?->customer?->name ?? $message->conversation?->customer?->phone_e164 ?? 'Unknown customer' }}</div>
                                    <p class="mt-2 text-sm leading-6 text-slate-500">{{ \Illuminate\Support\Str::limit($message->message_text ?: '[non-text]', 120) }}</p>
                                    @if ($message->delivery_error)
                                        <div class="mt-2 text-xs text-red-500">{{ \Illuminate\Support\Str::limit($message->delivery_error, 120) }}</div>
                                    @endif
                                </div>
                                <div class="flex shrink-0 flex-col items-end gap-2">
                                    <x-admin.chatbot.status-badge :value="is_string($message->delivery_status) ? $message->delivery_status : $message->delivery_status?->value" size="sm" />
                                    <div class="text-xs text-slate-400">{{ $message->created_at?->diffForHumans() ?? '-' }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>
    </section>
</div>
@endsection
