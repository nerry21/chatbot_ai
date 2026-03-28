@props([
    'title' => null,
    'description' => null,
])

<x-admin.chatbot.panel :title="$title" :description="$description" padding="none" {{ $attributes }}>
    <div class="overflow-x-auto">
        {{ $slot }}
    </div>

    @isset($footer)
        <div class="border-t border-slate-100 px-6 py-4">
            {{ $footer }}
        </div>
    @endisset
</x-admin.chatbot.panel>
