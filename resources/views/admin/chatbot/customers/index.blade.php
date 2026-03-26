@extends('admin.chatbot.layouts.app')
@section('title', 'Customer')

@section('content')

<form method="GET" class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari</label>
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Nama / nomor / email"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-64 focus:outline-none focus:ring-2 focus:ring-indigo-300">
    </div>
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-1.5 rounded-md">Filter</button>
    <a href="{{ route('admin.chatbot.customers.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
</form>

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left">Nama / Nomor</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Booking</th>
                <th class="px-4 py-3 text-left">Percakapan</th>
                <th class="px-4 py-3 text-left">Tag</th>
                <th class="px-4 py-3 text-left">Terakhir aktif</th>
                <th class="px-4 py-3 text-left">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($customers as $cust)
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800">{{ $cust->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $cust->phone_e164 }}</div>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded {{ $cust->status === 'active' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' }}">{{ $cust->status }}</span>
                    </td>
                    <td class="px-4 py-3 text-gray-600">{{ $cust->total_bookings }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $cust->conversations_count }}</td>
                    <td class="px-4 py-3 text-gray-600">{{ $cust->tags_count }}</td>
                    <td class="px-4 py-3 text-xs text-gray-400">{{ $cust->last_interaction_at?->diffForHumans() ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <a href="{{ route('admin.chatbot.customers.show', $cust) }}" class="text-indigo-600 hover:underline text-xs">Profil →</a>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">Tidak ada customer.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $customers->links() }}</div>

@endsection
