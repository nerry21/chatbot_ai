@php
    $selectedStatus = $selectedConversation
        ? (is_string($selectedConversation->status) ? $selectedConversation->status : $selectedConversation->status?->value)
        : null;
    $latestEscalationStatus = $latestEscalation?->status;
    $selectedModeLabel = $selectedConversation?->currentOperationalModeLabel();
    $selectedModePalette = $selectedConversation?->currentOperationalModePalette();
@endphp

@if ($selectedConversation)
    <div class="border-b border-slate-100 px-6 py-5">
        <div class="flex items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="truncate text-lg font-semibold text-slate-900">
                    {{ $selectedConversation->customer?->name ?? 'Unknown customer' }}
                </div>
                <div class="mt-1 text-sm text-slate-500">{{ $selectedConversation->customer?->phone_e164 ?? '-' }}</div>
                <div class="mt-3 flex flex-wrap items-center gap-2">
                    <x-admin.chatbot.status-badge :value="$selectedModeLabel" :palette="$selectedModePalette" />
                    <x-admin.chatbot.status-badge :value="$selectedStatus" />
                    @if ($selectedConversation->needs_human)
                        <x-admin.chatbot.status-badge value="Needs Human" palette="red" />
                    @endif
                    @if ($latestEscalationStatus)
                        <x-admin.chatbot.status-badge :value="$latestEscalationStatus" palette="red" />
                    @endif
                    @if ($selectedConversation->assignedAdmin?->name)
                        <x-admin.chatbot.status-badge :value="'Assigned: '.$selectedConversation->assignedAdmin->name" palette="indigo" />
                    @endif
                    @if ((int) ($selectedConversation->unread_messages_count ?? 0) > 0)
                        <x-admin.chatbot.status-badge :value="($selectedConversation->unread_messages_count > 9 ? '9+' : $selectedConversation->unread_messages_count).' unread'" palette="red" />
                    @endif
                </div>
            </div>

            <div class="shrink-0 text-right">
                <div class="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-400">Last activity</div>
                <div class="mt-2 text-sm font-medium text-slate-900">{{ $selectedConversation->last_message_at?->diffForHumans() ?? '-' }}</div>
                <div class="mt-1 text-xs text-slate-500">{{ $selectedConversation->last_message_at?->format('d M Y H:i') ?? '-' }}</div>
            </div>
        </div>
    </div>

    <div class="console-scrollbar flex-1 overflow-y-auto bg-[linear-gradient(180deg,rgba(248,250,252,0.9),rgba(255,255,255,0.96))] px-6 py-6">
        @forelse ($messageGroups as $dateLabel => $messages)
            <div class="mb-8">
                <div class="mb-4 flex justify-center">
                    <span class="rounded-full border border-slate-200 bg-white px-4 py-1.5 text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500 shadow-sm">
                        {{ $dateLabel }}
                    </span>
                </div>

                <div class="space-y-4">
                    @foreach ($messages as $message)
                        @include('admin.chatbot.live-chats.partials.message-bubble', ['message' => $message])
                    @endforeach
                </div>
            </div>
        @empty
            <x-admin.chatbot.empty-state
                title="Belum ada thread"
                description="Percakapan terpilih belum memiliki message yang bisa ditampilkan."
                icon="chat"
            />
        @endforelse
    </div>
@else
    <div class="flex flex-1 items-center justify-center p-8">
        <x-admin.chatbot.empty-state
            title="Pilih conversation"
            description="Pilih salah satu chat dari panel kiri untuk melihat thread dan insight lengkap."
            icon="chat"
        />
    </div>
@endif
