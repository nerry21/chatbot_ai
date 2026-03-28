@extends('admin.chatbot.layouts.app')

@section('title', 'Escalations')
@section('page-subtitle', 'Daftar kasus yang membutuhkan human handling, assignment admin, atau penyelesaian manual.')

@section('content')
<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Human Intervention"
        title="Escalation queue"
        description="Pantau seluruh escalation terbuka, assign ke admin, dan resolve dengan audit trail yang tetap aman."
    />

    <x-admin.chatbot.panel title="Filter escalations" description="Saring berdasarkan status dan prioritas untuk memudahkan triase kasus.">
        <form method="GET" class="grid gap-4 lg:grid-cols-[220px_220px_auto_auto]">
            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Status</span>
                <select name="status" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                    <option value="">Semua status</option>
                    @foreach ($statusOptions as $status)
                        <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Prioritas</span>
                <select name="priority" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                    <option value="">Semua prioritas</option>
                    @foreach ($priorityOptions as $priority)
                        <option value="{{ $priority }}" @selected(request('priority') === $priority)>{{ ucfirst($priority) }}</option>
                    @endforeach
                </select>
            </label>

            <div class="flex items-end gap-3">
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('admin.chatbot.escalations.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                    Reset
                </a>
            </div>
        </form>
    </x-admin.chatbot.panel>

    <x-admin.chatbot.table-shell title="Escalation list" description="Kasus operasional yang dipicu guardrail, human takeover, atau kebutuhan admin manual.">
        @if ($escalations->isEmpty())
            <div class="p-6">
                <x-admin.chatbot.empty-state
                    title="Tidak ada escalation"
                    description="Saat ini tidak ada escalation yang tercatat atau sesuai filter yang dipilih."
                    icon="alert"
                />
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50/80">
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <th class="px-6 py-4">Customer</th>
                        <th class="px-6 py-4">Reason</th>
                        <th class="px-6 py-4">Priority</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Assigned</th>
                        <th class="px-6 py-4">Created</th>
                        <th class="px-6 py-4 text-right">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($escalations as $escalation)
                        <tr class="align-top transition hover:bg-slate-50/70">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900">{{ $escalation->conversation->customer?->name ?? 'Unknown customer' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $escalation->conversation->customer?->phone_e164 ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="max-w-md text-sm leading-7 text-slate-600">{{ \Illuminate\Support\Str::limit($escalation->reason ?? $escalation->summary ?? 'Tidak ada alasan.', 130) }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.chatbot.status-badge :value="$escalation->priority" />
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.chatbot.status-badge :value="$escalation->status" />
                            </td>
                            <td class="px-6 py-4 text-slate-600">
                                {{ $escalation->assigned_admin_id ? 'Admin #' . $escalation->assigned_admin_id : '-' }}
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $escalation->created_at?->diffForHumans() ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $escalation->created_at?->format('d M Y H:i') ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4 text-right">
                                <div class="flex flex-col items-end gap-2">
                                    <a href="{{ route('admin.chatbot.conversations.show', $escalation->conversation_id) }}" class="inline-flex items-center gap-2 rounded-2xl border border-slate-200 bg-white px-4 py-2 text-xs font-medium text-slate-700 transition hover:border-slate-300 hover:text-slate-900">
                                        Buka chat
                                    </a>

                                    @if (in_array($escalation->status, ['open', 'assigned'], true))
                                        <form method="POST" action="{{ route('admin.chatbot.escalations.assign', $escalation) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-amber-500 px-3 py-2 text-xs font-medium text-white transition hover:bg-amber-600">
                                                Assign ke Saya
                                            </button>
                                        </form>
                                    @endif

                                    @if (! in_array($escalation->status, ['resolved', 'closed'], true))
                                        <form method="POST" action="{{ route('admin.chatbot.escalations.resolve', $escalation) }}" onsubmit="return confirm('Tandai eskalasi ini sebagai resolved?')">
                                            @csrf
                                            <button type="submit" class="inline-flex items-center justify-center rounded-xl bg-emerald-600 px-3 py-2 text-xs font-medium text-white transition hover:bg-emerald-700">
                                                Resolve
                                            </button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            {{ $escalations->links() }}
        </x-slot:footer>
    </x-admin.chatbot.table-shell>
</div>
@endsection
