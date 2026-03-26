@extends('admin.chatbot.layouts.app')
@section('title', 'Dashboard')

@section('content')

{{-- ── Stat Cards ────────────────────────────────────────────────────────── --}}
<div class="grid grid-cols-2 lg:grid-cols-5 gap-4 mb-8">

    @php
        $cards = [
            ['label' => 'Total Percakapan',   'value' => $stats['total_conversations'],   'color' => 'indigo'],
            ['label' => 'Aktif',              'value' => $stats['active_conversations'],   'color' => 'green'],
            ['label' => 'Butuh Admin',        'value' => $stats['needs_human'],            'color' => 'orange'],
            ['label' => 'Total Customer',     'value' => $stats['total_customers'],        'color' => 'blue'],
            ['label' => 'Total Booking',      'value' => $stats['total_bookings'],         'color' => 'purple'],
            ['label' => 'Menunggu Konfirmasi','value' => $stats['awaiting_confirmation'],  'color' => 'yellow'],
            ['label' => 'Booking Confirmed',  'value' => $stats['confirmed_bookings'],     'color' => 'teal'],
            ['label' => 'Eskalasi Open',      'value' => $stats['open_escalations'],       'color' => 'red'],
            ['label' => 'Notif Belum Dibaca', 'value' => $stats['unread_notifications'],   'color' => 'pink'],
            ['label' => 'AI Logs Hari Ini',   'value' => $stats['ai_logs_today'],          'color' => 'gray'],
        ];
        $colorMap = [
            'indigo' => 'bg-indigo-50 text-indigo-700 border-indigo-200',
            'green'  => 'bg-green-50  text-green-700  border-green-200',
            'orange' => 'bg-orange-50 text-orange-700 border-orange-200',
            'blue'   => 'bg-blue-50   text-blue-700   border-blue-200',
            'purple' => 'bg-purple-50 text-purple-700 border-purple-200',
            'yellow' => 'bg-yellow-50 text-yellow-700 border-yellow-200',
            'teal'   => 'bg-teal-50   text-teal-700   border-teal-200',
            'red'    => 'bg-red-50    text-red-700    border-red-200',
            'pink'   => 'bg-pink-50   text-pink-700   border-pink-200',
            'gray'   => 'bg-gray-50   text-gray-700   border-gray-200',
        ];
    @endphp

    @foreach ($cards as $card)
        <div class="bg-white rounded-lg border {{ $colorMap[$card['color']] }} p-4">
            <div class="text-2xl font-bold">{{ number_format($card['value']) }}</div>
            <div class="text-xs mt-0.5 opacity-80">{{ $card['label'] }}</div>
        </div>
    @endforeach

</div>

{{-- ── Reliability Summary (Tahap 9) ───────────────────────────────────── --}}
<div class="mb-6">
    @php
        $rStatus = $reliability['status'] ?? 'ok';
        $rColorMap = [
            'ok'       => ['bg' => 'bg-green-50',  'border' => 'border-green-200',  'text' => 'text-green-700',  'badge' => 'bg-green-100 text-green-700',  'icon' => '✓'],
            'warning'  => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-300', 'text' => 'text-yellow-700', 'badge' => 'bg-yellow-100 text-yellow-700', 'icon' => '⚠'],
            'critical' => ['bg' => 'bg-red-50',    'border' => 'border-red-300',    'text' => 'text-red-700',    'badge' => 'bg-red-100 text-red-700',      'icon' => '✗'],
        ];
        $rc = $rColorMap[$rStatus] ?? $rColorMap['ok'];
    @endphp

    <div class="{{ $rc['bg'] }} {{ $rc['border'] }} border rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-sm text-gray-700">⚙ Reliability</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $rc['badge'] }}">
                    {{ $rc['icon'] }} {{ strtoupper($rStatus) }}
                </span>
            </div>
            <span class="text-xs text-gray-400">Outbound 24h</span>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $reliabilityCards = [
                    ['label' => 'Gagal 24h',        'value' => $reliability['failed_messages_24h'],  'alert' => $reliability['failed_messages_24h'] > 0],
                    ['label' => 'Pending Stale',    'value' => $reliability['pending_stale'],         'alert' => $reliability['pending_stale'] > 0],
                    ['label' => 'Terkirim 24h',     'value' => $reliability['sent_messages_24h'],     'alert' => false],
                    ['label' => 'Takeover Aktif',   'value' => $reliability['active_takeovers'],      'alert' => $reliability['active_takeovers'] > 0],
                    ['label' => 'Eskalasi Open',    'value' => $reliability['open_escalations'],      'alert' => $reliability['open_escalations'] > 0],
                    ['label' => 'Notif Belum Baca', 'value' => $reliability['unread_notifications'],  'alert' => $reliability['unread_notifications'] > 0],
                ];
            @endphp

            @foreach ($reliabilityCards as $rc2)
                <div class="bg-white rounded border border-gray-100 px-3 py-2 text-center">
                    <div class="text-lg font-bold {{ $rc2['alert'] ? 'text-orange-600' : 'text-gray-800' }}">
                        {{ number_format($rc2['value']) }}
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">{{ $rc2['label'] }}</div>
                </div>
            @endforeach
        </div>

        @if ($rStatus !== 'ok')
            <div class="mt-3 text-xs {{ $rc['text'] }}">
                @if ($reliability['failed_messages_24h'] > 0)
                    <span class="mr-3">• {{ $reliability['failed_messages_24h'] }} pesan outbound gagal dalam 24 jam terakhir.</span>
                @endif
                @if ($reliability['pending_stale'] > 0)
                    <span class="mr-3">• {{ $reliability['pending_stale'] }} pesan terjebak di status pending &gt;10 menit.</span>
                @endif
            </div>
        @endif
    </div>
