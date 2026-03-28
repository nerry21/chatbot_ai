<?php

namespace App\Providers;

use App\Models\AdminNotification;
use App\Models\Conversation;
use App\Models\Escalation;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\View;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('admin.chatbot.*', function ($view): void {
            $meta = [
                'unread_notifications' => $this->safeCount(fn (): int => AdminNotification::where('is_read', false)->count()),
                'open_escalations' => $this->safeCount(fn (): int => Escalation::where('status', 'open')->count()),
                'active_takeovers' => $this->safeCount(fn (): int => Conversation::humanTakeoverActive()->count()),
            ];

            $view->with('chatbotConsoleMeta', $meta);
            $view->with('chatbotConsoleNav', [
                [
                    'route' => 'admin.chatbot.dashboard',
                    'patterns' => ['admin.chatbot.dashboard'],
                    'label' => 'Dashboard',
                    'caption' => 'Overview operasional',
                    'icon' => 'dashboard',
                ],
                [
                    'route' => 'admin.chatbot.live-chats.index',
                    'patterns' => ['admin.chatbot.live-chats.*', 'admin.chatbot.conversations.*'],
                    'label' => 'Live Chats',
                    'caption' => 'Thread & takeover',
                    'icon' => 'chat',
                    'badge' => $meta['active_takeovers'] > 0 ? (string) $meta['active_takeovers'] : null,
                ],
                [
                    'route' => 'admin.chatbot.customers.index',
                    'patterns' => ['admin.chatbot.customers.*'],
                    'label' => 'Customers',
                    'caption' => 'Kontak & profil',
                    'icon' => 'users',
                ],
                [
                    'route' => 'admin.chatbot.bookings.index',
                    'patterns' => ['admin.chatbot.bookings.*'],
                    'label' => 'Bookings / Leads',
                    'caption' => 'Pipeline booking',
                    'icon' => 'briefcase',
                ],
                [
                    'route' => 'admin.chatbot.escalations.index',
                    'patterns' => ['admin.chatbot.escalations.*'],
                    'label' => 'Escalations',
                    'caption' => 'Kasus perlu human',
                    'icon' => 'alert',
                    'badge' => $meta['open_escalations'] > 0 ? (string) $meta['open_escalations'] : null,
                ],
                [
                    'route' => 'admin.chatbot.ai-logs.index',
                    'patterns' => ['admin.chatbot.ai-logs.*'],
                    'label' => 'AI Logs',
                    'caption' => 'Trace & evaluasi',
                    'icon' => 'sparkles',
                ],
                [
                    'route' => 'admin.chatbot.knowledge.index',
                    'patterns' => ['admin.chatbot.knowledge.*'],
                    'label' => 'Knowledge Base',
                    'caption' => 'Grounded facts',
                    'icon' => 'book',
                ],
                [
                    'route' => 'admin.chatbot.settings.index',
                    'patterns' => ['admin.chatbot.settings.*'],
                    'label' => 'Settings',
                    'caption' => 'Konfigurasi & guard',
                    'icon' => 'settings',
                ],
            ]);
        });
    }

    private function safeCount(callable $callback): int
    {
        try {
            return (int) $callback();
        } catch (\Throwable) {
            return 0;
        }
    }
}
