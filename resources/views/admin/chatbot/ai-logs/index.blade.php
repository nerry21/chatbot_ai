@extends('admin.chatbot.layouts.app')
@section('title', 'AI Logs')

@section('content')

{{-- ── Filters ─────────────────────────────────────────────────────────────── --}}
<form method="GET" class="bg-white rounded-lg border border-gray-200 p-4 mb-5 flex flex-wrap gap-3 items-end">
    <div>
        <label class="block text-xs text-gray-500 mb-1">Task Type</label>
        <select name="task_type" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($taskTypeOptions as $t)
                <option value="{{ $t }}" @selected(request('task_type') === $t)>{{ $t }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">Status</label>
        <select name="status" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($statusOptions as $s)
                <option value="{{ $s }}" @selected(request('status') === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    {{-- Tahap 10: quality label filter --}}
    <div>
        <label class="block text-xs text-gray-500 mb-1">Quality Label</label>
        <select name="quality_label" class="border border-gray-300 rounded-md px-3 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-indigo-300">
            <option value="">Semua</option>
            @foreach ($qualityLabelOptions as $ql)
                <option value="{{ $ql }}" @selected(request('quality_label') === $ql)>{{ str_replace('_', ' ', ucfirst($ql)) }}</option>
            @endforeach
        </select>
    </div>
    {{-- Tahap 10: knowledge hits filter --}}
    <div class="flex items-center gap-2 self-end pb-1.5">
        <input type="checkbox" id="has_knowledge_hits" name="has_knowledge_hits" value="1"
               @checked(request()->boolean('has_knowledge_hits'))
               class="rounded border-gray-300 text-indigo-600 focus:ring-indigo-300">
        <label for="has_knowledge_hits" class="text-sm text-gray-700 cursor-pointer">Ada Knowledge Hit</label>
    </div>
    <div>
        <label class="block text-xs text-gray-500 mb-1">ID Percakapan</label>
        <input type="number" name="conversation_id" value="{{ request('conversation_id') }}"
               placeholder="e.g. 42"
               class="border border-gray-300 rounded-md px-3 py-1.5 text-sm w-28 focus:outline-none focus:ring-2 focus:ring-indigo-300">
    </div>
    <button type="submit" class="bg-indigo-600 hover:bg-indigo-700 text-white text-sm px-4 py-1.5 rounded-md">Filter</button>
    <a href="{{ route('admin.chatbot.ai-logs.index') }}" class="text-sm text-gray-500 hover:text-gray-700 py-1.5">Reset</a>
</form>

{{-- ── Table ────────────────────────────────────────────────────────────────── --}}
<div class="bg-white rounded-lg border border-gray-200 overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 border-b border-gray-200 text-xs text-gray-500 uppercase tracking-wide">
            <tr>
                <th class="px-4 py-3 text-left">Task</th>
                <th class="px-4 py-3 text-left">Status</th>
                <th class="px-4 py-3 text-left">Quality</th>
                <th class="px-4 py-3 text-left">Knowledge</th>
                <th class="px-4 py-3 text-left">Provider / Model</th>
                <th class="px-4 py-3 text-left">Percakapan</th>
                <th class="px-4 py-3 text-left">Token (in/out)</th>
                <th class="px-4 py-3 text-left">Latency</th>
                <th class="px-4 py-3 text-left">Waktu</th>
                <th class="px-4 py-3 text-left">Detail</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            @forelse ($logs as $log)
                <tr class="hover:bg-gray-50 align-top">
                    <td class="px-4 py-3">
                        <span class="text-xs font-mono bg-blue-50 text-blue-700 px-1.5 py-0.5 rounded">{{ $log->task_type }}</span>
                    </td>
                    <td class="px-4 py-3">
                        @php
                            $statusClass = match($log->status) {
                                'success' => 'bg-green-100 text-green-700',
                                'failed'  => 'bg-red-100 text-red-700',
                                default   => 'bg-gray-100 text-gray-500',
                            };
                        @endphp
                        <span class="text-xs px-2 py-0.5 rounded {{ $statusClass }}">{{ $log->status }}</span>
                    </td>
                    {{-- Tahap 10: quality label badge --}}
                    <td class="px-4 py-3">
                        @if ($log->quality_label)
                            @php
                                $qlClass = match($log->quality_label) {
                                    'low_confidence' => 'bg-orange-100 text-orange-700',
                                    'fallback'       => 'bg-yellow-100 text-yellow-700',
                                    'knowledge_used' => 'bg-teal-100 text-teal-700',
                                    'faq_direct'     => 'bg-purple-100 text-purple-700',
                                    default          => 'bg-gray-100 text-gray-500',
                                };
                                $qlLabel = str_replace('_', ' ', $log->quality_label);
                            @endphp
                            <span class="text-xs px-1.5 py-0.5 rounded {{ $qlClass }}">{{ $qlLabel }}</span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                    {{-- Tahap 10: knowledge hits indicator --}}
                    <td class="px-4 py-3 text-center">
                        @if (!empty($log->knowledge_hits))
                            @php $hitCount = count($log->knowledge_hits); @endphp
                            <span title="{{ $hitCount }} artikel knowledge" class="text-xs bg-teal-50 text-teal-600 px-1.5 py-0.5 rounded font-mono">
                                ✓ {{ $hitCount }}
                            </span>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $log->provider ?? '—' }}<br>
                        <span class="text-gray-400">{{ $log->model ?? '—' }}</span>
                    </td>
                    <td class="px-4 py-3 text-xs">
                        @if ($log->conversation_id)
                            <a href="{{ route('admin.chatbot.conversations.show', $log->conversation_id) }}"
                               class="text-indigo-600 hover:underline">#{{ $log->conversation_id }}</a>
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $log->token_input ?? '—' }} / {{ $log->token_output ?? '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500">
                        {{ $log->latency_ms ? $log->latency_ms.'ms' : '—' }}
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-400 whitespace-nowrap">{{ $log->created_at->format('d M H:i:s') }}</td>
                    <td class="px-4 py-3">
                        @if ($log->error_message || $log->prompt_snapshot || $log->response_snapshot || !empty($log->knowledge_hits))
                            <details>
                                <summary class="text-xs text-indigo-600 cursor-pointer hover:underline">Lihat</summary>
                                <div class="mt-2 space-y-2 w-80">
                                    @if ($log->error_message)
                                        <div>
                                            <div class="text-xs text-red-500 font-medium">Error</div>
                                            <pre class="text-xs bg-red-50 p-2 rounded overflow-x-auto whitespace-pre-wrap">{{ $log->error_message }}</pre>
                                        </div>
                                    @endif
                                    @if (!empty($log->knowledge_hits))
                                        <div>
                                            <div class="text-xs text-teal-600 font-medium">Knowledge Hits ({{ count($log->knowledge_hits) }})</div>
                                            <div class="text-xs bg-teal-50 p-2 rounded space-y-1">
                                                @foreach ($log->knowledge_hits as $hit)
                                                    <div>
                                                        <span class="font-medium text-teal-700">{{ $hit['title'] ?? '—' }}</span>
                                                        <span class="text-gray-500">[{{ $hit['category'] ?? '—' }}]</span>
                                                        <span class="text-gray-400">skor: {{ $hit['score'] ?? '—' }}</span>
                                                    </div>
                                                @endforeach
                                            </div>
                                        </div>
                                    @endif
                                    @if ($log->prompt_snapshot)
                                        <div>
                                            <div class="text-xs text-gray-500 font-medium">Prompt</div>
                                            <pre class="text-xs bg-gray-50 p-2 rounded overflow-x-auto whitespace-pre-wrap max-h-40">{{ Str::limit($log->prompt_snapshot, 500) }}</pre>
                                        </div>
                                    @endif
                                    @if ($log->response_snapshot)
                                        <div>
                                            <div class="text-xs text-gray-500 font-medium">Response</div>
                                            <pre class="text-xs bg-gray-50 p-2 rounded overflow-x-auto whitespace-pre-wrap max-h-40">{{ Str::limit($log->response_snapshot, 500) }}</pre>
                                        </div>
                                    @endif
                                </div>
                            </details>
                        @else
                            <span class="text-xs text-gray-300">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="px-4 py-8 text-center text-gray-400 text-sm">Tidak ada log.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">{{ $logs->links() }}</div>

@endsection
