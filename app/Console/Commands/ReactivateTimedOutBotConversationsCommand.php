<?php

namespace App\Console\Commands;

use App\Jobs\ReactivateTimedOutBotConversationsJob;
use Illuminate\Console\Command;

class ReactivateTimedOutBotConversationsCommand extends Command
{
    protected $signature = 'chatbot:reactivate-timed-out-bots {--limit=100}';

    protected $description = 'Reactivate bot automatically for admin-takeover conversations that have timed out.';

    public function handle(): int
    {
        $limit = max(1, (int) $this->option('limit'));
        ReactivateTimedOutBotConversationsJob::dispatchSync($limit);
        $this->info('Bot auto-resume scan selesai dijalankan.');

        return self::SUCCESS;
    }
}
