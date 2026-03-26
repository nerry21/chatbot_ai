<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') — {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-sans antialiased bg-gray-100">

<div class="flex h-screen overflow-hidden">

    {{-- ── Sidebar ─────────────────────────────────────────────────────────── --}}
    <aside class="w-60 bg-gray-900 text-gray-200 flex-shrink-0 flex flex-col">
        <div class="px-5 py-4 border-b border-gray-700">
            <span class="text-base font-bold tracking-wide text-white">⚙ Chatbot Admin</span>
            <div class="text-xs text-gray-400 mt-0.5">{{ config('app.name') }}</div>
        </div>

        @php
            $unread = \App\Models\AdminNotification::where('is_read', false)->count();
            $navItems = [
                ['route' => 'admin.chatbot.dashboard',           'label' => 'Dashboard',      'icon' => '📊'],
                ['route' => 'admin.chatbot.conversations.index', 'label' => 'Percakapan',     'icon' => '💬'],
                ['route' => 'admin.chatbot.customers.index',     'label' => 'Customer',       'icon' => '👤'],
                ['route' => 'admin.chatbot.bookings.index',      'label' => 'Booking Lead',   'icon' => '📋'],
                ['route' => 'admin.chatbot.escalations.index',   'label' => 'Eskalasi',       'icon' => '🚨'],
                ['route' => 'admin.chatbot.notifications.index', 'label' => 'Notifikasi',     'icon' => '🔔'],
                ['route' => 'admin.chatbot.ai-logs.index',       'label' => 'AI Logs',        'icon' => '🤖'],
                ['route' => 'admin.chatbot.knowledge.index',     'label' => 'Knowledge Base', 'icon' => '📚'],
            ];
        @endphp

        <nav class="flex-1 px-3 py-4 space-y-0.5 overflow-y-auto">
            @foreach ($navItems as $item)
                @php $active = request()->routeIs($item['route'] . '*'); @endphp
                <a href="{{ route($item['route']) }}"
                   class="flex items-center gap-2.5 px-3 py-2 rounded-md text-sm transition-colors
                          {{ $active ? 'bg-indigo-600 text-white font-medium' : 'text-gray-300 hover:bg-gray-700 hover:text-white' }}">
                    <span>{{ $item['icon'] }}</span>
                    <span class="flex-1">{{ $item['label'] }}</span>
                    @if ($item['route'] === 'admin.chatbot.notifications.index' && $unread > 0)
                        <span class="bg-red-500 text-white text-xs rounded-full px-1.5 py-0.5 leading-none">{{ $unread }}</span>
                    @endif
                </a>
            @endforeach
        </nav>

        <div class="px-4 py-3 border-t border-gray-700 text-xs text-gray-500">
            <div>Masuk sebagai: <span class="text-gray-300">{{ auth()->user()?->name }}</span></div>
            @if (auth()->user()?->isChatbotAdmin())
                <span class="inline-block mt-1 bg-indigo-700 text-indigo-200 text-xs px-2 py-0.5 rounded">Admin</span>
            @elseif (auth()->user()?->isChatbotOperator())
                <span class="inline-block mt-1 bg-gray-700 text-gray-300 text-xs px-2 py-0.5 rounded">Operator</span>
            @endif
        </div>
    </aside>

    {{-- ── Main Area ───────────────────────────────────────────────────────── --}}
    <div class="flex-1 flex flex-col overflow-hidden">

        {{-- Top bar --}}
        <header class="bg-white border-b border-gray-200 px-6 py-3 flex items-center justify-between flex-shrink-0">
            <h1 class="text-lg font-semibold text-gray-800">@yield('title', 'Admin Dashboard')</h1>
            <div class="flex items-center gap-4">
                <a href="{{ route('admin.chatbot.notifications.index') }}"
                   class="relative text-gray-500 hover:text-gray-700">
                    🔔
                    @if ($unread > 0)
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs rounded-full w-4 h-4 flex items-center justify-center leading-none">{{ $unread > 9 ? '9+' : $unread }}</span>
                    @endif
                </a>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button type="submit" class="text-sm text-gray-500 hover:text-red-600 transition-colors">Logout</button>
                </form>
            </div>
        </header>

        {{-- Flash messages --}}
        <div class="px-6">
            @if (session('success'))
                <div class="mt-4 bg-green-50 border border-green-200 text-green-800 rounded-md px-4 py-3 text-sm">
                    {{ session('success') }}
                </div>
            @endif
            @if (session('error'))
                <div class="mt-4 bg-red-50 border border-red-200 text-red-800 rounded-md px-4 py-3 text-sm">
                    {{ session('error') }}
                </div>
            @endif
        </div>

        {{-- Page content --}}
        <main class="flex-1 overflow-y-auto p-6">
            @yield('content')
        </main>
    </div>

</div>

</body>
</html>
