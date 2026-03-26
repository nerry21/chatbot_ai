@extends('admin.chatbot.layouts.app')
@section('title', 'Percakapan')

@section('content')

{{-- Filter Bar --}}
<form method="GET" class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari customer</label>
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Nama / nomor HP"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-52 focus:outline-none focus:ring-2 focus:ring-indigo-300">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($statusOptions as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div class="flex items-center gap-2 pb-0.5">
        <input type="checkbox" name="needs_human" value="1" id="needs_human" @checked(request('needs_human'))
               class="rounded border-gray-300 text-indigo-600">
        <label for="needs_human" class="text-sm text-gray-600">Butuh admin</label>
    </div>
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-1.5 rounded-md transition-colors">Filter</button>
    <a href="{{ route('admin.chatbot.conversations.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
</form>

{{-- Table --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left">Customer</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Intent</th>
                <th class="px-4 py-3 text-left">Pesan Terakhir</th>
                <th class="px-4 py-3 text-left">Flag</th>
                <th class="px-4 py-3 text-left">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($conversations as $conv)
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800">{{ $conv->customer?->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $conv->customer?->phone_e164 }}</div>
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $sBadge = ['active' => 'bg-green-100 text-green-700', 'closed' => 'bg-gray-100 text-gray-600', 'escalated' => 'bg-red-100 text-red-700', 'archived' => 'bg-yellow-100 text-yellow-700'];
                            $sv = is_string($conv->status) ? $conv->status : $conv->status->value;
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded {{ $sBadge[$sv] ?? 'bg-gray-100 text-gray-600' }}">{{ $sv }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-600 text-xs">{{ $conv->current_intent ?? '—' }}</td>
                    <td class="px-4 py-3 text-xs text-gray-400">{{ $conv->last_message_at?->diffForHumans() ?? '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($conv->needs_human)
                            <span class="text-xs bg-red-100 text-red-600 px-1.5 py-0.5 rounded">Human</span>
                        @endif
                    </td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.chatbot.conversations.show', $conv) }}"
                           class="text-indigo-600 hover:underline text-xs">Detail →</a>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-8 text-center text-gray-400 text-sm">Tidak ada percakapan.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $conversations->links() }}</div>

@endsection