</div>

{{-- ── AI Quality Summary (Tahap 10) ────────────────────────────────────── --}}
@if (!empty($aiQuality))
<div class="mb-6">
    @php
        $aqStatus = $aiQuality['status'] ?? 'good';
        $aqColorMap = [
            'good'    => ['bg' => 'bg-teal-50',   'border' => 'border-teal-200',  'text' => 'text-teal-700',  'badge' => 'bg-teal-100 text-teal-700',  'icon' => '✓'],
            'warning' => ['bg' => 'bg-yellow-50', 'border' => 'border-yellow-300','text' => 'text-yellow-700','badge' => 'bg-yellow-100 text-yellow-700','icon' => '⚠'],
            'poor'    => ['bg' => 'bg-red-50',    'border' => 'border-red-300',   'text' => 'text-red-700',   'badge' => 'bg-red-100 text-red-700',    'icon' => '✗'],
        ];
        $aqc = $aqColorMap[$aqStatus] ?? $aqColorMap['good'];
        $summary = $aiQuality['summary'] ?? [];
        $windowDays = $aiQuality['window_days'] ?? 7;
        $qualityRate = $aiQuality['quality_rate'] ?? 100.0;
    @endphp

    <div class="{{ $aqc['bg'] }} {{ $aqc['border'] }} border rounded-lg p-4">
        <div class="flex items-center justify-between mb-3">
            <div class="flex items-center gap-2">
                <span class="font-semibold text-sm text-gray-700">🤖 Kualitas AI</span>
                <span class="text-xs font-semibold px-2 py-0.5 rounded-full {{ $aqc['badge'] }}">
                    {{ $aqc['icon'] }} {{ strtoupper($aqStatus) }}
                </span>
                <span class="text-xs text-gray-400">{{ $qualityRate }}% reply berkualitas</span>
            </div>
            <div class="flex items-center gap-3">
                <span class="text-xs text-gray-400">{{ $windowDays }} hari terakhir (dari {{ $aiQuality['from'] ?? '-' }})</span>
                <a href="{{ route('admin.chatbot.ai-logs.index') }}" class="text-xs text-indigo-600 hover:underline">Lihat logs →</a>
            </div>
        </div>

        <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
            @php
                $aiCards = [
                    ['label' => 'Total Logs',      'value' => $summary['total_logs'] ?? 0,              'alert' => false],
                    ['label' => 'Gagal',           'value' => $summary['failed_logs'] ?? 0,             'alert' => ($summary['failed_logs'] ?? 0) > 0],
                    ['label' => 'Low Confidence',  'value' => $summary['low_confidence_intents'] ?? 0,  'alert' => ($summary['low_confidence_intents'] ?? 0) > 0],
                    ['label' => 'Reply Fallback',  'value' => $summary['reply_fallbacks'] ?? 0,         'alert' => ($summary['reply_fallbacks'] ?? 0) > 0],
                    ['label' => 'Pakai Knowledge', 'value' => $summary['knowledge_hit_logs'] ?? 0,      'alert' => false],
                    ['label' => 'FAQ Langsung',    'value' => $summary['faq_direct_hits'] ?? 0,         'alert' => false],
                ];
            @endphp
            @foreach ($aiCards as $ac)
                <div class="bg-white rounded border border-gray-100 px-3 py-2 text-center">
                    <div class="text-lg font-bold {{ $ac['alert'] ? 'text-orange-600' : 'text-gray-800' }}">
                        {{ number_format($ac['value']) }}
                    </div>
                    <div class="text-xs text-gray-400 mt-0.5">{{ $ac['label'] }}</div>
                </div>
            @endforeach
        </div>

        @if (!empty($aiQuality['task_breakdown']))
            <div class="mt-3 flex flex-wrap gap-3">
                @foreach ($aiQuality['task_breakdown'] as $task => $counts)
                    <div class="text-xs text-gray-500 bg-white border border-gray-100 rounded px-2 py-1">
                        <span class="font-mono font-medium text-gray-700">{{ $task }}</span>:
                        {{ $counts['total'] }} total
                        @if ($counts['failed'] > 0)
                            · <span class="text-red-500">{{ $counts['failed'] }} gagal</span>
                        @endif
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
@endif

