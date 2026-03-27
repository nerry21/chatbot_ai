<?php

namespace App\Services\Chatbot;

use App\Enums\BookingFlowState;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\Booking\BookingConfirmationService;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\WaLog;

class HumanEscalationService
{
    public function __construct(
        private readonly WhatsAppSenderService $senderService,
        private readonly BookingConfirmationService $confirmationService,
        private readonly ConversationStateService $stateService,
    ) {}

    public function escalateQuestion(Conversation $conversation, Customer $customer, string $reason): void
    {
        $conversation->takeoverBy(null);
        $conversation->update([
            'needs_human' => true,
            'escalation_reason' => $reason,
        ]);
        $this->syncEscalationState($conversation, $reason);

        EscalateConversationToAdminJob::dispatch(
            $conversation->id,
            $reason,
            'normal',
        );

        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
            WaLog::warning('[HumanEscalation] escalation not forwarded because admin phone is missing', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'reason' => $reason,
            ]);

            return;
        }

        $result = $this->senderService->sendText(
            $adminPhone,
            'Bos, ini ada pertanyaan dari nomor '.ltrim((string) $customer->phone_e164, '+').', bisa bantu jawab ya bos?',
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'context' => 'question_escalation',
            ],
        );

        WaLog::info('[HumanEscalation] escalation forwarded to admin', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'admin_phone' => WaLog::maskPhone($adminPhone),
            'reason' => $reason,
            'status' => $result['status'],
        ]);

        if ($result['status'] !== 'sent') {
            WaLog::warning('[HumanEscalation] escalation forward did not send successfully', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'admin_phone' => WaLog::maskPhone($adminPhone),
                'status' => $result['status'],
                'error' => $result['error'],
            ]);
        }
    }

    public function forwardBooking(Conversation $conversation, Customer $customer, BookingRequest $booking): void
    {
        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
            WaLog::warning('[HumanEscalation] booking not forwarded because admin phone is missing', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
            ]);

            return;
        }

        $summary = $this->confirmationService->buildAdminSummary(
            booking: $booking,
            customerPhone: $customer->phone_e164 ?? '-',
        );

        $result = $this->senderService->sendText(
            $adminPhone,
            $summary,
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'booking_id' => $booking->id,
                'context' => 'booking_forward',
            ],
        );

        WaLog::info('[HumanEscalation] booking forwarded to admin', [
            'conversation_id' => $conversation->id,
            'booking_id' => $booking->id,
            'admin_phone' => WaLog::maskPhone($adminPhone),
            'status' => $result['status'],
        ]);
    }

    private function adminPhone(): string
    {
        return trim((string) config('chatbot.jet.admin_phone', ''));
    }

    private function syncEscalationState(Conversation $conversation, string $reason): void
    {
        $this->stateService->put($conversation, 'needs_human_escalation', true);
        $this->stateService->put($conversation, 'admin_takeover', true);
        $this->stateService->put($conversation, 'waiting_for', 'admin');
        $this->stateService->put($conversation, 'waiting_reason', $reason);
        $this->stateService->put($conversation, 'booking_intent_status', BookingFlowState::Closed->value);
    }
}
