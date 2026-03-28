@extends('admin.chatbot.layouts.app')

@section('title', 'Live Chats')
@section('page-subtitle', 'Workspace operasional dengan polling ringan, unread tracking, dan thread monitoring yang terasa hidup tanpa mengganggu performa produksi.')

@section('content')
@php
    $selectedConversationId = $selectedConversation?->id;
    $initialUnreadTotal = (int) $conversations->getCollection()->sum(
        fn ($conversation) => (int) ($conversation->unread_messages_count ?? 0)
    );
@endphp

<div
    x-data="liveChatWorkspace({
        selectedConversationId: @js($selectedConversationId),
        listPollUrl: @js(route('admin.chatbot.live-chats.poll.list')),
        conversationPollBase: @js(url('/admin/chatbot/live-chats')),
        markReadBase: @js(url('/admin/chatbot/live-chats')),
        currentQuery: @js(request()->query()),
        csrfToken: @js(csrf_token()),
        initialLastUpdated: @js($lastUpdatedAt),
        initialUnreadTotal: @js($initialUnreadTotal),
    })"
    x-init="init()"
    x-on:beforeunload.window="destroy()"
    class="space-y-6"
>
    <x-admin.chatbot.section-heading
        kicker="Workspace"
        title="Live Chat Panel"
        description="Panel monitoring 3 kolom dengan refresh parsial aman, unread tracking per admin, dan status operasional conversation yang selalu segar."
    />

    <div class="grid gap-4 xl:grid-cols-[minmax(0,1fr)_auto]">
        <div class="console-card-lift flex items-center gap-3 rounded-[28px] border border-slate-200/80 bg-white/90 px-5 py-4 shadow-[0_18px_50px_-34px_rgba(15,23,42,0.24)] backdrop-blur">
            <div class="relative flex h-11 w-11 items-center justify-center rounded-2xl bg-slate-900 text-white shadow-lg shadow-slate-900/20">
                <span
                    class="absolute inset-0 rounded-2xl bg-slate-900/20"
                    :class="isRefreshing ? 'animate-pulse' : ''"
                ></span>
                <x-admin.chatbot.icon name="activity" class="relative z-[1] h-5 w-5" />
            </div>

            <div class="min-w-0">
                <div class="text-sm font-semibold text-slate-900">Auto refresh aktif</div>
                <div class="mt-1 text-sm text-slate-500">
                    Thread aktif, unread badge, takeover status, dan latest activity diperbarui ringan setiap beberapa detik.
                </div>
            </div>
        </div>

        <div class="console-card-lift flex items-center justify-between gap-4 rounded-[28px] border border-slate-200/80 bg-white/90 px-5 py-4 shadow-[0_18px_50px_-34px_rgba(15,23,42,0.24)] backdrop-blur xl:min-w-[320px]">
            <div>
                <div class="text-[11px] font-semibold uppercase tracking-[0.2em] text-slate-400">Live Status</div>
                <div class="mt-2 flex items-center gap-3">
                    <span class="inline-flex h-2.5 w-2.5 rounded-full bg-emerald-500" :class="isRefreshing ? 'animate-pulse' : ''"></span>
                    <span class="text-sm font-medium text-slate-900" x-text="statusLabel"></span>
                </div>
                <div class="mt-2 text-xs text-slate-500">
                    Last updated <span class="font-semibold text-slate-700" x-text="lastUpdatedLabel"></span>
                    <span class="mx-2 text-slate-300">&middot;</span>
                    <span class="font-semibold text-slate-700" x-text="`${unreadTotal} unread`"></span>
                </div>
            </div>

            <button
                type="button"
                @click="manualRefresh()"
                :disabled="isRefreshing"
                class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-60"
            >
                <x-admin.chatbot.icon name="refresh" class="h-4 w-4" />
                <span x-text="isRefreshing ? 'Refreshing...' : 'Refresh now'"></span>
            </button>
        </div>
    </div>

    <div class="grid gap-5 xl:grid-cols-[340px_minmax(0,1fr)_360px] xl:h-[calc(100vh-11rem)]">
        <section class="console-card-lift overflow-hidden rounded-[32px] border border-slate-200/80 bg-white/95 shadow-[0_24px_70px_-36px_rgba(15,23,42,0.35)] backdrop-blur">
            <div x-ref="listPane">
                @include('admin.chatbot.live-chats.partials.list-pane')
            </div>
        </section>

        <section class="console-card-lift flex min-h-[42rem] flex-col overflow-hidden rounded-[32px] border border-slate-200/80 bg-white/95 shadow-[0_24px_70px_-36px_rgba(15,23,42,0.35)] backdrop-blur xl:h-[calc(100vh-11rem)]">
            <div x-ref="threadPane" class="flex min-h-0 flex-1 flex-col">
                @include('admin.chatbot.live-chats.partials.thread-pane')
            </div>

            @if ($selectedConversation)
                <div class="border-t border-slate-100 bg-white/95 px-6 py-5">
                    <form
                        method="POST"
                        action="{{ route('admin.chatbot.live-chats.messages.store', array_merge(['conversation' => $selectedConversation], request()->query())) }}"
                        x-data="{ busy: false, body: @js(old('message', '')), resize() { this.$refs.body.style.height = '0px'; this.$refs.body.style.height = Math.min(this.$refs.body.scrollHeight, 180) + 'px'; } }"
                        x-init="$nextTick(() => resize())"
                        @submit="busy = true"
                        class="rounded-[28px] border border-slate-200 bg-[linear-gradient(180deg,rgba(255,255,255,0.98),rgba(248,250,252,0.95))] px-5 py-4 shadow-[0_20px_50px_-34px_rgba(15,23,42,0.25)]"
                    >
                        @csrf
                        <div class="mb-3 flex items-center justify-between gap-4">
                            <div>
                                <div class="text-sm font-semibold text-slate-900">Admin Composer</div>
                                <p class="mt-1 text-sm text-slate-500">Pesan manual akan otomatis mengamankan mode takeover sebelum dikirim ke WhatsApp.</p>
                            </div>
                            <x-admin.chatbot.status-badge :value="$selectedConversation->isAdminTakeover() ? 'Takeover Active' : 'Will Take Over'" :palette="$selectedConversation->isAdminTakeover() ? 'orange' : 'slate'" size="sm" />
                        </div>

                        <div class="rounded-[24px] border border-slate-200 bg-white px-4 py-3 transition focus-within:border-slate-300 focus-within:ring-4 focus-within:ring-slate-200/60">
                            <textarea
                                x-ref="body"
                                x-model="body"
                                @input="resize()"
                                name="message"
                                rows="1"
                                maxlength="4096"
                                required
                                placeholder="Tulis balasan manual ke customer..."
                                class="max-h-[180px] min-h-[48px] w-full resize-none border-0 bg-transparent p-0 text-sm leading-7 text-slate-700 outline-none placeholder:text-slate-400 focus:ring-0"
                            >{{ old('message') }}</textarea>
                        </div>

                        @error('message')
                            <p class="mt-3 text-sm text-red-500">{{ $message }}</p>
                        @enderror

                        <div class="mt-4 flex items-center justify-between gap-4">
                            <div class="text-xs text-slate-400">
                                Status awal pesan akan tersimpan sebagai <span class="font-semibold text-slate-600">sending</span> lalu diperbarui oleh job pengiriman.
                            </div>

                            <button
                                type="submit"
                                :disabled="busy || body.trim().length === 0"
                                class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                            >
                                <span x-show="!busy">Kirim ke WhatsApp</span>
                                <span x-show="busy">Mengirim...</span>
                            </button>
                        </div>
                    </form>
                </div>
            @endif
        </section>

        <section class="console-card-lift flex min-h-[42rem] flex-col overflow-hidden rounded-[32px] border border-slate-200/80 bg-white/95 shadow-[0_24px_70px_-36px_rgba(15,23,42,0.35)] backdrop-blur xl:h-[calc(100vh-11rem)]">
            <div x-ref="insightPane" class="flex min-h-0 flex-1 flex-col">
                @include('admin.chatbot.live-chats.partials.insight-pane')
            </div>
        </section>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function liveChatWorkspace(config) {
        return {
            selectedConversationId: config.selectedConversationId,
            listPollUrl: config.listPollUrl,
            conversationPollBase: config.conversationPollBase,
            markReadBase: config.markReadBase,
            currentQuery: config.currentQuery || {},
            csrfToken: config.csrfToken,
            lastUpdatedLabel: config.initialLastUpdated || '--:--:--',
            unreadTotal: Number(config.initialUnreadTotal || 0),
            isRefreshingList: false,
            isRefreshingThread: false,
            listIntervalMs: 12000,
            threadIntervalMs: 8000,
            listTimer: null,
            threadTimer: null,
            visibilityHandler: null,

            get isRefreshing() {
                return this.isRefreshingList || this.isRefreshingThread;
            },

            get statusLabel() {
                if (this.isRefreshing) {
                    return 'Memperbarui workspace...';
                }

                if (this.selectedConversationId) {
                    return 'Thread aktif dimonitor otomatis';
                }

                return 'List conversation dimonitor otomatis';
            },

            init() {
                this.markReadConversation();
                this.startPolling();

                this.visibilityHandler = () => {
                    if (!document.hidden) {
                        this.manualRefresh();
                    }
                };

                document.addEventListener('visibilitychange', this.visibilityHandler);
            },

            destroy() {
                if (this.listTimer) {
                    clearInterval(this.listTimer);
                }

                if (this.threadTimer) {
                    clearInterval(this.threadTimer);
                }

                if (this.visibilityHandler) {
                    document.removeEventListener('visibilitychange', this.visibilityHandler);
                }
            },

            startPolling() {
                this.listTimer = setInterval(() => {
                    this.refreshList();
                }, this.listIntervalMs);

                this.threadTimer = setInterval(() => {
                    if (this.selectedConversationId) {
                        this.refreshConversation();
                    }
                }, this.threadIntervalMs);
            },

            async manualRefresh() {
                await Promise.all([
                    this.refreshList(true),
                    this.selectedConversationId ? this.refreshConversation(true) : Promise.resolve(),
                ]);
            },

            shouldPauseListRefresh() {
                const activeElement = document.activeElement;

                return document.hidden
                    || (activeElement && activeElement.name === 'search');
            },

            shouldPauseThreadRefresh() {
                const activeElement = document.activeElement;
                const guardedFieldNames = ['message', 'body', 'tag', 'reason'];

                return document.hidden
                    || (activeElement && guardedFieldNames.includes(activeElement.name));
            },

            buildQuery(extra = {}) {
                return new URLSearchParams({
                    ...this.currentQuery,
                    ...extra,
                }).toString();
            },

            async refreshList(force = false) {
                if (this.isRefreshingList || (!force && this.shouldPauseListRefresh())) {
                    return;
                }

                this.isRefreshingList = true;

                try {
                    const query = this.buildQuery({
                        selected_conversation_id: this.selectedConversationId || '',
                    });

                    const response = await fetch(`${this.listPollUrl}?${query}`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(`List refresh failed with status ${response.status}`);
                    }

                    const payload = await response.json();

                    if (this.$refs.listPane && typeof payload.html === 'string') {
                        this.$refs.listPane.innerHTML = payload.html;
                    }

                    if (payload.meta?.refreshed_at) {
                        this.lastUpdatedLabel = payload.meta.refreshed_at;
                    }

                    if (typeof payload.meta?.unread_total !== 'undefined') {
                        this.unreadTotal = Number(payload.meta.unread_total || 0);
                    }
                } catch (error) {
                    console.error(error);
                } finally {
                    this.isRefreshingList = false;
                }
            },

            async refreshConversation(force = false) {
                if (!this.selectedConversationId || this.isRefreshingThread || (!force && this.shouldPauseThreadRefresh())) {
                    return;
                }

                this.isRefreshingThread = true;

                try {
                    const query = this.buildQuery();
                    const response = await fetch(`${this.conversationPollBase}/${this.selectedConversationId}/poll?${query}`, {
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                    });

                    if (!response.ok) {
                        throw new Error(`Conversation refresh failed with status ${response.status}`);
                    }

                    const payload = await response.json();

                    if (this.$refs.threadPane && typeof payload.thread_html === 'string') {
                        this.$refs.threadPane.innerHTML = payload.thread_html;
                    }

                    if (this.$refs.insightPane && typeof payload.insight_html === 'string') {
                        this.$refs.insightPane.innerHTML = payload.insight_html;
                    }

                    if (payload.meta?.refreshed_at) {
                        this.lastUpdatedLabel = payload.meta.refreshed_at;
                    }
                } catch (error) {
                    console.error(error);
                } finally {
                    this.isRefreshingThread = false;
                }
            },

            async markReadConversation() {
                if (!this.selectedConversationId) {
                    return;
                }

                try {
                    const response = await fetch(`${this.markReadBase}/${this.selectedConversationId}/mark-read`, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            'Content-Type': 'application/json',
                            'X-CSRF-TOKEN': this.csrfToken,
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: JSON.stringify({}),
                    });

                    if (!response.ok) {
                        throw new Error(`Mark read failed with status ${response.status}`);
                    }
                } catch (error) {
                    console.error(error);
                }
            },
        };
    }
</script>
@endpush
