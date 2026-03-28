@extends('admin.chatbot.layouts.app')

@section('title', 'AI Logs')
@section('page-subtitle', 'Audit trail reasoning, extraction, fallback, quality label, dan anomaly log dari seluruh pipeline AI chatbot.')

@section('content')
<div class="space-y-6">
    <x-admin.chatbot.section-heading
        kicker="Observability"
        title="AI execution logs"
        description="Lihat task AI, status, quality label, latency, token usage, dan knowledge hit untuk evaluasi reasoning pipeline."
    />

    <x-admin.chatbot.panel title="Filter AI logs" description="Saring berdasarkan task, status, quality label, knowledge hits, atau conversation tertentu.">
        <form method="GET" class="grid gap-4 lg:grid-cols-5">
            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Task type</span>
                <select name="task_type" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                    <option value="">Semua task</option>
                    @foreach ($taskTypeOptions as $taskType)
                        <option value="{{ $taskType }}" @selected(request('task_type') === $taskType)>{{ $taskType }}</option>
                    @endforeach
                </select>
            </label>

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
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Quality label</span>
                <select name="quality_label" class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60">
                    <option value="">Semua label</option>
                    @foreach ($qualityLabelOptions as $qualityLabel)
                        <option value="{{ $qualityLabel }}" @selected(request('quality_label') === $qualityLabel)>{{ str_replace('_', ' ', $qualityLabel) }}</option>
                    @endforeach
                </select>
            </label>

            <label class="block">
                <span class="mb-2 block text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Conversation ID</span>
                <input
                    type="number"
                    name="conversation_id"
                    value="{{ request('conversation_id') }}"
                    placeholder="e.g. 42"
                    class="w-full rounded-2xl border border-slate-200 bg-white px-4 py-3 text-sm text-slate-700 shadow-sm outline-none transition placeholder:text-slate-400 focus:border-slate-300 focus:ring-4 focus:ring-slate-200/60"
                >
            </label>

            <div class="flex items-end gap-3">
                <label class="flex flex-1 items-center gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3">
                    <input type="checkbox" name="has_knowledge_hits" value="1" @checked(request()->boolean('has_knowledge_hits')) class="rounded border-slate-300 text-slate-900 focus:ring-slate-300">
                    <span class="text-sm font-medium text-slate-700">Knowledge hits</span>
                </label>
                <button type="submit" class="inline-flex items-center justify-center rounded-2xl bg-slate-900 px-5 py-3 text-sm font-medium text-white transition hover:bg-slate-800">
                    Terapkan
                </button>
                <a href="{{ route('admin.chatbot.ai-logs.index') }}" class="inline-flex items-center justify-center rounded-2xl border border-slate-200 bg-white px-5 py-3 text-sm font-medium text-slate-600 transition hover:border-slate-300 hover:text-slate-900">
                    Reset
                </a>
            </div>
        </form>
    </x-admin.chatbot.panel>

    <x-admin.chatbot.table-shell title="AI log list" description="Observability view untuk seluruh task AI yang dieksekusi di percakapan customer.">
        @if ($logs->isEmpty())
            <div class="p-6">
                <x-admin.chatbot.empty-state
                    title="Belum ada AI log"
                    description="Log reasoning, classification, extraction, dan response composer akan muncul di sini setelah pipeline berjalan."
                    icon="sparkles"
                />
            </div>
        @else
            <table class="min-w-full divide-y divide-slate-100 text-sm">
                <thead class="bg-slate-50/80">
                    <tr class="text-left text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">
                        <th class="px-6 py-4">Task</th>
                        <th class="px-6 py-4">Status</th>
                        <th class="px-6 py-4">Quality</th>
                        <th class="px-6 py-4">Provider / Model</th>
                        <th class="px-6 py-4">Conversation</th>
                        <th class="px-6 py-4">Tokens</th>
                        <th class="px-6 py-4">Latency</th>
                        <th class="px-6 py-4">Detail</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($logs as $log)
                        <tr class="align-top transition hover:bg-slate-50/70">
                            <td class="px-6 py-4">
                                <div class="font-semibold text-slate-900">{{ $log->task_type }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $log->created_at?->format('d M Y H:i:s') ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                <x-admin.chatbot.status-badge :value="$log->status" />
                            </td>
                            <td class="px-6 py-4">
                                @if ($log->quality_label)
                                    <x-admin.chatbot.status-badge :value="$log->quality_label" />
                                @else
                                    <span class="text-sm text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-700">{{ $log->provider ?? '-' }}</div>
                                <div class="mt-1 text-xs text-slate-500">{{ $log->model ?? '-' }}</div>
                            </td>
                            <td class="px-6 py-4">
                                @if ($log->conversation_id)
                                    <a href="{{ route('admin.chatbot.conversations.show', $log->conversation_id) }}" class="text-sm font-medium text-slate-700 transition hover:text-slate-900">
                                        #{{ $log->conversation_id }}
                                    </a>
                                @else
                                    <span class="text-sm text-slate-400">-</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-slate-600">
                                {{ $log->token_input ?? 0 }} / {{ $log->token_output ?? 0 }}
                            </td>
                            <td class="px-6 py-4 text-slate-600">
                                {{ $log->latency_ms ? $log->latency_ms . ' ms' : '-' }}
                            </td>
                            <td class="px-6 py-4">
                                @if ($log->error_message || $log->prompt_snapshot || $log->response_snapshot || ! empty($log->knowledge_hits))
                                    <details class="group rounded-2xl border border-slate-200 bg-white p-3">
                                        <summary class="cursor-pointer list-none text-sm font-medium text-slate-700 group-open:text-slate-900">Lihat payload</summary>
                                        <div class="mt-4 space-y-3">
                                            @if ($log->error_message)
                                                <div>
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-[0.16em] text-red-500">Error</div>
                                                    <pre class="overflow-x-auto rounded-2xl bg-red-50 p-3 text-xs text-red-700">{{ $log->error_message }}</pre>
                                                </div>
                                            @endif

                                            @if (! empty($log->knowledge_hits))
                                                <div>
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-[0.16em] text-teal-500">Knowledge Hits</div>
                                                    <div class="space-y-2 rounded-2xl bg-teal-50 p-3 text-xs text-teal-800">
                                                        @foreach ($log->knowledge_hits as $hit)
                                                            <div>
                                                                <div class="font-semibold">{{ $hit['title'] ?? '-' }}</div>
                                                                <div class="text-teal-700/80">{{ $hit['category'] ?? '-' }} · skor {{ $hit['score'] ?? '-' }}</div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @endif

                                            @if ($log->prompt_snapshot)
                                                <div>
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Prompt</div>
                                                    <pre class="max-h-48 overflow-auto rounded-2xl bg-slate-50 p-3 text-xs text-slate-700">{{ \Illuminate\Support\Str::limit($log->prompt_snapshot, 1200) }}</pre>
                                                </div>
                                            @endif

                                            @if ($log->response_snapshot)
                                                <div>
                                                    <div class="mb-1 text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Response</div>
                                                    <pre class="max-h-48 overflow-auto rounded-2xl bg-slate-50 p-3 text-xs text-slate-700">{{ \Illuminate\Support\Str::limit($log->response_snapshot, 1200) }}</pre>
                                                </div>
                                            @endif
                                        </div>
                                    </details>
                                @else
                                    <span class="text-sm text-slate-400">-</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif

        <x-slot:footer>
            {{ $logs->links() }}
        </x-slot:footer>
    </x-admin.chatbot.table-shell>
</div>
@endsection