{{-- ── Two-column: Recent Conversations + Open Escalations ─────────────── --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">

    {{-- Recent Conversations --}}
    <div class="bg-white rounded-lg border border-gray-200">
        <div class="px-5 py-3 border-b border-gray-100 flex justify-between items-center">
            <span class="font-semibold text-gray-700 text-sm">Percakapan Terbaru</span>
            <a href="{{ route('admin.chatbot.conversations.index') }}" class="text-xs text-indigo-600 hover:underline">Lihat semua →</a>
        </div>
        <div class="divide-y divide-gray-50">
            @forelse ($latestConversations as $conv)
                <a href="{{ route('admin.chatbot.conversations.show', $conv) }}"
                   class="flex items-start gap-3 px-5 py-3 hover:bg-gray-50 transition-colors">
                    <div class="flex-1 min-w-0">
                        <div class="text-sm font-medium text-gray-800 truncate">
                            {{ $conv->customer?->name ?? $conv->customer?->phone_e164 ?? '—' }}
                        </div>
                        <div class="text-xs text-gray-400">
                            {{ $conv->status->value ?? $conv->status }} ·
                            {{ $conv->last_message_at?->diffForHumans() ?? '—' }}
                        </div>
                    </div>
                    @if ($conv->needs_human)
                        <span class="mt-0.5 bg-red-100 text-red-700 text-xs px-1.5 py-0.5 rounded">Human</span>
                    @endif
                </a>
            @empty
                <div class="px-5 py-4 text-sm text-gray-400">Belum ada percakapan.</div>
            @endforelse
        </div>
    </div>

    {{-- Open Escalations --}}
    <div class="bg-white rounded-lg border border-gray-200">
        <div class="px-5 py-3 border-b border-gray-100 flex justify-between items-center">
            <span class="font-semibold text-gray-700 text-sm">Eskalasi Open</span>
            <a href="{{ route('admin.chatbot.escalations.index') }}" class="text-xs text-indigo-600 hover:underline">Lihat semua →</a>
        </div>
        <div class="divide-y divide-gray-50">
            @forelse ($latestEscalations as $esc)
                <div class="px-5 py-3">
                    <div class="flex items-start justify-between gap-2">
                        <div class="flex-1 min-w-0">
                            <div class="text-sm font-medium text-gray-800 truncate">
                                {{ $esc->conversation->customer?->name ?? $esc->conversation->customer?->phone_e164 ?? '—' }}
                            </div>
                            <div class="text-xs text-gray-500 mt-0.5 truncate">
                                {{ $esc->reason ?? 'Tidak ada keterangan' }}
                            </div>
                        </div>
                        @php
                            $pBadge = ['normal' => 'bg-gray-100 text-gray-600', 'high' => 'bg-orange-100 text-orange-700', 'urgent' => 'bg-red-100 text-red-700'];
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded {{ $pBadge[$esc->priority] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ $esc->priority }}
                        </span>
                    </div>
                    <div class="text-xs text-gray-400 mt-1">{{ $esc->created_at->diffForHumans() }}</div>
                </div>
            @empty
                <div class="px-5 py-4 text-sm text-gray-400">Tidak ada eskalasi open.</div>
            @endforelse
        </div>
    </div>

</div>

@endsection
