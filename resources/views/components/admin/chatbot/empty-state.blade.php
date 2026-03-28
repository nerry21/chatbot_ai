@props([
    'title' => 'Belum ada data',
    'description' => 'Data akan muncul di sini setelah aktivitas mulai tercatat.',
    'icon' => 'sparkles',
])

<div {{ $attributes->merge(['class' => 'flex flex-col items-start gap-4 rounded-3xl border border-dashed border-slate-200 bg-slate-50/80 px-6 py-8 text-left']) }}>
    <div class="flex h-12 w-12 items-center justify-center rounded-2xl bg-white text-slate-500 shadow-sm ring-1 ring-slate-200">
        <x-admin.chatbot.icon :name="$icon" class="h-5 w-5" />
    </div>
    <div>
        <h3 class="text-sm font-semibold text-slate-800">{{ $title }}</h3>
        <p class="mt-1 text-sm leading-6 text-slate-500">{{ $description }}</p>
    </div>
    {{ $slot }}
</div>
