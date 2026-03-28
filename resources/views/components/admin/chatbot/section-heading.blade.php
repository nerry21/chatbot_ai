@props([
    'kicker' => null,
    'title',
    'description' => null,
    'href' => null,
    'linkLabel' => 'Lihat semua',
])

<div {{ $attributes->merge(['class' => 'flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between']) }}>
    <div class="max-w-3xl">
        @if ($kicker)
            <div class="mb-2 text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">{{ $kicker }}</div>
        @endif
        <h2 class="text-xl font-semibold tracking-tight text-slate-900 sm:text-2xl">{{ $title }}</h2>
        @if ($description)
            <p class="mt-2 text-sm leading-6 text-slate-500">{{ $description }}</p>
        @endif
    </div>

    <div class="flex items-center gap-3">
        @isset($actions)
            {{ $actions }}
        @endisset

        @if ($href)
            <a href="{{ $href }}" class="inline-flex items-center gap-2 text-sm font-medium text-slate-600 transition hover:text-slate-900">
                {{ $linkLabel }}
                <x-admin.chatbot.icon name="arrow-up-right" class="h-4 w-4" />
            </a>
        @endif
    </div>
</div>
