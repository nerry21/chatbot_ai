<div class="border-b border-slate-100 px-5 py-5">
    <div class="flex items-center justify-between gap-3">
        <div>
            <h2 class="text-sm font-semibold text-slate-900">Conversation List</h2>
            <p class="mt-1 text-sm text-slate-500">Filter operasional, unread, dan pencarian customer secara cepat.</p>
        </div>
        <div class="flex items-center gap-2">
            @php
                $unreadTotal = (int) $conversations->getCollection()->sum(
                    fn ($conversation) => (int) ($conversation->unread_messages_count ?? 0)
                );
            @endphp
            @if ($unreadTotal > 0)
                <span class="inline-flex items-center gap-2 rounded-full border border-red-200 bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-600">
                    <span class="h-1.5 w-1.5 rounded-full bg-red-500"></span>
                    {{ $unreadTotal }} unread
                </span>
            @endif
            <x-admin.chatbot.status-badge :value="$scope" size="sm" />
        </div>
    </div>

    <form method="GET" action="{{ route('admin.chatbot.live-chats.index') }}" class="mt-4">
        <div class="relative">
            <input
                type="text"
                name="search"
                value="{{ $search }}"
                placeholder="Cari nama, nomor WhatsApp, intent, atau keyword pesan"
                class="w-full rounded-[22px] border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:bg-white focus:ring-4 focus:ring-slate-200/60"
            >
            <input type="hidden" name="scope" value="{{ $scope }}">
            <input type="hidden" name="channel" value="{{ $channel }}">
        </div>
    </form>

    <div class="mt-4 flex flex-wrap gap-2">
        @foreach ($filters as $key => $label)
            @include('admin.chatbot.live-chats.partials.filter-pill', [
                'key' => $key,
                'label' => $label,
                'currentScope' => $scope,
            ])
        @endforeach
    </div>

    <div class="mt-3 flex flex-wrap gap-2">
        @foreach ($channels as $key => $label)
            @include('admin.chatbot.live-chats.partials.filter-pill', [
                'key' => $key,
                'label' => $label,
                'queryKey' => 'channel',
                'currentValue' => $channel,
            ])
        @endforeach
    </div>
</div>

<div class="console-scrollbar h-[28rem] overflow-y-auto px-4 py-4 xl:h-[calc(100vh-20rem)]">
    @if ($conversations->isEmpty())
        <x-admin.chatbot.empty-state
            title="Tidak ada chat"
            description="Belum ada conversation yang cocok dengan filter operasional saat ini."
            icon="chat"
        />
    @else
        <div class="space-y-3">
            @foreach ($conversations as $conversation)
                @include('admin.chatbot.live-chats.partials.conversation-list-item', [
                    'conversation' => $conversation,
                    'selectedConversationId' => $selectedConversationId,
                ])
            @endforeach
        </div>
    @endif
</div>

<div class="border-t border-slate-100 px-5 py-4">
    {{ $conversations->links() }}
</div>
