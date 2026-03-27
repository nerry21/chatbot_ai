<?php

namespace App\Services\Chatbot;

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
    ) {
    }

    public function escalateQuestion(Conversation $conversation, Customer $customer, string $reason): void
    {
        $conversation->takeoverBy(null);
        $conversation->update([
            'needs_human' => true,
        ]);

        EscalateConversationToAdminJob::dispatch(
            $conversation->id,
            $reason,
            'normal',
        );

        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
            return;
        }

        $result = $this->senderService->sendText(
            $adminPhone,
            'Bos, ini ada pertanyaan dari nomor ' . ltrim((string) $customer->phone_e164, '+') . ', bisa bantu jawab ya bos?',
            [
                'conversation_id' => $conversation->id,
                'customer_id'     => $customer->id,
                'context'         => 'question_escalation',
            ],
        );

        WaLog::info('[HumanEscalation] escalation forwarded to admin', [
            'conversation_id' => $conversation->id,
            'customer_id'     => $customer->id,
            'status'          => $result['status'],
        ]);
    }

    public function forwardBooking(Conversation $conversation, Customer $customer, BookingRequest $booking): void
    {
        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
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
                'customer_id'     => $customer->id,
                'booking_id'      => $booking->id,
                'context'         => 'booking_forward',
            ],
        );

        WaLog::info('[HumanEscalation] booking forwarded to admin', [
            'conversation_id' => $conversation->id,
            'booking_id'      => $booking->id,
            'status'          => $result['status'],
        ]);
    }

    private function adminPhone(): string
    {
        return trim((string) config('chatbot.jet.admin_phone', ''));
    }
}
