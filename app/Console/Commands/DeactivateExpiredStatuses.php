<?php

namespace App\Console\Commands;

use App\Models\StatusUpdate;
use Illuminate\Console\Command;

class DeactivateExpiredStatuses extends Command
{
    protected $signature = 'statuses:deactivate-expired';

    protected $description = 'Menonaktifkan status yang sudah melewati 24 jam atau expires_at';

    public function handle(): int
    {
        $count = StatusUpdate::query()
            ->where('is_active', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'is_active' => false,
            ]);

        $this->info("Status expired dinonaktifkan: {$count}");

        return self::SUCCESS;
    }
}
