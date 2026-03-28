@props([
    'name',
    'class' => 'h-5 w-5',
])

@switch($name)
    @case('dashboard')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.75 5.75h6.5v5.5h-6.5zm8 0h6.5v8.5h-6.5zm-8 7h6.5v6.5h-6.5zm8 10.5h6.5v-3.5h-6.5z" />
        </svg>
        @break
    @case('chat')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 18.5c-2.485 0-4.5-1.79-4.5-4V8.75c0-2.21 2.015-4 4.5-4h8c2.485 0 4.5 1.79 4.5 4v5.75c0 2.21-2.015 4-4.5 4H12l-4.5 3v-3z" />
        </svg>
        @break
    @case('users')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15.5 18.5v-.75A3.75 3.75 0 0 0 11.75 14h-3.5A3.75 3.75 0 0 0 4.5 17.75v.75M10 10.5a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm8.25 8v-.5a3 3 0 0 0-2.25-2.9m-1.25-4.35a2.75 2.75 0 1 0 0-5.5" />
        </svg>
        @break
    @case('briefcase')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9 6.25V5.5A1.75 1.75 0 0 1 10.75 3.75h2.5A1.75 1.75 0 0 1 15 5.5v.75m-11 2h16a1.25 1.25 0 0 1 1.25 1.25v7.75A2.5 2.5 0 0 1 18.75 19.75H5.25a2.5 2.5 0 0 1-2.5-2.5V9.5A1.25 1.25 0 0 1 4 8.25Zm6 4.75h4" />
        </svg>
        @break
    @case('alert')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="m11.25 4.75-7 12.25a1.5 1.5 0 0 0 1.3 2.25h14.9a1.5 1.5 0 0 0 1.3-2.25l-7-12.25a1.5 1.5 0 0 0-2.6 0ZM12 9.25v4.5m0 3h.008" />
        </svg>
        @break
    @case('sparkles')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="m12 3 1.3 3.95L17.25 8.25 13.3 9.55 12 13.5l-1.3-3.95L6.75 8.25l3.95-1.3L12 3Zm6 10 .8 2.2L21 16l-2.2.8L18 19l-.8-2.2L15 16l2.2-.8L18 13ZM6 14l1 3 3 1-3 1-1 3-1-3-3-1 3-1 1-3Z" />
        </svg>
        @break
    @case('book')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M6.75 4.75h9.5A2.75 2.75 0 0 1 19 7.5v11.75H8.5a2.75 2.75 0 0 0-2.75 2.75V7.5A2.75 2.75 0 0 1 8.5 4.75Zm0 0v14.5m3-10h5.5" />
        </svg>
        @break
    @case('settings')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M10.25 4.75h3.5l.55 2.1a5.8 5.8 0 0 1 1.3.75l2.02-.82 1.75 3.03-1.48 1.53c.08.38.12.77.12 1.16 0 .4-.04.79-.12 1.18l1.48 1.52-1.75 3.03-2.02-.82c-.4.3-.83.55-1.3.74l-.55 2.11h-3.5l-.55-2.1a5.85 5.85 0 0 1-1.3-.75l-2.02.82-1.75-3.03 1.48-1.52a5.7 5.7 0 0 1 0-2.34L4.83 9.8l1.75-3.03 2.02.82c.4-.3.83-.56 1.3-.75l.35-2.1ZM12 15.25A3.25 3.25 0 1 0 12 8.75a3.25 3.25 0 0 0 0 6.5Z" />
        </svg>
        @break
    @case('shield')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 3.75 5.75 6v5.07c0 4.42 2.67 8.4 6.75 10.18 4.08-1.78 6.75-5.76 6.75-10.18V6L12 3.75Z" />
        </svg>
        @break
    @case('activity')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 12h3.75l2.25-4.5 4 9 2.25-4.5H20" />
        </svg>
        @break
    @case('refresh')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M20 12a8 8 0 1 1-2.34-5.66M20 4v6h-6" />
        </svg>
        @break
    @case('arrow-up-right')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="m8 16 8-8m-5 0h5v5" />
        </svg>
        @break
    @case('bell')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.5 19.25a2.5 2.5 0 0 1-5 0m8.5-1.5H6l1.15-1.53a2.5 2.5 0 0 0 .5-1.5v-2.47a4.35 4.35 0 1 1 8.7 0v2.47a2.5 2.5 0 0 0 .5 1.5L18 17.75Z" />
        </svg>
        @break
    @case('menu')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 7h16M4 12h16M4 17h16" />
        </svg>
        @break
    @case('close')
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <path stroke-linecap="round" stroke-linejoin="round" d="m6 6 12 12M18 6 6 18" />
        </svg>
        @break
    @default
        <svg {{ $attributes->merge(['class' => $class]) }} viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8">
            <circle cx="12" cy="12" r="8.25" />
        </svg>
@endswitch
