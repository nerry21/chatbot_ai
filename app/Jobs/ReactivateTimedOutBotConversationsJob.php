<?php

namespace App\Jobs;

use App\Services\Chatbot\BotAutomationToggleService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReactivateTimedOutBotConversationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public readonly int $limit = 100) {}

    public function handle(BotAutomationToggleService $toggleService): void
    {
        $toggleService->reactivateExpiredConversations($this->limit);
    }
}
