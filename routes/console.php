<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ─────────────────────────────────────────────────────────────────────────────
// Chatbot Scheduler (Tahap 9 — Reliability & Retry Strategy)
//
// These commands are registered here using the Laravel 11+ functional scheduler
// style (Schedule:: facade in routes/console.php, loaded via bootstrap/app.php).
//
// To activate the scheduler, ensure the following cron entry exists on your
// server (one entry runs the entire scheduler):
//
//   * * * * * cd /path-to-project && php artisan schedule:run >> /dev/null 2>&1
//
// ─────────────────────────────────────────────────────────────────────────────

// Health check — runs every 30 minutes.
// Checks WhatsApp, LLM, failed messages, stale pending, escalations, queue.
// Creates an AdminNotification if issues are found (config-gated, deduplicated).
Schedule::command('chatbot:health-check')
    ->everyThirtyMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/chatbot-health.log'));

// Operational cleanup — runs daily at 02:00 (low-traffic window).
// Deletes old read notifications, audit logs, AI logs, closed escalations,
// and expired conversation states.
// --dry-run=0 executes real deletes (safe to run daily).
Schedule::command('chatbot:cleanup --dry-run=0')
    ->dailyAt('02:00')
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/chatbot-cleanup.log'));

Schedule::command('chatbot:reactivate-timed-out-bots --limit=100')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/chatbot-bot-auto-resume.log'));

Schedule::command('statuses:deactivate-expired')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->runInBackground()
    ->appendOutputTo(storage_path('logs/statuses-deactivate-expired.log'));
