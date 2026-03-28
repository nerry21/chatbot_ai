@php
    $selectedModeLabel = $selectedConversation?->currentOperationalModeLabel();
    $selectedModePalette = $selectedConversation?->currentOperationalModePalette();
    $selectedQuery = array_merge(['conversation' => $selectedConversation], request()->query());
    $customerTagLabels = collect($customerTags ?? [])->pluck('tag');
    $conversationTagLabels = collect($conversationTags ?? [])->pluck('tag');
@endphp

@if ($selectedConversation)
    <div class="border-b border-slate-100 px-6 py-5">
        <h2 class="text-sm font-semibold text-slate-900">Conversation Insight</h2>
        <p class="mt-1 text-sm text-slate-500">Ringkasan customer, slot booking, quick actions, catatan internal, dan jejak operasional.</p>
    </div>

    <div class="console-scrollbar flex-1 space-y-5 overflow-y-auto px-5 py-5">
        <x-admin.chatbot.panel title="Customer Profile" description="Informasi dasar customer, tag aktif, dan detail hubungan ke thread ini." padding="sm">
            <div class="space-y-4">
                <div>
                    <div class="text-base font-semibold text-slate-900">{{ $selectedConversation->customer?->name ?? 'Unknown customer' }}</div>
                    <div class="mt-1 text-sm text-slate-500">{{ $selectedConversation->customer?->phone_e164 ?? '-' }}</div>
                </div>

                <div class="space-y-3">
                    <div>
                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">Conversation Tags</div>
                        @if ($conversationTagLabels->isNotEmpty())
                            <div class="flex flex-wrap gap-2">
                                @foreach ($conversationTagLabels as $tag)
                                    <x-admin.chatbot.status-badge :value="$tag" palette="teal" size="sm" />
                                @endforeach
                            </div>
                        @else
                            <div class="text-sm text-slate-400">Belum ada tag conversation.</div>
                        @endif
                    </div>

                    <div>
                        <div class="mb-2 text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">Customer Tags</div>
                        @if ($customerTagLabels->isNotEmpty())
                            <div class="flex flex-wrap gap-2">
                                @foreach ($customerTagLabels as $tag)
                                    <x-admin.chatbot.status-badge :value="$tag" palette="indigo" size="sm" />
                                @endforeach
                            </div>
                        @else
                            <div class="text-sm text-slate-400">Belum ada tag customer.</div>
                        @endif
                    </div>
                </div>

                <dl class="space-y-3 text-sm">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500">Customer status</dt>
                        <dd class="font-medium text-slate-900">{{ $selectedConversation->customer?->status ?? '-' }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500">Total bookings</dt>
                        <dd class="font-medium text-slate-900">{{ number_format((int) ($selectedConversation->customer?->total_bookings ?? 0)) }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-slate-500">Last interaction</dt>
                        <dd class="font-medium text-slate-900">{{ $selectedConversation->customer?->last_interaction_at?->diffForHumans() ?? '-' }}</dd>
                    </div>
                </dl>

                <form method="POST" action="{{ route('admin.chatbot.conversations.tags.store', $selectedQuery) }}" x-data="{ busy: false }" @submit="busy = true" class="rounded-[22px] border border-slate-200 bg-slate-50 px-4 py-4">
                    @csrf
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <div class="text-sm font-semibold text-slate-900">Add Tag</div>
                        <x-admin.chatbot.status-badge value="internal" palette="slate" size="sm" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-[140px_minmax(0,1fr)]">
                        <select name="target" class="rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 outline-none focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                            <option value="conversation">Conversation</option>
                            <option value="customer">Customer</option>
                        </select>
                        <input
                            type="text"
                            name="tag"
                            maxlength="40"
                            placeholder="mis. vip, follow-up, refund-risk"
                            class="rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60"
                        >
                    </div>
                    <button type="submit" :disabled="busy" class="mt-3 inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                        <span x-show="!busy">Simpan Tag</span>
                        <span x-show="busy">Menyimpan...</span>
                    </button>
                </form>
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Quick Details" description="Snapshot status conversation dan operasional terbaru." padding="sm">
            <div class="space-y-3 text-sm">
                <div class="flex items-center justify-between gap-4">
                    <span class="text-slate-500">Assigned admin</span>
                    <span class="font-medium text-slate-900">
                        {{ $selectedConversation->assignedAdmin?->name ?? ($selectedConversation->assigned_admin_id ? 'Admin #' . $selectedConversation->assigned_admin_id : '-') }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-slate-500">Conversation mode</span>
                    <x-admin.chatbot.status-badge :value="$selectedModeLabel" :palette="$selectedModePalette" size="sm" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-slate-500">Urgency</span>
                    <x-admin.chatbot.status-badge :value="$selectedConversation->is_urgent ? 'urgent' : 'normal'" :palette="$selectedConversation->is_urgent ? 'red' : 'slate'" size="sm" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-slate-500">Bot paused</span>
                    <x-admin.chatbot.status-badge :value="$selectedConversation->bot_paused ? 'Paused' : 'Running'" :palette="$selectedConversation->bot_paused ? 'orange' : 'green'" size="sm" />
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-slate-500">Pause reason</span>
                    <span class="font-medium text-slate-900">{{ $selectedConversation->bot_paused_reason ?? '-' }}</span>
                </div>
                <div class="flex items-center justify-between gap-4">
                    <span class="text-slate-500">Escalation status</span>
                    @if ($latestEscalation?->status)
                        <x-admin.chatbot.status-badge :value="$latestEscalation->status" size="sm" />
                    @else
                        <span class="font-medium text-slate-900">-</span>
                    @endif
                </div>
                <div class="flex items-start justify-between gap-4">
                    <span class="text-slate-500">Last inbound</span>
                    <span class="max-w-[55%] text-right font-medium text-slate-900">{{ $lastInbound?->sent_at?->format('d M H:i') ?? '-' }}</span>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <span class="text-slate-500">Last outbound</span>
                    <span class="max-w-[55%] text-right font-medium text-slate-900">{{ $lastOutbound?->sent_at?->format('d M H:i') ?? '-' }}</span>
                </div>
                <div class="flex items-start justify-between gap-4">
                    <span class="text-slate-500">Last admin intervention</span>
                    <span class="max-w-[55%] text-right font-medium text-slate-900">{{ $selectedConversation->last_admin_intervention_at?->format('d M H:i') ?? '-' }}</span>
                </div>
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Quick Actions" description="Aksi operasional utama dengan guard dan audit yang jelas." padding="sm">
            <div class="grid grid-cols-2 gap-3">
                @if ($selectedConversation->isAdminTakeover())
                    <form method="POST" action="{{ route('admin.chatbot.conversations.release', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button type="submit" :disabled="busy" class="w-full rounded-2xl bg-emerald-600 px-4 py-3 text-sm font-medium text-white transition hover:bg-emerald-700 disabled:cursor-not-allowed disabled:opacity-70">
                            <span x-show="!busy">Release to Bot</span>
                            <span x-show="busy">Melepas...</span>
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.chatbot.conversations.takeover', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button type="submit" :disabled="busy" class="w-full rounded-2xl bg-orange-500 px-4 py-3 text-sm font-medium text-white transition hover:bg-orange-600 disabled:cursor-not-allowed disabled:opacity-70">
                            <span x-show="!busy">Take Over</span>
                            <span x-show="busy">Mengambil alih...</span>
                        </button>
                    </form>
                @endif

                <form method="POST" action="{{ route('admin.chatbot.conversations.status.escalate', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <input type="hidden" name="reason" value="manual_console_escalation">
                    <button type="submit" :disabled="busy" class="w-full rounded-2xl bg-red-500 px-4 py-3 text-sm font-medium text-white transition hover:bg-red-600 disabled:cursor-not-allowed disabled:opacity-70">
                        <span x-show="!busy">Mark Escalated</span>
                        <span x-show="busy">Menandai...</span>
                    </button>
                </form>

                <form method="POST" action="{{ route('admin.chatbot.conversations.status.urgent', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true">
                    @csrf
                    <input type="hidden" name="urgent" value="{{ $selectedConversation->is_urgent ? 0 : 1 }}">
                    <button type="submit" :disabled="busy" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-70">
                        <span x-show="!busy">{{ $selectedConversation->is_urgent ? 'Clear Urgent' : 'Mark Urgent' }}</span>
                        <span x-show="busy">Memproses...</span>
                    </button>
                </form>

                @if ((is_string($selectedConversation->status) ? $selectedConversation->status : $selectedConversation->status?->value) === 'closed')
                    <form method="POST" action="{{ route('admin.chatbot.conversations.status.reopen', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <button type="submit" :disabled="busy" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-70">
                            <span x-show="!busy">Reopen</span>
                            <span x-show="busy">Membuka...</span>
                        </button>
                    </form>
                @else
                    <form method="POST" action="{{ route('admin.chatbot.conversations.status.close', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true">
                        @csrf
                        <input type="hidden" name="reason" value="closed_from_console">
                        <button type="submit" :disabled="busy" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900 disabled:cursor-not-allowed disabled:opacity-70">
                            <span x-show="!busy">Close Conversation</span>
                            <span x-show="busy">Menutup...</span>
                        </button>
                    </form>
                @endif
            </div>

            <div class="mt-3 grid grid-cols-2 gap-3">
                <a href="{{ route('admin.chatbot.live-chats.show', $selectedQuery) }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                    Refresh
                </a>

                @if ($selectedConversation->customer)
                    <a href="{{ route('admin.chatbot.customers.show', $selectedConversation->customer) }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                        View Customer
                    </a>
                @endif

                <a href="{{ route('admin.chatbot.conversations.show', $selectedConversation) }}" class="col-span-2 inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                    Full Detail
                </a>
            </div>
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Booking Summary" description="Ringkasan data booking dan status review yang sudah dikumpulkan bot." padding="sm">
            @if ($collectedSlots->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Belum ada slot booking"
                    description="Summary booking akan muncul setelah flow booking mulai mengumpulkan data."
                    icon="briefcase"
                />
            @else
                <div class="space-y-3">
                    @foreach ($collectedSlots as $slot)
                        <div class="flex items-start justify-between gap-4 rounded-[20px] border border-slate-100 bg-slate-50 px-4 py-3">
                            <div class="text-sm text-slate-500">{{ $slot['label'] }}</div>
                            <div class="max-w-[58%] text-right">
                                @if (! empty($slot['palette']))
                                    <x-admin.chatbot.status-badge :value="$slot['value']" :palette="$slot['palette']" size="sm" />
                                @else
                                    <div class="text-sm font-medium text-slate-900">{{ $slot['value'] }}</div>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Internal Notes" description="Catatan internal conversation atau customer. Tidak pernah terkirim ke WhatsApp customer." padding="sm">
            <form method="POST" action="{{ route('admin.chatbot.conversations.notes.store', $selectedConversation) }}" x-data="{ busy: false }" @submit="busy = true" class="rounded-[22px] border border-slate-200 bg-slate-50 px-4 py-4">
                @csrf
                <div class="grid gap-3">
                    <select name="target" class="rounded-2xl border border-slate-200 bg-white px-3 py-3 text-sm text-slate-700 outline-none focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                        <option value="conversation">Conversation note</option>
                        <option value="customer">Customer note</option>
                    </select>
                    <textarea
                        name="body"
                        rows="3"
                        maxlength="3000"
                        placeholder="Tulis catatan internal untuk tim operasional..."
                        class="w-full rounded-[22px] border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60"
                    >{{ old('body') }}</textarea>
                </div>
                <button type="submit" :disabled="busy" class="mt-3 inline-flex items-center justify-center rounded-2xl bg-slate-900 px-4 py-2.5 text-sm font-medium text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60">
                    <span x-show="!busy">Simpan Note</span>
                    <span x-show="busy">Menyimpan...</span>
                </button>
            </form>

            @if ($internalNotes->isEmpty())
                <div class="mt-4">
                    <x-admin.chatbot.empty-state
                        title="Belum ada internal note"
                        description="Gunakan note untuk menyimpan konteks operasional tanpa mengirimnya ke customer."
                        icon="book"
                    />
                </div>
            @else
                <div class="mt-4 space-y-3">
                    @foreach ($internalNotes as $note)
                        <div class="rounded-[22px] border border-slate-100 bg-white px-4 py-4 shadow-sm">
                            <div class="flex items-start justify-between gap-4">
                                <div class="flex flex-wrap items-center gap-2">
                                    <x-admin.chatbot.status-badge :value="class_basename($note->noteable_type) === 'Customer' ? 'Customer Note' : 'Conversation Note'" :palette="class_basename($note->noteable_type) === 'Customer' ? 'indigo' : 'teal'" size="sm" />
                                    @if ($note->author?->name)
                                        <x-admin.chatbot.status-badge :value="$note->author->name" palette="slate" size="sm" />
                                    @endif
                                </div>
                                <div class="text-xs text-slate-400">{{ $note->created_at?->diffForHumans() ?? '-' }}</div>
                            </div>
                            <div class="mt-3 text-sm leading-6 text-slate-700">{{ $note->body }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="Audit Trail" description="Jejak aksi admin penting pada conversation ini." padding="sm">
            @if ($auditTrail->isEmpty())
                <x-admin.chatbot.empty-state
                    title="Belum ada audit trail"
                    description="Aksi takeover, release, notes, tag, close, dan reply admin akan muncul di sini."
                    icon="activity"
                />
            @else
                <div class="space-y-3">
                    @foreach ($auditTrail as $entry)
                        <div class="rounded-[22px] border border-slate-100 bg-slate-50 px-4 py-4">
                            <div class="flex items-start justify-between gap-4">
                                <div>
                                    <div class="text-sm font-semibold text-slate-900">{{ $entry->message ?? str_replace('_', ' ', $entry->action_type) }}</div>
                                    <div class="mt-1 flex flex-wrap items-center gap-2">
                                        <x-admin.chatbot.status-badge :value="$entry->action_type" size="sm" />
                                        @if ($entry->actor?->name)
                                            <x-admin.chatbot.status-badge :value="$entry->actor->name" palette="slate" size="sm" />
                                        @endif
                                    </div>
                                    @if (filled(data_get($entry->context, 'reason')))
                                        <div class="mt-2 text-xs text-slate-500">Reason: {{ data_get($entry->context, 'reason') }}</div>
                                    @elseif (filled(data_get($entry->context, 'tag')))
                                        <div class="mt-2 text-xs text-slate-500">Tag: {{ data_get($entry->context, 'tag') }}</div>
                                    @endif
                                </div>
                                <div class="text-right text-xs text-slate-400">
                                    <div>{{ $entry->created_at?->format('d M H:i') ?? '-' }}</div>
                                    <div class="mt-1">{{ $entry->created_at?->diffForHumans() ?? '-' }}</div>
                                </div>
                            </div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>

        <x-admin.chatbot.panel title="State Summary" description="State chatbot aktif yang masih disimpan di conversation memory." padding="sm">
            @if ($stateSummary->isEmpty())
                <x-admin.chatbot.empty-state
                    title="State belum ada"
                    description="Belum ada state aktif yang relevan untuk conversation ini."
                    icon="activity"
                />
            @else
                <div class="space-y-3">
                    @foreach ($stateSummary as $state)
                        <div class="rounded-[20px] border border-slate-100 bg-slate-50 px-4 py-3">
                            <div class="text-[11px] font-semibold uppercase tracking-[0.16em] text-slate-400">{{ $state['key'] }}</div>
                            <div class="mt-2 break-all text-sm text-slate-700">{{ $state['value'] }}</div>
                        </div>
                    @endforeach
                </div>
            @endif
        </x-admin.chatbot.panel>
    </div>
@else
    <div class="flex flex-1 items-center justify-center p-8">
        <x-admin.chatbot.empty-state
            title="Belum ada insight"
            description="Pilih salah satu conversation dari panel kiri untuk melihat profil, state, slot booking, dan jejak operasional."
            icon="users"
        />
    </div>
@endif
