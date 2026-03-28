@php
    $selected = (int) ($selectedConversationId ?? 0) === (int) $conversation->id;
    $status = is_string($conversation->status) ? $conversation->status : $conversation->status?->value;
    $lastSender = (string) ($conversation->last_message_sender_type ?? '');
    $lastPreview = trim((string) ($conversation->last_message_preview ?? ''));
    $previewPrefix = match ($lastSender) {
        'bot' => 'Bot: ',
        'admin' => 'Admin: ',
        'agent' => 'Admin: ',
        'system' => 'System: ',
        default => '',
    };
    $previewText = $lastPreview !== '' ? $previewPrefix . $lastPreview : 'Belum ada preview pesan.';
    $unreadCount = (int) ($conversation->unread_messages_count ?? 0);
    $modeLabel = $conversation->currentOperationalModeLabel();
    $modePalette = $conversation->currentOperationalModePalette();
    $routeParameters = array_merge(['conversation' => $conversation], request()->query());
@endphp

<a
    href="{{ route('admin.chatbot.live-chats.show', $routeParameters) }}"
    class="group block rounded-[26px] border px-4 py-4 transition {{ $selected ? 'border-slate-900 bg-slate-900 text-white shadow-[0_24px_60px_-32px_rgba(15,23,42,0.6)]' : 'border-slate-200 bg-white hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-[0_18px_40px_-28px_rgba(15,23,42,0.35)]' }}"
>
    <div class="flex items-start justify-between gap-4">
        <div class="min-w-0">
            <div class="truncate text-sm font-semibold {{ $selected ? 'text-white' : 'text-slate-900' }}">
                {{ $conversation->customer?->name ?? 'Unknown customer' }}
            </div>
            <div class="mt-1 truncate text-xs {{ $selected ? 'text-slate-300' : 'text-slate-500' }}">
                {{ $conversation->customer?->phone_e164 ?? '-' }}
            </div>
        </div>

        <div class="shrink-0 text-right">
            <div class="text-xs {{ $selected ? 'text-slate-300' : 'text-slate-400' }}">
                {{ $conversation->last_message_at?->format('H:i') ?? '-' }}
            </div>
            @if ($unreadCount > 0)
                <div class="mt-2 inline-flex min-w-6 items-center justify-center rounded-full bg-red-500 px-2 py-1 text-[11px] font-semibold text-white">
                    {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                </div>
            @endif
        </div>
    </div>

    <p class="mt-3 line-clamp-2 text-sm leading-6 {{ $selected ? 'text-slate-200' : 'text-slate-500' }}">
        {{ \Illuminate\Support\Str::limit($previewText, 110) }}
    </p>

    <div class="mt-4 flex flex-wrap items-center gap-2">
        <x-admin.chatbot.status-badge :value="$modeLabel" :palette="$modePalette" size="sm" />
        <x-admin.chatbot.status-badge :value="$status" :palette="$selected ? 'slate' : null" size="sm" />
        @if ($conversation->assignedAdmin?->name)
            <x-admin.chatbot.status-badge :value="'Owner: '.$conversation->assignedAdmin->name" palette="indigo" size="sm" />
        @endif
        @if ($conversation->needs_human && ! $conversation->isAdminTakeover() && $conversation->currentOperationalMode() !== 'closed')
            <x-admin.chatbot.status-badge value="Needs Human" palette="red" size="sm" />
        @endif
    </div>
</a>
