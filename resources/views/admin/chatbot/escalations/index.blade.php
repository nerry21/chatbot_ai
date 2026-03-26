@extends('admin.chatbot.layouts.app')
@section('title', 'Eskalasi')

@section('content')

<form method="GET" class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($statusOptions as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Prioritas</label>
        <select name="priority" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($priorityOptions as $p)
                <option value="{{ $p }}" @selected(request('priority') === $p)>{{ ucfirst($p) }}</option>
            @endforeach
        </select>
    </div>
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-1.5 rounded-md">Filter</button>
    <a href="{{ route('admin.chatbot.escalations.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
</form>

@php
    $statusColors   = ['open' => 'bg-red-100 text-red-700', 'assigned' => 'bg-yellow-100 text-yellow-700', 'resolved' => 'bg-green-100 text-green-700', 'closed' => 'bg-gray-100 text-gray-500'];
    $priorityColors = ['normal' => 'bg-gray-100 text-gray-600', 'high' => 'bg-orange-100 text-orange-700', 'urgent' => 'bg-red-100 text-red-700'];
@endphp

<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left">Customer</th>
                <th class="px-4 py-3 text-left">Alasan</th>
                <th class="px-4 py-3 text-left">Prioritas</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Assigned</th>
                <th class="px-4 py-3 text-left">Ringkasan</th>
                <th class="px-4 py-3 text-left">Dibuat</th>
                <th class="px-4 py-3 text-left">Aksi</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">

            {{-- Flash messages --}}
            @if (session('success'))
                <tr><td colspan="8" class="px-4 py-2">
                    <div class="bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-2 rounded-lg">
                        {{ session('success') }}
                    </div>
                </td></tr>
            @endif
            @if (session('error'))
                <tr><td colspan="8" class="px-4 py-2">
                    <div class="bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-2 rounded-lg">
                        {{ session('error') }}
                    </div>
                </td></tr>
            @endif

            @forelse ($escalations as $esc)
                <tr class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-800">{{ $esc->conversation->customer?->name ?? '—' }}</div>
                        <div class="text-xs text-gray-400">{{ $esc->conversation->customer?->phone_e164 }}</div>
                    </td>
                    <td class="px-4 py-3 text-gray-600 text-xs max-w-xs">
                        {{ Str::limit($esc->reason, 80) ?? '—' }}
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded {{ $priorityColors[$esc->priority] ?? 'bg-gray-100 text-gray-600' }}">{{ $esc->priority }}</span>
                    </td>
                    <td class="px-4 py-3">
                        <span class="text-xs px-2 py-0.5 rounded {{ $statusColors[$esc->status] ?? 'bg-gray-100 text-gray-600' }}">{{ $esc->status }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $esc->assigned_admin_id ? 'Admin #'.$esc->assigned_admin_id : '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 max-w-xs">
                        {{ Str::limit($esc->summary, 100) ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">{{ $esc->created_at->format('d M Y H:i') }}</td>
                    <td class="px-4 py-3">
                        <div class="flex flex-col gap-1.5">
                            {{-- Link ke conversation --}}
                            <a href="{{ route('admin.chatbot.conversations.show', $esc->conversation_id) }}"
                               class="text-indigo-600 hover:underline text-xs">Buka →</a>

                            {{-- Assign (hanya jika open/belum assigned ke admin ini) --}}
                            @if (in_array($esc->status, ['open', 'assigned']))
                                <form method="POST"
                                      action="{{ route('admin.chatbot.escalations.assign', $esc) }}">
                                    @csrf
                                    <button type="submit"
                                            class="text-xs text-yellow-700 hover:text-yellow-900 hover:underline text-left">
                                        ⚡ Assign ke Saya
                                    </button>
                                </form>
                            @endif

                            {{-- Resolve (hanya jika belum resolved/closed) --}}
                            @if (! in_array($esc->status, ['resolved', 'closed']))
                                <form method="POST"
                                      action="{{ route('admin.chatbot.escalations.resolve', $esc) }}"
                                      onsubmit="return confirm('Tandai eskalasi ini sebagai resolved?')">
                                    @csrf
                                    <button type="submit"
                                            class="text-xs text-green-700 hover:text-green-900 hover:underline text-left">
                                        ✅ Resolve
                                    </button>
                                </form>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="px-4 py-8 text-center text-gray-400 text-sm">Tidak ada eskalasi.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $escalations->links() }}</div>

@endsection
