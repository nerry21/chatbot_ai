<?php

namespace App\Console\Commands;

use App\Enums\AuditActionType;
use App\Services\Support\AuditLogService;
use App\Services\Support\ChatbotCleanupService;
use Illuminate\Console\Command;

class ChatbotCleanupCommand extends Command
{
    /**
     * --dry-run=1  → preview only, no rows deleted (default from config).
     * --dry-run=0  → execute actual deletes.
     */
    protected $signature = 'chatbot:cleanup
                            {--dry-run= : Override dry-run mode (1=preview, 0=execute). Defaults to config value.}';

    protected $description = 'Run operational cleanup: old notifications, audit logs, AI logs, escalations, expired states.';

    public function handle(
        ChatbotCleanupService $cleanupService,
        AuditLogService       $audit,
    ): int {
        // Resolve dry-run mode:
        // 1. Explicit --dry-run option takes precedence.
        // 2. Falls back to config default.
        $dryRunOption = $this->option('dry-run');

        $dryRun = $dryRunOption !== null
            ? (bool) (int) $dryRunOption
            : (bool) config('chatbot.reliability.cleanup.dry_run_default', true);

        $this->line('');
        $this->line('<fg=cyan>╔══════════════════════════════════════════╗</>');
        $this->line('<fg=cyan>║       Chatbot Operational Cleanup        ║</>');
        $this->line('<fg=cyan>╚══════════════════════════════════════════╝</>');
        $this->line('');

        if ($dryRun) {
            $this->line('<fg=yellow;options=bold>Mode: DRY RUN — tidak ada data yang dihapus. Gunakan --dry-run=0 untuk eksekusi nyata.</>');
        } else {
            $this->line('<fg=red;options=bold>Mode: EXECUTE — baris yang memenuhi kriteria akan dihapus.</>');

            if (! $this->confirm('Lanjutkan cleanup? Data yang dihapus tidak dapat dikembalikan.', false)) {
                $this->line('Cleanup dibatalkan.');
                return self::SUCCESS;
            }
        }

        $this->line('');

        $result = $cleanupService->run($dryRun);

        // ── Display results ──────────────────────────────────────────────────
        $rows = [];
        $totalAffected = 0;

        foreach ($result['actions'] as $action) {
            $verb       = $dryRun ? 'akan dihapus' : 'dihapus';
            $affectedStr = $action['affected'] > 0
                ? "<fg=yellow>{$action['affected']}</> baris {$verb}"
                : '<fg=green>0 baris</>';

            $rows[]       = [$action['key'], $affectedStr, $action['note'] ?? ''];
            $totalAffected += $action['affected'];
        }

        $this->table(['Task', 'Hasil', 'Keterangan'], $rows);

        $this->line('');
        if ($dryRun) {
            $this->line("<fg=yellow>DRY RUN selesai — {$totalAffected} baris akan dihapus jika mode execute dijalankan.</>");
        } else {
            $this->line("<fg=green>Cleanup selesai — {$totalAffected} baris dihapus.</>");

            // Audit the cleanup execution (only when actually run, not dry-run)
            $audit->record(AuditActionType::CleanupCommandRun, [
                'message' => "Cleanup dijalankan — {$totalAffected} baris dihapus.",
                'context' => [
                    'dry_run'       => false,
                    'total_affected'=> $totalAffected,
                    'actions'       => array_map(
                        fn ($a) => ['key' => $a['key'], 'affected' => $a['affected']],
                        $result['actions'],
                    ),
                ],
            ]);
        }

        $this->line('');

        return self::SUCCESS;
    }
}
