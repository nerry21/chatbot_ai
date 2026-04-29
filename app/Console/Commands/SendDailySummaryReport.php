<?php

namespace App\Console\Commands;

use App\Services\Chatbot\DailySummaryReportService;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\WaLog;
use Illuminate\Console\Command;
use Throwable;

class SendDailySummaryReport extends Command
{
    protected $signature = 'chatbot:daily-summary {--date= : Override date (Y-m-d)} {--dry-run : Generate but do not send}';

    protected $description = 'Generate dan kirim daily summary bot stats ke nomor admin via WhatsApp';

    public function __construct(
        private readonly DailySummaryReportService $reportService,
        private readonly WhatsAppSenderService $sender,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dateOpt = $this->option('date');
        $date = $dateOpt ? \Carbon\Carbon::parse($dateOpt, 'Asia/Jakarta') : now('Asia/Jakarta');
        $dryRun = (bool) $this->option('dry-run');

        try {
            $report = $this->reportService->generate($date);

            $this->info("Generated summary for {$date->toDateString()}:");
            $this->line($report['text']);

            if ($dryRun) {
                $this->warn('DRY-RUN — not sending.');
                return self::SUCCESS;
            }

            $recipients = (array) config('chatbot.daily_summary.recipients', []);

            if ($recipients === []) {
                $this->error('No recipients configured (chatbot.daily_summary.recipients empty).');
                return self::FAILURE;
            }

            $sent = 0;
            $failed = 0;
            foreach ($recipients as $phone) {
                $result = $this->sender->sendText(
                    (string) $phone,
                    $report['text'],
                    ['source' => 'daily_summary_report']
                );

                if (($result['status'] ?? null) === 'success') {
                    $sent++;
                    $this->info("✅ Sent to {$phone}");
                } else {
                    $failed++;
                    $this->error("❌ Failed to {$phone}: ".($result['error'] ?? 'unknown'));
                }
            }

            WaLog::info('[DailySummaryReport] Completed', [
                'date' => $date->toDateString(),
                'sent' => $sent,
                'failed' => $failed,
                'stats' => $report['stats'],
            ]);

            return $failed === 0 ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $e) {
            WaLog::error('[DailySummaryReport] Exception', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            $this->error('Exception: '.$e->getMessage());
            return self::FAILURE;
        }
    }
}
