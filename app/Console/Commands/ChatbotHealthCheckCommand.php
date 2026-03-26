<?php

namespace App\Console\Commands;

use App\Enums\AuditActionType;
use App\Models\AdminNotification;
use App\Services\Support\AuditLogService;
use App\Services\Support\ChatbotHealthService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ChatbotHealthCheckCommand extends Command
{
    protected $signature = 'chatbot:health-check';

    protected $description = 'Run chatbot health checks and report status of key components.';

    public function handle(
        ChatbotHealthService $healthService,
        AuditLogService      $audit,
    ): int {
        $this->line('');
        $this->line('<fg=cyan>╔══════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║       Chatbot Health Check               ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════════╝</>');
        $this->line('');

        $result = $healthService->run();

        // ── Print each check ─────────────────────────────────────────────────
        $rows = [];
        foreach ($result['checks'] as $check) {
            $icon = match ($check['status']) {
                'ok'       => '<fg=green>✓ OK</>',
                'warning'  => '<fg=yellow>⚠ WARN</>',
                'critical' => '<fg=red>✗ CRIT</>',
                default    => '?',
            };
            $rows[] = [$icon, $check['key'], $check['message']];
        }

        $this->table(['Status', 'Check', 'Detail'], $rows);

        // ── Overall status ────────────────────────────────────────────────────
        $this->line('');
        $overall = $result['status'];
        match ($overall) {
            'ok'       => $this->line('<fg=green;options=bold>Overall Status: OK — semua komponen normal.</>')  ,
            'warning'  => $this->line('<fg=yellow;options=bold>Overall Status: WARNING — ada komponen yang perlu diperhatikan.</>'),
            'critical' => $this->line('<fg=red;options=bold>Overall Status: CRITICAL — ada masalah serius yang perlu ditangani segera.</>'),
            default    => $this->line("Overall Status: {$overall}"),
        };
        $this->line('');

        // ── Create AdminNotification if configured and issues found ──────────
        if (
            $overall !== 'ok'
            && config('chatbot.notifications.enabled', true)
            && config('chatbot.reliability.create_notification_on_health_issue', true)
        ) {
            $this->createHealthNotificationIfNeeded($result, $audit);
        }

        // ── Return exit code ─────────────────────────────────────────────────
        return match ($overall) {
            'critical' => self::FAILURE,
            default    => self::SUCCESS,
        };
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Create an AdminNotification for the health issue.
     *
     * Deduplication: if an unread 'health_issue' notification already exists
     * that was created within the last 30 minutes, skip creation to prevent
     * notification spam during scheduled runs.
     */
    private function createHealthNotificationIfNeeded(array $result, AuditLogService $audit): void
    {
        $deduplicationWindow = 30; // minutes

        try {
            $recentExists = AdminNotification::where('type', 'health_issue')
                ->where('is_read', false)
                ->where('created_at', '>=', now()->subMinutes($deduplicationWindow))
                ->exists();

            if ($recentExists) {
                $this->line('<fg=gray>Notifikasi health issue sudah ada dalam ' . $deduplicationWindow . ' menit terakhir — tidak dibuat duplikat.</>');
                return;
            }

            $issueChecks = array_filter($result['checks'], fn ($c) => $c['status'] !== 'ok');
            $issueLines  = array_map(fn ($c) => "[{$c['status']}] {$c['key']}: {$c['message']}", $issueChecks);

            AdminNotification::create([
                'type'    => 'health_issue',
                'title'   => 'Health Check: ' . strtoupper($result['status']),
                'body'    => "Health check menemukan isu ({$result['status']}):\n" . implode("\n", $issueLines),
                'payload' => [
                    'overall_status' => $result['status'],
                    'summary'        => $result['summary'],
                    'failed_checks'  => array_values(array_map(
                        fn ($c) => ['key' => $c['key'], 'status' => $c['status'], 'message' => $c['message']],
                        $issueChecks,
                    )),
                ],
                'is_read' => false,
            ]);

            $audit->record(AuditActionType::HealthCheckIssueNotified, [
                'message' => "Health check ({$result['status']}) — notifikasi dibuat untuk admin.",
                'context' => ['overall_status' => $result['status'], 'summary' => $result['summary']],
            ]);

            $this->line('<fg=yellow>Notifikasi health issue dibuat untuk admin.</>');

        } catch (\Throwable $e) {
            Log::error('[ChatbotHealthCheckCommand] Failed to create health notification', [
                'error' => $e->getMessage(),
            ]);
            $this->line('<fg=red>Gagal membuat notifikasi: ' . $e->getMessage() . '</>');
        }
    }
}
