@extends('admin.chatbot.layouts.app')
@section('title', 'Notifikasi Admin')

@section('content')

@php
$typeLabels = [
    'escalation'              => ['label' => 'Eskalasi',        'color' => 'bg-red-100 text-red-700'],
    'takeover'                => ['label' => 'Takeover',        'color' => 'bg-orange-100 text-orange-700'],
    'release'                 => ['label' => 'Release',         'color' => 'bg-green-100 text-green-700'],
    'whatsapp_failed'         => ['label' => 'WA Gagal',        'color' => 'bg-red-100 text-red-700'],
    'inbound_during_takeover' => ['label' => 'Pesan Masuk',     'color' => 'bg-yellow-100 text-yellow-700'],
    'booking'                 => ['label' => 'Booking',         'color' => 'bg-blue-100 text-blue-700'],
    'system'                  => ['label' => 'Sistem',          'color' => 'bg-gray-100 text-gray-600'],
];
@endphp

<div class="flex items-center justify-between mb-5">
    <div class="flex gap-3">
        <a href="{{ route('admin.chatbot.notifications.index') }}"
           class="text-sm px-3 py-1.5 rounded-md {{ !request('filter') ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50' }}">
            Semua
        </a>
        <a href="{{ route('admin.chatbot.notifications.index', ['filter' => 'unread']) }}"
           class="text-sm px-3 py-1.5 rounded-md {{ request('filter') === 'unread' ? 'bg-indigo-600 text-white' : 'bg-white border border-gray-300 text-gray-600 hover:bg-gray-50' }}">
            Belum Dibaca <span class="ml-1 text-xs font-semibold">({{ $unreadCount }})</span>
        </a>
    </div>

    @if ($unreadCount > 0)
        <form method="POST" action="{{ route('admin.chatbot.notifications.read-all') }}">
            @csrf
            <button type="submit" class="text-sm text-gray-500 hover:text-indigo-600 border border-gray-300 px-3 py-1.5 rounded-md hover:border-indigo-400 transition-colors">
                Tandai semua dibaca
            </button>
        </form>
    @endif
</div>

<div class="space-y-2">
    @forelse ($notifications as $notif)
        @php
            $typeInfo = $typeLabels[$notif->type] ?? ['label' => $notif->type, 'color' => 'bg-gray-100 text-gray-600'];
        @endphp
        <div class="bg-white rounded-lg border {{ $notif->is_read ? 'border-gray-200' : 'border-indigo-300 bg-indigo-50/40' }} p-4">
            <div class="flex items-start justify-between gap-4">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2 mb-1 flex-wrap">
                        @if (! $notif->is_read)
                            <span class="w-2 h-2 bg-indigo-500 rounded-full flex-shrink-0" title="Belum dibaca"></span>
                        @endif
                        <span class="text-xs px-2 py-0.5 rounded font-medium {{ $typeInfo['color'] }}">
                            {{ $typeInfo['label'] }}
                        </span>
                        <span class="text-sm font-semibold text-gray-800">{{ $notif->title }}</span>
                    </div>
                    <p class="text-sm text-gray-600 whitespace-pre-line leading-relaxed">{{ $notif->body }}</p>
                    @if ($notif->payload)
                        <details class="mt-2">
                            <summary class="text-xs text-gray-400 cursor-pointer hover:text-gray-600 select-none">Lihat detail payload</summary>
                            <pre class="mt-1 text-xs bg-gray-50 border border-gray-200 rounded p-2 overflow-x-auto">{{ json_encode($notif->payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) }}</pre>
                        </details>
                    @endif
                </div>
                <div class="flex-shrink-0 text-right space-y-1.5 min-w-[110px]">
                    <div class="text-xs text-gray-400 whitespace-nowrap">{{ $notif->created_at->format('d M Y') }}</div>
                    <div class="text-xs text-gray-400">{{ $notif->created_at->format('H:i') }}</div>
                    @if (! $notif->is_read)
                        <form method="POST" action="{{ route('admin.chatbot.notifications.mark-read', $notif) }}">
                            @csrf
                            <button type="submit" class="text-xs text-indigo-600 hover:text-indigo-800 hover:underline">
                                Tandai dibaca
                            </button>
                        </form>
                    @else
                        <span class="text-xs text-gray-300">✓ Dibaca</span>
                    @endif
                </div>
            </div>
        </div>
    @empty
        <div class="bg-white rounded-lg border border-gray-200 p-8 text-center text-gray-400 text-sm">
            Tidak ada notifikasi.
        </div>
    @endforelse
</div>

<div class="mt-4">{{ $notifications->links() }}</div>

@endsection
