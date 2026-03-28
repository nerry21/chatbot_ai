@props([
    'title' => null,
    'description' => null,
    'padding' => 'md',
])

@php
    $paddingClass = match ($padding) {
        'none' => '',
        'sm' => 'p-4',
        'lg' => 'p-8',
        default => 'p-6',
    };
@endphp

<section {{ $attributes->merge(['class' => 'console-card-lift relative rounded-[28px] border border-slate-200/80 bg-white/95 shadow-[0_20px_60px_-32px_rgba(15,23,42,0.35)] backdrop-blur']) }}>
    @if ($title || $description || isset($actions))
        <div class="flex flex-col gap-4 border-b border-slate-100 px-6 py-5 sm:flex-row sm:items-start sm:justify-between">
            <div>
                @if ($title)
                    <h3 class="text-sm font-semibold text-slate-900">{{ $title }}</h3>
                @endif
                @if ($description)
                    <p class="mt-1 text-sm leading-6 text-slate-500">{{ $description }}</p>
                @endif
            </div>

            @isset($actions)
                <div class="shrink-0">
                    {{ $actions }}
                </div>
            @endisset
        </div>
    @endif

    <div class="{{ $paddingClass }}">
        {{ $slot }}
    </div>
</section>
