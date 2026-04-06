<?php

namespace App\Console\Commands;

use App\Services\Chatbot\TravelChatbotOrchestratorService;
use App\Services\Chatbot\TravelWhatsAppPipelineService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessTravelChatbotFollowUps extends Command
{
    protected $signature = 'travel-chatbot:process-followups';

    protected $description = 'Send 15-minute follow-ups and auto-cancel abandoned travel chatbot bookings';

    public function __construct(
        protected TravelChatbotOrchestratorService $orchestrator,
        protected TravelWhatsAppPipelineService $travelPipeline,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $result = $this->orchestrator->processPendingFollowUps([
                'send_reply' => function (string $phone, string $message, array $context) {
                    return $this->travelPipeline->sendFollowUpToCustomerPhone($phone, $message);
                },
            ]);

            Log::info('[TravelFollowUpCommand] Processed travel follow-ups', $result);

            $this->info('Travel chatbot follow-ups processed: ' . ($result['count'] ?? 0));

            return self::SUCCESS;
        } catch (Throwable $e) {
            Log::error('[TravelFollowUpCommand] Failed processing follow-ups', [
                'error' => $e->getMessage(),
            ]);

            $this->error('Failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
}
