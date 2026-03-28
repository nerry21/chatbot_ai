<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Chatbot Console') - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <style>
        [x-cloak] { display: none !important; }

        @keyframes console-fade-up {
            from {
                opacity: 0;
                transform: translateY(10px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        .console-fade-up {
            animation: console-fade-up .45s ease-out both;
        }

        .console-fade-up-delay {
            animation: console-fade-up .55s ease-out both;
            animation-delay: .08s;
        }

        .console-card-lift {
            transition: transform .2s ease, box-shadow .2s ease, border-color .2s ease;
        }

        .console-card-lift:hover {
            transform: translateY(-2px);
        }

        .console-scrollbar::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .console-scrollbar::-webkit-scrollbar-track {
            background: transparent;
        }

        .console-scrollbar::-webkit-scrollbar-thumb {
            background: rgba(148, 163, 184, .45);
            border-radius: 9999px;
            border: 2px solid transparent;
            background-clip: padding-box;
        }
    </style>

    @stack('styles')
</head>
<body class="min-h-screen bg-slate-100 text-slate-900 antialiased">
@php
    $pageTitle = trim($__env->yieldContent('title', 'Chatbot Console'));
    $pageSubtitle = trim($__env->yieldContent('page-subtitle'));
    $consoleMeta = $chatbotConsoleMeta ?? [
        'unread_notifications' => 0,
        'open_escalations' => 0,
        'active_takeovers' => 0,
    ];
    $consoleNav = $chatbotConsoleNav ?? [];
@endphp

<div x-data="{ sidebarOpen: false }" class="min-h-screen">
    <div
        x-cloak
        x-show="sidebarOpen"
        x-transition.opacity
        class="fixed inset-0 z-40 bg-slate-950/50 backdrop-blur-sm lg:hidden"
        @click="sidebarOpen = false"
    ></div>

    <div class="flex min-h-screen">
        <aside
            class="console-scrollbar fixed inset-y-0 left-0 z-50 flex w-[19rem] flex-col overflow-y-auto border-r border-slate-800/60 bg-slate-950 text-slate-200 shadow-2xl transition-transform duration-300 lg:translate-x-0"
            :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full lg:translate-x-0'"
        >
            <div class="border-b border-white/10 px-6 pb-6 pt-7">
                <a href="{{ route('admin.chatbot.dashboard') }}" class="block">
                    <div class="inline-flex items-center gap-3 rounded-2xl bg-white/6 px-3 py-2 ring-1 ring-white/10">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-sky-500 shadow-lg shadow-indigo-900/40">
                            <x-admin.chatbot.icon name="sparkles" class="h-5 w-5 text-white" />
                        </div>
                        <div>
                            <div class="text-sm font-semibold tracking-[0.22em] text-white/80">CHATBOT</div>
                            <div class="text-lg font-semibold text-white">Admin Console</div>
                        </div>
                    </div>
                </a>

                <div class="mt-6 rounded-[24px] border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <div class="text-xs font-medium uppercase tracking-[0.22em] text-slate-400">Realtime Ready</div>
                            <div class="mt-1 text-sm text-slate-200">Monitoring chatbot, takeover, dan kualitas AI dalam satu panel.</div>
                        </div>
                        <div class="rounded-full bg-emerald-500/15 px-2.5 py-1 text-[11px] font-medium text-emerald-300 ring-1 ring-emerald-500/30">
                            Online
                        </div>
                    </div>

                    <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                        <div class="rounded-2xl bg-slate-900/70 px-3 py-2 ring-1 ring-white/5">
                            <div class="text-lg font-semibold text-white">{{ number_format($consoleMeta['active_takeovers'] ?? 0) }}</div>
                            <div class="text-[11px] text-slate-400">Takeover</div>
                        </div>
                        <div class="rounded-2xl bg-slate-900/70 px-3 py-2 ring-1 ring-white/5">
                            <div class="text-lg font-semibold text-white">{{ number_format($consoleMeta['open_escalations'] ?? 0) }}</div>
                            <div class="text-[11px] text-slate-400">Open</div>
                        </div>
                        <div class="rounded-2xl bg-slate-900/70 px-3 py-2 ring-1 ring-white/5">
                            <div class="text-lg font-semibold text-white">{{ number_format($consoleMeta['unread_notifications'] ?? 0) }}</div>
                            <div class="text-[11px] text-slate-400">Notif</div>
                        </div>
                    </div>
                </div>
            </div>

            <nav class="flex-1 px-4 py-5">
                <div class="mb-3 px-3 text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">Workspace</div>

                <div class="space-y-1.5">
                    @foreach ($consoleNav as $item)
                        @php
                            $isActive = collect($item['patterns'] ?? [])->contains(fn ($pattern) => request()->routeIs($pattern));
                        @endphp
                        <a
                            href="{{ route($item['route']) }}"
                            class="console-nav-item group flex items-center gap-3 rounded-2xl px-3.5 py-3 transition duration-200 {{ $isActive ? 'bg-white text-slate-900 shadow-lg shadow-slate-950/15' : 'text-slate-300 hover:bg-white/6 hover:text-white' }}"
                        >
                            <div class="flex h-11 w-11 items-center justify-center rounded-2xl {{ $isActive ? 'bg-slate-100 text-slate-900' : 'bg-white/6 text-slate-300 group-hover:bg-white/10 group-hover:text-white' }}">
                                <x-admin.chatbot.icon :name="$item['icon'] ?? 'dashboard'" class="h-5 w-5" />
                            </div>

                            <div class="min-w-0 flex-1">
                                <div class="truncate text-sm font-semibold">{{ $item['label'] }}</div>
                                <div class="truncate text-xs {{ $isActive ? 'text-slate-500' : 'text-slate-400 group-hover:text-slate-300' }}">{{ $item['caption'] ?? '' }}</div>
                            </div>

                            @if (! empty($item['badge']))
                                <span class="inline-flex min-w-8 items-center justify-center rounded-full border px-2 py-1 text-[11px] font-semibold {{ $isActive ? 'border-slate-200 bg-white text-slate-700' : 'border-orange-400/30 bg-orange-400/15 text-orange-200' }}">
                                    {{ $item['badge'] }}
                                </span>
                            @endif
                        </a>
                    @endforeach
                </div>
            </nav>

            <div class="border-t border-white/10 px-5 py-5">
                <div class="rounded-[24px] border border-white/10 bg-white/5 p-4">
                    <div class="flex items-center gap-3">
                        <div class="flex h-11 w-11 items-center justify-center rounded-2xl bg-white/10 text-sm font-semibold text-white">
                            {{ strtoupper(mb_substr(auth()->user()?->name ?? 'A', 0, 1)) }}
                        </div>
                        <div class="min-w-0">
                            <div class="truncate text-sm font-semibold text-white">{{ auth()->user()?->name ?? 'Admin' }}</div>
                            <div class="text-xs text-slate-400">
                                @if (auth()->user()?->isChatbotAdmin())
                                    Chatbot Admin
                                @elseif (auth()->user()?->isChatbotOperator())
                                    Chatbot Operator
                                @else
                                    Authenticated User
                                @endif
                            </div>
                        </div>
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        <a href="{{ route('admin.chatbot.notifications.index') }}" class="inline-flex items-center gap-2 rounded-xl bg-white/8 px-3 py-2 text-xs font-medium text-slate-200 transition hover:bg-white/12 hover:text-white">
                            <x-admin.chatbot.icon name="bell" class="h-4 w-4" />
                            Notifikasi
                        </a>
                        <form method="POST" action="{{ route('logout') }}" class="flex-1">
                            @csrf
                            <button type="submit" class="w-full rounded-xl border border-white/10 bg-slate-900/80 px-3 py-2 text-xs font-medium text-slate-300 transition hover:border-red-400/30 hover:text-red-200">
                                Logout
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        </aside>

        <div class="flex min-h-screen min-w-0 flex-1 flex-col lg:pl-[19rem]">
            <header class="sticky top-0 z-30 border-b border-white/70 bg-slate-100/85 backdrop-blur-xl">
                <div class="mx-auto flex w-full max-w-[1600px] items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                    <div class="flex items-start gap-3">
                        <button
                            type="button"
                            class="inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-900 lg:hidden"
                            @click="sidebarOpen = ! sidebarOpen"
                        >
                            <x-admin.chatbot.icon name="menu" class="h-5 w-5" />
                        </button>

                        <div>
                            <div class="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">Admin Chatbot Console</div>
                            <h1 class="mt-1 text-2xl font-semibold tracking-tight text-slate-900">{{ $pageTitle }}</h1>
                            @if ($pageSubtitle !== '')
                                <p class="mt-1 text-sm leading-6 text-slate-500">{{ $pageSubtitle }}</p>
                            @endif
                        </div>
                    </div>

                    <div class="flex items-center gap-2 sm:gap-3">
                        <div class="hidden items-center gap-2 xl:flex">
                            <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Takeover</div>
                                <div class="text-sm font-semibold text-slate-900">{{ number_format($consoleMeta['active_takeovers'] ?? 0) }}</div>
                            </div>
                            <div class="rounded-2xl border border-slate-200 bg-white px-3 py-2 shadow-sm">
                                <div class="text-[11px] uppercase tracking-[0.18em] text-slate-400">Escalations</div>
                                <div class="text-sm font-semibold text-slate-900">{{ number_format($consoleMeta['open_escalations'] ?? 0) }}</div>
                            </div>
                        </div>

                        <a href="{{ route('admin.chatbot.notifications.index') }}" class="relative inline-flex h-11 w-11 items-center justify-center rounded-2xl border border-slate-200 bg-white text-slate-700 shadow-sm transition hover:border-slate-300 hover:text-slate-900">
                            <x-admin.chatbot.icon name="bell" class="h-5 w-5" />
                            @if (($consoleMeta['unread_notifications'] ?? 0) > 0)
                                <span class="absolute right-2 top-2 inline-flex min-w-5 items-center justify-center rounded-full bg-red-500 px-1.5 py-0.5 text-[10px] font-semibold text-white">
                                    {{ ($consoleMeta['unread_notifications'] ?? 0) > 9 ? '9+' : $consoleMeta['unread_notifications'] }}
                                </span>
                            @endif
                        </a>

                        @yield('page-actions')
                    </div>
                </div>
            </header>

            <main class="flex-1">
                <div class="mx-auto w-full max-w-[1600px] px-4 py-6 sm:px-6 lg:px-8 lg:py-8">
                    @if (session('success'))
                        <div class="console-fade-up mb-5 rounded-[24px] border border-emerald-200 bg-emerald-50 px-5 py-4 text-sm text-emerald-700 shadow-sm">
                            {{ session('success') }}
                        </div>
                    @endif

                    @if (session('error'))
                        <div class="console-fade-up mb-5 rounded-[24px] border border-red-200 bg-red-50 px-5 py-4 text-sm text-red-700 shadow-sm">
                            {{ session('error') }}
                        </div>
                    @endif

                    <div class="console-fade-up">
                        @yield('content')
                    </div>
                </div>
            </main>
        </div>
    </div>
</div>

@stack('scripts')
</body>
</html>
