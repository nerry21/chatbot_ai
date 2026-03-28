@extends('admin.chatbot.layouts.app')

@section('title', 'Live Chats')
@section('page-subtitle', 'Daftar percakapan WhatsApp yang diproses bot maupun yang sedang diambil alih admin.')

@section('content')
<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Monitoring"
        title="Conversation Queue"
        description="Pantau seluruh thread customer, filter kebutuhan human takeover, dan buka detail percakapan untuk reply, takeover, atau release ke bot."
    />

    <x-admin.chatbot.panel title="Filter conversations" description="Gunakan filter untuk mempersempit percakapan aktif, butuh human, atau berdasarkan status operasional.">
        <form method="GET" class="grid gap-4 lg:grid-cols-[minmax(0,1.4fr)_220px_auto_auto]">
            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Cari customer</span>
                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Nama atau nomor WhatsApp"
                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60"
                >
            </label>

            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</span>
                <select name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                    <option value="">Semua status</option>
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="flex items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 lg:self-end">
                <input type="checkbox" name="needs_human" value="1" @checked(request('needs_human')) class="rounded border-slate-300 text-slate-900 focus:ring-slate-300">
                <span class="text-sm font-medium text-slate-700">Butuh human</span>
            </label>

            <div class="flex items-end gap-3">
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('admin.chatbot.live-chats.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                    Reset
                </a>
            </div>
        </form>
    </x-admin.chatbot.panel>

    <x-admin.chatbot.table-shell title="Daftar percakapan" description="Data live chats terbaru dengan status operasional, intent aktif, dan sinyal kebutuhan human.">
        @if ($conversations->isEmpty())
            <div class="p-6">
                <x-admin.chatbot.empty-state
                    title="Belum ada percakapan"
                    description="Percakapan customer akan tampil di sini setelah webhook WhatsApp menyimpan thread baru."
                    icon="chat"
                />
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50/80">
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Intent</th>
                        <th class="px-6 py-4">Takeover</th>
                        <th class="px-6 py-4">Last activity</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($conversations as $conversation)
                        @php
                            $status = is_string($conversation->status) ? $conversation->status : $conversation->status?->value;
                        @endphp
                        <tr class="transition hover:bg-slate-50/70">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900">{{ $conversation->customer?->name ?? 'Unknown customer' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $conversation->customer?->phone_e164 ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.chatbot.status-badge :value="$status" />
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $conversation->current_intent ?? 'no_intent' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-admin.chatbot.status-badge :value="$conversation->currentOperationalModeLabel()" :palette="$conversation->currentOperationalModePalette()" />

                                    @if ($conversation->assignedAdmin?->name)
                                        <x-admin.chatbot.status-badge :value="'Owner: '.$conversation->assignedAdmin->name" palette="indigo" />
                                    @endif

                                    @if ($conversation->needs_human && $conversation->currentOperationalMode() !== 'human_takeover')
                                        <x-admin.chatbot.status-badge value="needs_human" palette="red" />
                                    @endif
                                </div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $conversation->last_message_at?->diffForHumans() ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $conversation->last_message_at?->format('d M Y H:i') ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.chatbot.live-chats.show', $conversation) }}" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                                    Detail
                                    <x-admin.chatbot.icon name="arrow-up-right" class="h-4 w-4" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            {{ $conversations->links() }}
        </x-slot:footer>
    </x-admin.chatbot.table-shell>
</div>
@endsection
