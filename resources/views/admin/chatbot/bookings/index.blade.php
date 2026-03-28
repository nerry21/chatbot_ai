@extends('admin.chatbot.layouts.app')

@section('title', 'Bookings / Leads')
@section('page-subtitle', 'Daftar booking request dan lead yang dikumpulkan chatbot dari percakapan customer WhatsApp.')

@section('content')
<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Sales Pipeline"
        title="Booking requests"
        description="Pantau lead, status booking, route, jumlah penumpang, dan nilai estimasi dari percakapan chatbot."
    />

    <x-admin.chatbot.panel title="Filter bookings" description="Saring booking berdasarkan status atau customer untuk memudahkan monitoring lead.">
        <form method="GET" class="grid gap-4 lg:grid-cols-[minmax(0,1.2fr)_260px_auto_auto]">
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
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status booking</span>
                <select name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                    <option value="">Semua status</option>
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucwords(str_replace('_', ' ', $status)) }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex items-end gap-3">
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('admin.chatbot.bookings.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                    Reset
                </a>
            </div>
        </form>
    </x-admin.chatbot.panel>

    <x-admin.chatbot.table-shell title="Booking lead list" description="Lead booking aktual yang tercatat dari flow percakapan chatbot.">
        @if ($bookings->isEmpty())
            <div class="p-6">
                <x-admin.chatbot.empty-state
                    title="Belum ada booking request"
                    description="Booking yang berhasil dikumpulkan chatbot akan muncul di sini setelah customer mengisi flow booking."
                    icon="briefcase"
                />
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50/80">
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Route</th>
                        <th class="px-6 py-4">Schedule</th>
                        <th class="px-6 py-4">Passenger</th>
                        <th class="px-6 py-4">Price</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Updated</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($bookings as $booking)
                        @php
                            $bookingStatus = is_string($booking->booking_status) ? $booking->booking_status : $booking->booking_status?->value;
                        @endphp
                        <tr class="transition hover:bg-slate-50/70">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900">{{ $booking->customer?->name ?? $booking->passenger_name ?? 'Lead tanpa nama' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $booking->customer?->phone_e164 ?? $booking->contact_number ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $booking->pickup_location ?? '-' }} -> {{ $booking->destination ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">Trip key: {{ $booking->trip_key ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $booking->departure_date?->format('d M Y') ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $booking->departure_time ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $booking->passenger_name ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $booking->passenger_count ?? 0 }} penumpang</div>
                            </td>
                            <td class="px-6 py-4 text-slate-700">
                                {{ $booking->price_estimate ? 'Rp ' . number_format((float) $booking->price_estimate, 0, ',', '.') : '-' }}
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.chatbot.status-badge :value="$bookingStatus" />
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $booking->updated_at?->diffForHumans() ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $booking->updated_at?->format('d M Y H:i') ?? '-' }}</div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            {{ $bookings->links() }}
        </x-slot:footer>
    </x-admin.chatbot.table-shell>
</div>
@endsection
