@php
    $query = request()->query();
    $query[$queryKey ?? 'scope'] = $key;
    $isActive = ($currentValue ?? $currentScope ?? null) === $key;
@endphp

<a
    href="{{ route('admin.chatbot.live-chats.index', $query) }}"
    class="inline-flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition {{ $isActive ? 'bg-slate-900 text-white shadow-lg shadow-slate-900/15' : 'bg-slate-100 text-slate-600 hover:bg-slate-200 hover:text-slate-900' }}"
>
    {{ $label }}
</a>
