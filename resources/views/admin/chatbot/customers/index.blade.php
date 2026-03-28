@extends('admin.chatbot.layouts.app')

@section('title', 'Customers')
@section('page-subtitle', 'Daftar customer WhatsApp yang pernah berinteraksi dengan chatbot beserta aktivitas booking dan conversation count.')

@section('content')
<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Customer Directory"
        title="Customer monitoring"
        description="Pantau customer, frekuensi interaksi, jumlah booking, dan akses cepat ke profil untuk analisis lebih lanjut."
    />

    <x-admin.chatbot.panel title="Filter customers" description="Cari berdasarkan nama, nomor WhatsApp, atau email customer.">
        <form method="GET" class="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto_auto]">
            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Search</span>
                <input
                    type="text"
                    name="search"
                    value="{{ request('search') }}"
                    placeholder="Nama, nomor, atau email"
                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60"
                >
            </label>

            <div class="flex items-end gap-3">
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('admin.chatbot.customers.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                    Reset
                </a>
            </div>
        </form>
    </x-admin.chatbot.panel>

    <x-admin.chatbot.table-shell title="Customer list" description="Ringkasan kontak dan aktivitas customer yang tersimpan di sistem chatbot.">
        @if ($customers->isEmpty())
            <div class="p-6">
                <x-admin.chatbot.empty-state
                    title="Belum ada customer"
                    description="Data customer akan muncul setelah ada inbound message yang berhasil disimpan."
                    icon="users"
                />
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50/80">
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Bookings</th>
                        <th class="px-6 py-4">Conversations</th>
                        <th class="px-6 py-4">Tags</th>
                        <th class="px-6 py-4">Last interaction</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($customers as $customer)
                        <tr class="transition hover:bg-slate-50/70">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900">{{ $customer->name ?? 'Unnamed customer' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $customer->phone_e164 ?? '-' }}</div>
                                @if ($customer->email)
                                    <div class="mt-1 text-xs text-slate-400">{{ $customer->email }}</div>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.chatbot.status-badge :value="$customer->status ?? 'unknown'" />
                            </td>
                            <td class="px-6 py-4 text-slate-700">{{ number_format((int) ($customer->total_bookings ?? 0)) }}</td>
                            <td class="px-6 py-4 text-slate-700">{{ number_format((int) ($customer->conversations_count ?? 0)) }}</td>
                            <td class="px-6 py-4 text-slate-700">{{ number_format((int) ($customer->tags_count ?? 0)) }}</td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $customer->last_interaction_at?->diffForHumans() ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $customer->last_interaction_at?->format('d M Y H:i') ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <a href="{{ route('admin.chatbot.customers.show', $customer) }}" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                                    Profil
                                    <x-admin.chatbot.icon name="arrow-up-right" class="h-4 w-4" />
                                </a>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            {{ $customers->links() }}
        </x-slot:footer>
    </x-admin.chatbot.table-shell>
</div>
@endsection
