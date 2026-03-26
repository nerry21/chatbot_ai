@extends('admin.chatbot.layouts.app')
@section('title', 'Detail Percakapan #' . $conversation->id)

@section('content')

<div class="mb-4 flex items-center justify-between">
    <a href="{{ route('admin.chatbot.conversations.index') }}" class="text-sm text-indigo-600 hover:underline">← Kembali ke daftar</a>
    @if ($conversation->isAdminTakeover())
        <span class="inline-flex items-center gap-1.5 bg-orange-100 text-orange-700 text-xs font-semibold px-3 py-1 rounded-full">
            🔒 Bot Nonaktif — Admin Takeover Aktif
        </span>
    @else
        <span class="inline-flex items-center gap-1.5 bg-green-50 text-green-600 text-xs px-3 py-1 rounded-full">
            🤖 Bot Aktif
        </span>
    @endif
</div>

{{-- Flash messages --}}
@if (session('success'))
    <div class="mb-4 bg-green-50 border border-green-200 text-green-700 text-sm px-4 py-3 rounded-lg">
        {{ session('success') }}
    </div>
@endif
@if (session('error'))
    <div class="mb-4 bg-red-50 border border-red-200 text-red-700 text-sm px-4 py-3 rounded-lg">
        {{ session('error') }}
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

    {{-- ── Left column: Customer + Conversation Info ──────────────────────── --}}
    <div class="space-y-4">

        {{-- Customer Card --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">👤 Customer</h3>
            @if ($conversation->customer)
                <div class="space-y-1.5 text-sm">
                    <div><span class="text-gray-400 w-20 inline-block">Nama</span> <span class="text-gray-800 font-medium">{{ $conversation->customer->name ?? '—' }}</span></div>
                    <div><span class="text-gray-400 w-20 inline-block">Nomor</span> <span class="text-gray-800">{{ $conversation->customer->phone_e164 }}</span></div>
                    <div><span class="text-gray-400 w-20 inline-block">Status</span> <span class="text-gray-800">{{ $conversation->customer->status }}</span></div>
                    <div><span class="text-gray-400 w-20 inline-block">Booking</span> <span class="text-gray-800">{{ $conversation->customer->total_bookings }} kali</span></div>
                </div>
                @if ($conversation->customer->tags->isNotEmpty())
                    <div class="mt-3 flex flex-wrap gap-1">
                        @foreach ($conversation->customer->tags as $tag)
                            <span class="bg-indigo-50 text-indigo-700 text-xs px-2 py-0.5 rounded-full">{{ $tag->tag }}</span>
                        @endforeach
                    </div>
                @endif
                <div class="mt-3">
                    <a href="{{ route('admin.chatbot.customers.show', $conversation->customer) }}"
                       class="text-xs text-indigo-600 hover:underline">Lihat profil customer →</a>
                </div>
            @else
                <p class="text-sm text-gray-400">Customer tidak ditemukan.</p>
            @endif
        </div>

        {{-- Conversation Meta --}}
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">📋 Info Percakapan</h3>
            <div class="space-y-1.5 text-sm">
                @php $sv = is_string($conversation->status) ? $conversation->status : $conversation->status->value; @endphp
                <div><span class="text-gray-400 w-24 inline-block">Status</span> <span class="font-medium">{{ $sv }}</span></div>
                <div><span class="text-gray-400 w-24 inline-block">Intent</span> <span>{{ $conversation->current_intent ?? '—' }}</span></div>
                <div><span class="text-gray-400 w-24 inline-block">Butuh admin</span> <span>{{ $conversation->needs_human ? '✅ Ya' : '—' }}</span></div>
                <div><span class="text-gray-400 w-24 inline-block">Mulai</span> <span>{{ $conversation->started_at?->format('d M Y H:i') ?? '—' }}</span></div>
                <div><span class="text-gray-400 w-24 inline-block">Pesan terakhir</span> <span>{{ $conversation->last_message_at?->format('d M Y H:i') ?? '—' }}</span></div>
            </div>
            @if ($conversation->summary)
                <div class="mt-3 border-t pt-3">
                    <div class="text-xs text-gray-400 mb-1">Ringkasan AI</div>
                    <p class="text-sm text-gray-700 leading-relaxed">{{ $conversation->summary }}</p>
                </div>
            @endif
        </div>

        {{-- ── Handoff / Takeover Panel ──────────────────────────────── --}}
        <div class="bg-white rounded-lg border {{ $conversation->isAdminTakeover() ? 'border-orange-300' : 'border-gray-200' }} p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">🔁 Handoff & Kontrol Bot</h3>

            @if ($conversation->isAdminTakeover())
                <div class="mb-3 bg-orange-50 border border-orange-200 rounded-md px-3 py-2 text-xs text-orange-700">
                    <strong>Bot sedang nonaktif.</strong> Admin (ID: {{ $conversation->handoff_admin_id ?? '—' }}) mengambil alih
                    sejak {{ $conversation->handoff_at?->format('d M Y H:i') ?? '—' }}.
                </div>
                {{-- Release ke Bot --}}
                <form method="POST"
                      action="{{ route('admin.chatbot.conversations.release', $conversation) }}"
                      onsubmit="return confirm('Aktifkan bot kembali untuk percakapan ini?')">
                    @csrf
                    <button type="submit"
                            class="w-full bg-green-600 hover:bg-green-700 text-white text-sm font-medium px-4 py-2 rounded-md">
                        ✅ Release ke Bot
                    </button>
                </form>
            @else
                <div class="mb-3 text-xs text-gray-500">
                    Bot sedang aktif dan membalas otomatis. Klik <strong>Takeover</strong> untuk mengendalikan percakapan secara manual.
                </div>
                {{-- Takeover --}}
                <form method="POST"
                      action="{{ route('admin.chatbot.conversations.takeover', $conversation) }}"
                      onsubmit="return confirm('Ambil alih percakapan ini? Bot akan dinonaktifkan.')">
                    @csrf
                    <button type="submit"
                            class="w-full bg-orange-500 hover:bg-orange-600 text-white text-sm font-medium px-4 py-2 rounded-md">
                        🔒 Takeover (Nonaktifkan Bot)
                    </button>
                </form>
            @endif
        </div>

        {{-- Active States --}}
        @if ($conversation->states->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">🗂 State Aktif</h3>
            <div class="space-y-1.5">
                @foreach ($conversation->states as $state)
                    <div class="flex items-start justify-between text-xs gap-2">
                        <span class="text-gray-500 font-mono">{{ $state->state_key }}</span>
                        <span class="text-gray-700 break-all text-right">{{ is_array($state->state_value) ? json_encode($state->state_value) : $state->state_value }}</span>
                    </div>
                @endforeach
            </div>
        </div>
        @endif

        {{-- Active Booking --}}
        @if ($conversation->bookingRequests->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">🎫 Booking Aktif</h3>
            @foreach ($conversation->bookingRequests as $booking)
                <div class="text-sm space-y-1">
                    @php $bs = is_string($booking->booking_status) ? $booking->booking_status : $booking->booking_status->value; @endphp
                    <div><span class="text-gray-400 w-20 inline-block">Status</span> <span class="font-medium">{{ $bs }}</span></div>
                    <div><span class="text-gray-400 w-20 inline-block">Pickup</span> {{ $booking->pickup_location ?? '—' }}</div>
                    <div><span class="text-gray-400 w-20 inline-block">Tujuan</span> {{ $booking->destination ?? '—' }}</div>
                    <div><span class="text-gray-400 w-20 inline-block">Penumpang</span> {{ $booking->passenger_name ?? '—' }} ({{ $booking->passenger_count ?? '?' }} org)</div>
                    <div><span class="text-gray-400 w-20 inline-block">Harga</span> {{ $booking->price_estimate ? 'Rp '.number_format($booking->price_estimate, 0, ',', '.') : '—' }}</div>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Lead Pipelines --}}
        @if ($conversation->leadPipelines->isNotEmpty())
        <div class="bg-white rounded-lg border border-gray-200 p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">📈 Lead Pipeline</h3>
            @foreach ($conversation->leadPipelines as $lead)
                <div class="text-sm">
                    <span class="bg-purple-50 text-purple-700 text-xs px-2 py-0.5 rounded">{{ $lead->stage }}</span>
                    <span class="text-xs text-gray-400 ml-2">{{ $lead->updated_at->diffForHumans() }}</span>
                </div>
            @endforeach
        </div>
        @endif

        {{-- Escalations --}}
        @if ($conversation->escalations->isNotEmpty())
        <div class="bg-white rounded-lg border border-red-100 p-4">
            <h3 class="text-sm font-semibold text-red-600 mb-3">🚨 Eskalasi</h3>
            @foreach ($conversation->escalations as $esc)
                <div class="text-sm space-y-0.5 mb-3">
                    <div><span class="text-gray-400 w-20 inline-block">Status</span> <span class="font-medium">{{ $esc->status }}</span></div>
                    <div><span class="text-gray-400 w-20 inline-block">Prioritas</span> {{ $esc->priority }}</div>
                    <div><span class="text-gray-400 w-20 inline-block">Alasan</span> {{ $esc->reason ?? '—' }}</div>
                    <div><span class="text-gray-400 w-20 inline-block">Dibuat</span> {{ $esc->created_at->format('d M Y H:i') }}</div>
                </div>
            @endforeach
        </div>
        @endif

    </div>

    {{-- ── Right column: Chat Thread + Admin Reply ─────────────────────── --}}
    <div class="lg:col-span-2 flex flex-col gap-4">

        {{-- Chat Thread --}}
        <div class="bg-white rounded-lg border border-gray-200 flex flex-col" style="min-height: 520px;">
            <div class="px-5 py-3 border-b border-gray-100 flex items-center justify-between">
                <div>
                    <span class="text-sm font-semibold text-gray-700">💬 Thread Percakapan</span>
                    <span class="text-xs text-gray-400 ml-2">({{ $conversation->messages->count() }} pesan)</span>
                </div>
                @if ($conversation->isAdminTakeover())
                    <span class="text-xs bg-orange-100 text-orange-700 px-2 py-0.5 rounded-full font-medium">
                        🔒 Mode Admin — Bot Nonaktif
                    </span>
                @endif
            </div>
            <div class="flex-1 overflow-y-auto p-4 space-y-3">
                @forelse ($conversation->messages as $msg)
                    @php
                        $dir        = is_string($msg->direction)   ? $msg->direction   : $msg->direction->value;
                        $senderType = is_string($msg->sender_type) ? $msg->sender_type : $msg->sender_type->value;
                        $isInbound  = $dir === 'inbound';
                        $isAgent    = $senderType === 'agent';
                        $ds         = $msg->delivery_status instanceof \App\Enums\MessageDeliveryStatus
                            ? $msg->delivery_status->value
                            : $msg->delivery_status;
                        $canResend  = ! $isInbound && $msg->isResendable()
                            && ($msg->send_attempts < config('chatbot.reliability.max_send_attempts', 3));
                    @endphp
                    <div class="flex {{ $isInbound ? 'justify-start' : 'justify-end' }}">
                        <div class="max-w-xs lg:max-w-md xl:max-w-lg">
                            <div class="rounded-2xl px-3.5 py-2.5 text-sm leading-relaxed
                                @if ($isInbound)
                                    bg-gray-100 text-gray-800 rounded-tl-none
                                @elseif ($isAgent)
                                    bg-teal-600 text-white rounded-tr-none
                                @else
                                    bg-indigo-600 text-white rounded-tr-none
                                @endif">
                                {{ $msg->message_text ?? '[non-text]' }}
                            </div>
                            <div class="text-xs mt-0.5 {{ $isInbound ? 'text-left text-gray-400' : 'text-right text-gray-400' }} space-x-1">
                                <span>{{ $msg->sent_at?->format('H:i') }}</span>
                                @if ($isAgent)
                                    <span>·</span><span class="text-teal-500 font-medium">admin</span>
                                @elseif (! $isInbound)
                                    <span>·</span><span class="text-indigo-300">bot</span>
                                @endif
                                @if ($msg->ai_intent)
                                    <span>·</span><span class="italic">{{ $msg->ai_intent }}</span>
                                @endif
                                @if ($msg->is_fallback)
                                    <span>·</span><span class="text-orange-400">fallback</span>
                                @endif
                                {{-- Delivery status badge (outbound messages only) --}}
                                @if (! $isInbound && $ds)
                                    <span>·</span>
                                    @if ($ds === 'sent' || $ds === 'delivered')
                                        <span class="text-green-400" title="Terkirim ke WhatsApp">✓ sent</span>
                                    @elseif ($ds === 'pending')
                                        <span class="text-yellow-400" title="Menunggu pengiriman">⋯ pending</span>
                                    @elseif ($ds === 'failed')
                                        <span class="text-red-400 font-medium" title="{{ $msg->delivery_error ?? 'Gagal kirim' }}">✗ failed</span>
                                    @elseif ($ds === 'skipped')
                                        <span class="text-gray-400" title="{{ $msg->delivery_error ?? 'Dilewati' }}">— skip</span>
                                    @endif
                                @endif
                                {{-- Send attempts counter (outbound, shown if > 0) --}}
                                @if (! $isInbound && ($msg->send_attempts ?? 0) > 0)
                                    <span>·</span>
                                    <span class="text-gray-400" title="Percobaan pengiriman: {{ $msg->send_attempts }}x, terakhir {{ $msg->last_send_attempt_at?->format('d M H:i') ?? '—' }}">
                                        {{ $msg->send_attempts }}x
                                    </span>
                                @endif
                            </div>
                            {{-- Delivery error detail (only shown for failed messages) --}}
                            @if (! $isInbound && $ds === 'failed' && $msg->delivery_error)
                                <div class="mt-0.5 text-right">
                                    <span class="text-xs text-red-400 italic">{{ \Str::limit($msg->delivery_error, 100) }}</span>
                                </div>
                            @endif
                            {{-- Resend button (Tahap 9) --}}
                            @if ($canResend)
                                <div class="mt-1 text-right">
                                    <form method="POST"
                                          action="{{ route('admin.chatbot.conversations.messages.resend', [$conversation, $msg]) }}"
                                          onsubmit="return confirm('Kirim ulang pesan ini ke customer?')">
                                        @csrf
                                        <button type="submit"
                                                class="inline-flex items-center gap-1 text-xs text-orange-600 hover:text-orange-800 border border-orange-300 hover:border-orange-500 bg-orange-50 hover:bg-orange-100 rounded px-2 py-0.5 transition-colors"
                                                title="Percobaan ke-{{ ($msg->send_attempts ?? 0) + 1 }} dari {{ config('chatbot.reliability.max_send_attempts', 3) }}">
                                            ↩ Kirim Ulang
                                        </button>
                                    </form>
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="text-center text-sm text-gray-400 py-8">Tidak ada pesan.</p>
                @endforelse
            </div>
        </div>

        {{-- Admin Reply Form --}}
        @if ($conversation->customer)
        <div class="bg-white rounded-lg border {{ $conversation->isAdminTakeover() ? 'border-teal-300' : 'border-gray-200' }} p-4">
            <h3 class="text-sm font-semibold text-gray-700 mb-3">
                ✍️ Balas Manual
                @if ($conversation->isAdminTakeover())
                    <span class="text-xs text-teal-600 ml-2 font-normal">(mode admin takeover)</span>
                @else
                    <span class="text-xs text-gray-400 ml-2 font-normal">(bot tetap aktif — takeover tidak otomatis)</span>
                @endif
            </h3>
            <form method="POST" action="{{ route('admin.chatbot.conversations.reply', $conversation) }}">
                @csrf
                <div class="flex gap-2">
                    <textarea name="message"
                              rows="2"
                              placeholder="Tulis pesan ke customer..."
                              required
                              maxlength="4096"
                              class="flex-1 border border-gray-300 rounded-md px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-teal-300 resize-none">{{ old('message') }}</textarea>
                    <button type="submit"
                            class="self-end bg-teal-600 hover:bg-teal-700 text-white text-sm font-medium px-5 py-2 rounded-md whitespace-nowrap">
                        Kirim
                    </button>
                </div>
                @error('message')
                    <p class="text-xs text-red-500 mt-1">{{ $message }}</p>
                @enderror
            </form>
        </div>
        @endif

    </div>

</div>

@endsection
