@extends('admin.chatbot.layouts.app')
@section('title', 'Booking Lead')

@section('content')

<form method="GET" class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Cari customer</label>
        <input type="text" name="search" value="{{ request('search') }}"
               placeholder="Nama / nomor HP"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-52 focus:outline-none focus:ring-2 focus:ring-indigo-300">
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status Booking</label>
        <select name="status" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($statusOptions as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucwords(str_replace('_', ' ', $s)) }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-1.5 rounded-md">Filter</button>
    <a href="{{ route('admin.chatbot.bookings.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
</form>

@php
    $statusColors = [
        'draft'                  => 'bg-gray-100 text-gray-600',
        'awaiting_confirmation'  => 'bg-yellow-100 text-yellow-700',
        'confirmed'              => 'bg-green-100 text-green-700',
        'paid'                   => 'bg-blue-100 text-blue-700',
        'cancelled'              => 'bg-red-100 text-red-600',
        'completed'              => 'bg-teal-100 text-teal-700',
    ];
@endphp

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left">Customer</th>
                <th class="px-4 py-3 text-left">Pickup</th>
                <th class="px-4 py-3 text-left">Tujuan</th>
                <th class="px-4 py-3 text-left">Penumpang</th>
                <th class="px-4 py-3 text-left">Harga</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Diperbarui</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($bookings as $bk)
                @php $bs = is_string($bk->booking_status) ? $bk->booking_status : $bk->booking_status->value; @endphp
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800">{{ $bk->customer?->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $bk->customer?->phone_e164 }}</div>
                    </td>
                    <td class="px-4 py-3 text-gray-700">{{ $bk->pickup_location ?? '—' }}</td>
                    <td class="px-4 py-3 text-gray-700">{{ $bk->destination ?? '—' }}</td>
                    <td class="px-4 py-3">
                        <div class="text-gray-700">{{ $bk->passenger_name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $bk->passenger_count ?? '?' }} orang</div>
                    </td>
                    <td class="px-4 py-3 text-gray-700">{{ $bk->price_estimate ? 'Rp '.number_format($bk->price_estimate, 0, ',', '.') : '—' }}</td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded {{ $statusColors[$bs] ?? 'bg-gray-100 text-gray-600' }}">
                            {{ ucwords(str_replace('_', ' ', $bs)) }}
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400">{{ $bk->updated_at->format('d M Y H:i') }}</td>
                </tr>
            @empty
                <tr><td colspan="7" class="px-4 py-8 text-center text-gray-400 text-sm">Tidak ada booking.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $bookings->links() }}</div>

@endsection
