@props([
    'label',
    'value' => 0,
    'hint' => null,
    'icon' => 'activity',
    'tone' => 'slate',
    'href' => null,
])

@php
    $tones = [
        'slate' => 'from-slate-900 to-slate-700 text-white ring-slate-900/10',
        'indigo' => 'from-indigo-600 to-indigo-500 text-white ring-indigo-600/20',
        'blue' => 'from-sky-600 to-blue-500 text-white ring-sky-600/20',
        'green' => 'from-emerald-600 to-teal-500 text-white ring-emerald-600/20',
        'amber' => 'from-amber-500 to-orange-500 text-white ring-amber-500/20',
        'red' => 'from-rose-600 to-red-500 text-white ring-rose-600/20',
        'purple' => 'from-violet-600 to-fuchsia-500 text-white ring-violet-600/20',
    ];

    $display = is_numeric($value)
        ? number_format((float) $value, 0, ',', '.')
        : $value;

@endphp

@if ($href)
    <a
        href="{{ $href }}"
        {{ $attributes->merge(['class' => 'console-card-lift group relative overflow-hidden rounded-[26px] border border-white/70 bg-gradient-to-br ' . ($tones[$tone] ?? $tones['slate']) . ' p-5 shadow-[0_24px_60px_-30px_rgba(15,23,42,0.55)] ring-1']) }}
    >
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.2),transparent_45%)]"></div>
        <div class="relative flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-[0.22em] text-white/70">{{ $label }}</p>
                <div class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $display }}</div>
                @if ($hint)
                    <p class="mt-2 text-sm text-white/80">{{ $hint }}</p>
                @endif
            </div>
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/12 ring-1 ring-white/20">
                <x-admin.chatbot.icon :name="$icon" class="h-5 w-5 text-white" />
            </div>
        </div>

        <div class="relative mt-5 inline-flex items-center gap-2 text-sm font-medium text-white/90 transition group-hover:text-white">
            Buka
            <x-admin.chatbot.icon name="arrow-up-right" class="h-4 w-4" />
        </div>
    </a>
@else
    <div {{ $attributes->merge(['class' => 'console-card-lift group relative overflow-hidden rounded-[26px] border border-white/70 bg-gradient-to-br ' . ($tones[$tone] ?? $tones['slate']) . ' p-5 shadow-[0_24px_60px_-30px_rgba(15,23,42,0.55)] ring-1']) }}>
        <div class="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(255,255,255,0.2),transparent_45%)]"></div>
        <div class="relative flex items-start justify-between gap-4">
            <div>
                <p class="text-xs font-medium uppercase tracking-[0.22em] text-white/70">{{ $label }}</p>
                <div class="mt-4 text-3xl font-semibold tracking-tight text-white sm:text-4xl">{{ $display }}</div>
                @if ($hint)
                    <p class="mt-2 text-sm text-white/80">{{ $hint }}</p>
                @endif
            </div>
            <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white/12 ring-1 ring-white/20">
                <x-admin.chatbot.icon :name="$icon" class="h-5 w-5 text-white" />
            </div>
        </div>
    </div>
@endif
