@extends('admin.chatbot.layouts.app')

@section('title', 'Conversation #' . $conversation->id)
@section('page-subtitle', 'Thread percakapan customer, state aktif, booking context, dan kontrol manual admin takeover.')

@section('content')
@php
    $conversationStatus = is_string($conversation->status) ? $conversation->status : $conversation->status?->value;
    $modeLabel = $conversation->currentOperationalModeLabel();
    $modePalette = $conversation->currentOperationalModePalette();
@endphp

<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Conversation Detail"
        :title="'Thread #' . $conversation->id"
        description="Pantau isi percakapan, state aktif, booking context, takeover, dan kirim balasan manual langsung dari console."
        :href="route('admin.chatbot.live-chats.index')"
        link-label="Kembali ke Live Chats"
    >
        <x-slot:actions>
            <div class="flex flex-wrap items-center gap-2">
                <x-admin.chatbot.status-badge :value="$conversationStatus" />
                <x-admin.chatbot.status-badge :value="$modeLabel" :palette="$modePalette" />
                @if ($conversation->assignedAdmin?->name)
                    <x-admin.chatbot.status-badge :value="'Assigned: '.$conversation->assignedAdmin->name" palette="indigo" />
                @endif
            </div>
        </x-slot:actions>
    </x-admin.chatbot.section-heading>

    <div class="grid gap-6 xl:grid-cols-[0.95fr_1.45fr_0.85fr]">
        <div class="space-y-6">
            <x-admin.chatbot.panel title="Customer" description="Profil dasar customer yang terhubung ke percakapan ini.">
                @if ($conversation->customer)
                    <div class="space-y-4">
                        <div>
                            <div class="text-lg font-semibold text-slate-900">{{ $conversation->customer->name ?? 'Unnamed customer' }}</div>
                            <div class="mt-1 text-sm text-slate-500">{{ $conversation->customer->phone_e164 ?? '-' }}</div>
                        </div>

                        <dl class="space-y-3 text-sm">
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-slate-500">Status</dt>
                                <dd class="font-medium text-slate-900">{{ $conversation->customer->status ?? '-' }}</dd>
                            </div>
                            <div class="flex items-center justify-between gap-4">
                                <dt class="text-slate-500">Total booking</dt>
                                <dd class="font-medium text-slate-900">{{ number_format((int) ($conversation->customer->total_bookings ?? 0)) }}</dd>
                            </div>
                        </dl>

                        @if ($conversation->customer->tags->isNotEmpty())
                            <div class="flex flex-wrap gap-2">
                                @foreach ($conversation->customer->tags as $tag)
                                    <x-admin.chatbot.status-badge :value="$tag->tag" palette="indigo" size="sm" />
                                @endforeach
                            </div>
                        @endif

                        <a href="{{ route('admin.chatbot.customers.show', $conversation->customer) }}" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                            Profil customer
                            <x-admin.chatbot.icon name="arrow-up-right" class="h-4 w-4" />
                        </a>
                    </div>
                @else
                    <x-admin.chatbot.empty-state
                        title="Customer tidak ditemukan"
                        description="Relasi customer untuk conversation ini tidak tersedia."
                        icon="users"
                    />
                @endif
            </x-admin.chatbot.panel>

            <x-admin.chatbot.panel title="Conversation Summary" description="Status operasional dan ringkasan singkat thread.">
                <div class="space-y-4 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Status</span>
                        <x-admin.chatbot.status-badge :value="$conversationStatus" size="sm" />
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Current intent</span>
                        <span class="font-medium text-slate-900">{{ $conversation->current_intent ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Started at</span>
                        <span class="font-medium text-slate-900">{{ $conversation->started_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Last message</span>
                        <span class="font-medium text-slate-900">{{ $conversation->last_message_at?->format('d M Y H:i') ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Needs human</span>
                        <span class="font-medium text-slate-900">{{ $conversation->needs_human ? 'Ya' : 'Tidak' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Bot paused</span>
                        <span class="font-medium text-slate-900">{{ $conversation->bot_paused ? 'Ya' : 'Tidak' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Pause reason</span>
                        <span class="font-medium text-slate-900">{{ $conversation->bot_paused_reason ?? '-' }}</span>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-slate-500">Assigned admin</span>
                        <span class="font-medium text-slate-900">{{ $conversation->assignedAdmin?->name ?? ($conversation->assigned_admin_id ? 'Admin #' . $conversation->assigned_admin_id : '-') }}</span>
                    </div>
                </div>

                @if ($conversation->summary)
                    <div class="mt-5 rounded-[22px] border border-slate-100 bg-slate-50 p-4">
                        <div class="text-xs font-semibold uppercase tracking-[0.18em] text-slate-400">AI Summary</div>
                        <p class="mt-3 text-sm leading-7 text-slate-600">{{ $conversation->summary }}</p>
                    </div>
                @endif
            </x-admin.chatbot.panel>

            <x-admin.chatbot.panel title="Bot Control" description="Take over percakapan untuk menonaktifkan auto-reply atau release kembali ke bot.">
                @if ($conversation->isAdminTakeover())
                    <div class="rounded-[22px] border border-orange-200 bg-orange-50 px-4 py-4 text-sm text-orange-700">
                        Bot sedang nonaktif. Conversation dipegang admin sejak {{ $conversation->handoff_at?->format('d M Y H:i') ?? '-' }}.
                    </div>

                    <form method="POST" action="{{ route('admin.chatbot.conversations.release', $conversation) }}" class="mt-4" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button type="submit" :disabled="busy" class="inline-flex w-full items-center justify-center rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-70">
                            <span x-show="!busy">Release ke Bot</span>
                            <span x-show="busy">Melepas...</span>
                        </button>
                    </form>
                @else
                    <div class="rounded-[22px] border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-600">
                        Bot masih aktif dan dapat membalas otomatis. Gunakan takeover jika admin perlu mengendalikan thread ini secara manual.
                    </div>

                    <form method="POST" action="{{ route('admin.chatbot.conversations.takeover', $conversation) }}" class="mt-4" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button type="submit" :disabled="busy" class="inline-flex w-full items-center justify-center rounded-2xl bg-orange-500 px-4 py-3 text-sm font-medium text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-70">
                            <span x-show="!busy">Takeover Conversation</span>
                            <span x-show="busy">Mengambil alih...</span>
                        </button>
                    </form>
                @endif
            </x-admin.chatbot.panel>
        </div>

        <div class="space-y-6">
            <x-admin.chatbot.panel title="Chat Thread" :description="'Riwayat pesan customer, bot, dan admin. Total ' . $conversation->messages->count() . ' message(s).'">
                @if ($conversation->messages->isEmpty())
                    <x-admin.chatbot.empty-state
                        title="Belum ada pesan"
                        description="Belum ada message yang tercatat pada percakapan ini."
                        icon="chat"
                    />
                @else
                    <div class="console-scrollbar max-h-[820px] space-y-4 overflow-y-auto pr-1">
                        @foreach ($conversation->messages as $message)
                            @php
                                $direction = is_string($message->direction) ? $message->direction : $message->direction?->value;
                                $senderType = is_string($message->sender_type) ? $message->sender_type : $message->sender_type?->value;
                                $deliveryStatus = is_string($message->delivery_status) ? $message->delivery_status : $message->delivery_status?->value;
                                $isInbound = $direction === 'inbound';
                                $isAgent = in_array($senderType, ['agent', 'admin'], true);
                                $canResend = ! $isInbound
                                    && $message->isResendable()
                                    && ($message->send_attempts < config('chatbot.reliability.max_send_attempts', 3));
                            @endphp

                            <div class="flex {{ $isInbound ? 'justify-start' : 'justify-end' }}">
                                <div class="max-w-xl">
                                    <div class="rounded-[24px] px-4 py-3 shadow-sm ring-1 {{ $isInbound ? 'bg-slate-100 text-slate-800 ring-slate-200' : ($isAgent ? 'bg-teal-600 text-white ring-teal-500/30' : 'bg-slate-900 text-white ring-slate-800/20') }}">
                                        <div class="text-sm leading-7">{{ $message->message_text ?? '[non-text]' }}</div>
                                    </div>

                                    <div class="mt-2 flex flex-wrap items-center gap-2 text-xs {{ $isInbound ? 'text-slate-400' : 'justify-end text-slate-400' }}">
                                        <span>{{ $message->sent_at?->format('d M H:i') ?? '-' }}</span>
                                        @if ($isInbound)
                                            <x-admin.chatbot.status-badge value="customer" palette="slate" size="sm" />
                                        @elseif ($isAgent)
                                            <x-admin.chatbot.status-badge value="admin" palette="teal" size="sm" />
                                        @else
                                            <x-admin.chatbot.status-badge value="bot" palette="indigo" size="sm" />
                                        @endif

                                        @if ($message->ai_intent)
                                            <x-admin.chatbot.status-badge :value="$message->ai_intent" palette="purple" size="sm" />
                                        @endif

                                        @if ($message->is_fallback)
                                            <x-admin.chatbot.status-badge value="fallback" palette="amber" size="sm" />
                                        @endif

                                        @if (! $isInbound && $deliveryStatus)
                                            <x-admin.chatbot.status-badge :value="$deliveryStatus" size="sm" />
                                        @endif
                                    </div>

                                    @if (! $isInbound && $message->delivery_error)
                                        <div class="mt-2 text-xs text-red-500">{{ \Illuminate\Support\Str::limit($message->delivery_error, 160) }}</div>
                                    @endif

                                    @if ($canResend)
                                        <div class="mt-2 {{ $isInbound ? '' : 'text-right' }}">
                                            <form method="POST" action="{{ route('admin.chatbot.conversations.messages.resend', [$conversation, $message]) }}" onsubmit="return confirm('Kirim ulang pesan ini ke customer?')">
                                                @csrf
                                                <button type="submit" class="inline-flex items-center gap-2 rounded-xl border border-orange-200 bg-orange-50 px-3 py-2 text-xs font-medium text-orange-700 transition hover:border-orange-300 hover:bg-orange-100">
                                                    Kirim ulang
                                                </button>
                                            </form>
                                        </div>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-admin.chatbot.panel>

            @if ($conversation->customer)
                <x-admin.chatbot.panel title="Balas Manual" description="Admin bisa mengirim pesan langsung ke customer. Saat takeover aktif, bot tetap disuppress.">
                    <form method="POST" action="{{ route('admin.chatbot.conversations.reply', $conversation) }}" class="space-y-4">
                        @csrf
                        <textarea
                            name="message"
                            rows="4"
                            required
                            maxlength="4096"
                            placeholder="Tulis pesan manual ke customer..."
                            class="w-full rounded-[24px] border border-slate-200 bg-slate-50 px-4 py-4 text-sm text-slate-700 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:bg-white focus:ring-4 focus:ring-slate-200/60"
                        >{{ old('message') }}</textarea>
                        @error('message')
                            <p class="text-sm text-red-500">{{ $message }}</p>
                        @enderror

                        <div class="flex items-center justify-between gap-4">
                            <div class="text-sm text-slate-500">
                                @if ($conversation->isAdminTakeover())
                                    Mode admin takeover aktif. Bot tidak akan auto-reply.
                                @else
                                    Balasan manual tetap bisa dikirim, tetapi bot masih aktif sampai takeover dinyalakan.
                                @endif
                            </div>
                            <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
                                Kirim pesan
                            </button>
                        </div>
                    </form>
                </x-admin.chatbot.panel>
            @endif
        </div>

        <div class="space-y-6">
            @if ($conversation->states->isNotEmpty())
                <x-admin.chatbot.panel title="State Summary" description="Snapshot state aktif dari memory/state conversation saat ini.">
                    <div class="space-y-3">
                        @foreach ($conversation->states as $state)
                            <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-3">
                                <div class="text-xs font-semibold uppercase tracking-[0.16em] text-slate-400">{{ $state->state_key }}</div>
                                <div class="mt-2 break-all text-sm text-slate-700">
                                    {{ is_array($state->state_value) ? json_encode($state->state_value) : $state->state_value }}
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-admin.chatbot.panel>
            @endif

            @if ($conversation->bookingRequests->isNotEmpty())
                <x-admin.chatbot.panel title="Booking Context" description="Draft atau booking aktif yang terkait dengan percakapan ini.">
                    <div class="space-y-4">
                        @foreach ($conversation->bookingRequests as $booking)
                            @php
                                $bookingStatus = is_string($booking->booking_status) ? $booking->booking_status : $booking->booking_status?->value;
                            @endphp
                            <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <div class="text-sm font-semibold text-slate-900">{{ $booking->pickup_location ?? '-' }} -> {{ $booking->destination ?? '-' }}</div>
                                        <div class="mt-1 text-xs text-slate-500">
                                            {{ $booking->departure_date?->format('d M Y') ?? '-' }} · {{ $booking->departure_time ?? '-' }}
                                        </div>
                                    </div>
                                    <x-admin.chatbot.status-badge :value="$bookingStatus" size="sm" />
                                </div>

                                <div class="mt-4 space-y-2 text-sm text-slate-600">
                                    <div>Passenger: {{ $booking->passenger_name ?? '-' }} ({{ $booking->passenger_count ?? 0 }} org)</div>
                                    <div>Pickup detail: {{ $booking->pickup_full_address ?? '-' }}</div>
                                    <div>Destination detail: {{ $booking->destination_full_address ?? '-' }}</div>
                                    <div>Price: {{ $booking->price_estimate ? 'Rp ' . number_format((float) $booking->price_estimate, 0, ',', '.') : '-' }}</div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                </x-admin.chatbot.panel>
            @endif

            @if ($conversation->leadPipelines->isNotEmpty())
                <x-admin.chatbot.panel title="Lead Pipelines" description="Stage lead yang terkait dengan conversation ini.">
                    <div class="space-y-3">
                        @foreach ($conversation->leadPipelines as $lead)
                            <div class="flex items-center justify-between rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-3">
                                <div class="text-sm font-medium text-slate-900">{{ $lead->stage }}</div>
                                <div class="text-xs text-slate-400">{{ $lead->updated_at?->diffForHumans() ?? '-' }}</div>
                            </div>
                        @endforeach
                    </div>
                </x-admin.chatbot.panel>
            @endif

            @if ($conversation->escalations->isNotEmpty())
                <x-admin.chatbot.panel title="Escalation History" description="Catatan escalation yang terkait dengan conversation ini.">
                    <div class="space-y-3">
                        @foreach ($conversation->escalations as $escalation)
                            <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                                <div class="flex items-center justify-between gap-3">
                                    <x-admin.chatbot.status-badge :value="$escalation->status" size="sm" />
                                    <x-admin.chatbot.status-badge :value="$escalation->priority" size="sm" />
                                </div>
                                <p class="mt-3 text-sm leading-6 text-slate-600">{{ $escalation->reason ?? $escalation->summary ?? 'Tidak ada catatan.' }}</p>
                                <div class="mt-3 text-xs text-slate-400">{{ $escalation->created_at?->format('d M Y H:i') ?? '-' }}</div>
                            </div>
                        @endforeach
                    </div>
                </x-admin.chatbot.panel>
            @endif
        </div>
    </div>
</div>
@endsection
