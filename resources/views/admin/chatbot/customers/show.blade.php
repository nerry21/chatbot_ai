@extends('admin.chatbot.layouts.app')
@section('title', 'Profil Customer')

@section('content')

<div class="mb-4">
    <a href="{{ route('admin.chatbot.customers.index') }}" class="text-sm text-indigo-600 hover:underline">← Kembali ke daftar</a>
</div>

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- ── Left: Profile --}}
    <div class="space-y-4">

        <div class="bg-white rounded-lg border border-gray-200 p-5">
            <h3 class="text-sm font-semibold text-gray-700 mb-4">👤 Identitas</h3>
            <div class="space-y-2 text-sm">
                <div><span class="text-gray-400 w-28 inline-block">Nama</span> <span class="font-medium">{{ $customer->name ?? '—' }}</span></div>
                <div><span class="text-gray-400 w-28 inline-block">Nomor</span> {{ $customer->phone_e164 }}</div>
                <div><span class="text-gray-400 w-28 inline-block">Email</span> {{ $customer->email ?? '—' }}</div>
                <div><span class="text-gray-400 w-28 inline-block">Status</span> {{ $customer->status }}</div>
                <div><span class="text-gray-400 w-28 inline-block">Total booking</span> {{ $customer->total_bookings }}</div>
                <div><span class="text-gray-400 w-28 inline-block">Total spend</span> Rp {{ number_format($customer->total_spent, 0, ',', '.') }}</div>
                <div><span class="text-gray-400 w-28 inline-block">Terakhir aktif</span> {{ $customer->last_interaction_at?->format('d M Y H:i') ?? '—' }}</div>
            </div>
            @if ($customer->preferred_pickup || $customer->preferred_destination)
                <div class="mt-3 pt-3 border-t text-sm space-y-1">
                    <div><span class="text-gray-400 w-28 inline-block">Pickup favorit</span> {{ $customer->preferred_pickup ?? '—' }}</div>
                    <div><span class="text-gray-400 w-28 inline-block">Tujuan favorit</span> {{ $customer->preferred_destination ?? '—' }}</div>
                </div>
            @endif
            @if ($customer->notes)
                <div class="mt-3 pt-3 border-t text-sm text-gray-600">{{ $customer->notes }}</div>
            @endif
        </div>

        {{-- Aliases --}}
        @if ($customer->aliases->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">🏷 Alias</h3>
            <div class="flex flex-wrap gap-1.5">
                @foreach ($customer->aliases as $alias)
                    <span class="bg-gray-100 text-gray-700 text-xs px-2 py-0.5 rounded">{{ $alias->alias_name }}
                        @if ($alias->source)<span class="text-gray-400"> ({{ $alias->source }})</span>@endif
                    </span>
                @endforeach
            </div>
        </div>
        @endif

        {{-- CRM Tags --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">🔖 CRM Tags</h3>
            @if ($customer->tags->isNotEmpty())
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($customer->tags as $tag)
                        <span class="bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded-full">{{ $tag->tag }}</span>
                    @endforeach
                </div>
            @else
                <p class="text-xs text-gray-400">Belum ada tag.</p>
            @endif
        </div>

        {{-- CRM Contact --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">🔗 CRM Contact</h3>
            @if ($customer->crmContact)
                <div class="text-sm space-y-1">
                    <div><span class="text-gray-400 w-28 inline-block">Provider</span> {{ $customer->crmContact->provider }}</div>
                    <div><span class="text-gray-400 w-28 inline-block">External ID</span> {{ $customer->crmContact->external_contact_id ?? '—' }}</div>
                    <div><span class="text-gray-400 w-28 inline-block">Sync status</span> {{ $customer->crmContact->sync_status }}</div>
                    <div><span class="text-gray-400 w-28 inline-block">Last sync</span> {{ $customer->crmContact->last_synced_at?->format('d M Y H:i') ?? '—' }}</div>
                </div>
            @else
                <p class="text-xs text-gray-400">Belum ada CRM contact.</p>
            @endif
        </div>

        {{-- Lead Pipelines --}}
        @if ($customer->leadPipelines->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">📈 Lead Pipelines</h3>
            <div class="space-y-2">
                @foreach ($customer->leadPipelines as $lead)
                    <div class="flex items-center justify-between text-sm">
                        <span class="bg-purple-50 text-purple-700 text-xs px-2 py-0.5 rounded">{{ $lead->stage }}</span>
                        <span class="text-xs text-gray-400">{{ $lead->updated_at->format('d M Y') }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

    </div>

    {{-- ── Right: Conversations + Bookings --}}
    <div class="lg:col-span-2 space-y-5">

        {{-- Recent Conversations --}}
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <span class="text-sm font-semibold text-gray-700">💬 Percakapan Terakhir</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide border-b">
                    <tr>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Intent</th>
                        <th class="px-4 py-2 text-left">Pesan Terakhir</th>
                        <th class="px-4 py-2 text-left">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse ($conversations as $conv)
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">
                                @php $sv = is_string($conv->status) ? $conv->status : $conv->status->value; @endphp
                                <span class="text-xs px-1.5 py-0.5 rounded bg-gray-100 text-gray-600">{{ $sv }}</span>
                                @if ($conv->needs_human) <span class="text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded ml-1">Human</span> @endif
                            </td>
                            <td class="px-4 py-2 text-xs text-gray-500">{{ $conv->current_intent ?? '—' }}</td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $conv->last_message_at?->diffForHumans() ?? '—' }}</td>
                            <td class="px-4 py-2">
                                <a href="{{ route('admin.chatbot.conversations.show', $conv) }}" class="text-indigo-600 hover:underline text-xs">Detail →</a>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-4 py-4 text-center text-gray-400 text-sm">Belum ada percakapan.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        {{-- Recent Bookings --}}
        <div class="bg-white rounded-lg border border-gray-200">
            <div class="px-5 py-3 border-b border-gray-100">
                <span class="text-sm font-semibold text-gray-700">🎫 Booking Terakhir</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wide border-b">
                    <tr>
                        <th class="px-4 py-2 text-left">Rute</th>
                        <th class="px-4 py-2 text-left">Penumpang</th>
                        <th class="px-4 py-2 text-left">Harga</th>
                        <th class="px-4 py-2 text-left">Status</th>
                        <th class="px-4 py-2 text-left">Tanggal</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-50">
                    @forelse ($bookings as $bk)
                        @php $bs = is_string($bk->booking_status) ? $bk->booking_status : $bk->booking_status->value; @endphp
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-2">{{ $bk->pickup_location ?? '—' }} → {{ $bk->destination ?? '—' }}</td>
                            <td class="px-4 py-2 text-gray-600">{{ $bk->passenger_name ?? '—' }} ({{ $bk->passenger_count ?? '?' }})</td>
                            <td class="px-4 py-2 text-gray-600">{{ $bk->price_estimate ? 'Rp '.number_format($bk->price_estimate, 0, ',', '.') : '—' }}</td>
                            <td class="px-4 py-2"><span class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">{{ $bs }}</span></td>
                            <td class="px-4 py-2 text-xs text-gray-400">{{ $bk->created_at->format('d M Y') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-4 py-4 text-center text-gray-400 text-sm">Belum ada booking.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

    </div>

</div>

@endsection
