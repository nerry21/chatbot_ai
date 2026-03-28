<?php

namespace App\Services\Chatbot;

use App\Enums\BookingFlowState;
use App\Enums\BookingStatus;
use App\Jobs\EscalateConversationToAdminJob;
use App\Models\BookingRequest;
use App\Models\Conversation;
use App\Models\Customer;
use App\Services\WhatsApp\WhatsAppSenderService;
use App\Support\WaLog;

class HumanEscalationService
{
    private const STATE_ADMIN_BOOKING_FORWARD = 'admin_booking_forward';
    private const STATE_ADMIN_QUESTION_ESCALATION = 'admin_question_escalation';

    public function __construct(
        private readonly WhatsAppSenderService $senderService,
        private readonly ConversationStateService $stateService,
        private readonly AdminHandoffFormatterService $formatter,
    ) {}

    public function escalateQuestion(Conversation $conversation, Customer $customer, string $reason): void
    {
        $wasAdminTakeover = $conversation->isAdminTakeover();
        $alreadyEscalated = $wasAdminTakeover && $this->questionEscalationAlreadySent($conversation);

        if (! $wasAdminTakeover) {
            $conversation->takeoverBy(null);
        }

        $conversation->update([
            'needs_human' => true,
            'escalation_reason' => $reason,
        ]);
        $this->syncEscalationState($conversation, $reason);

        if ($alreadyEscalated) {
            WaLog::info('[HumanEscalation] skipped duplicate question escalation forward', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
            ]);

            return;
        }

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

        $this->rememberQuestionEscalation($conversation, $customer, $reason, 'pending');

        $result = $this->senderService->sendText(
            $adminPhone,
            $this->formatter->formatQuestionEscalation((string) ($customer->phone_e164 ?? '')),
            [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'context' => 'question_escalation',
            ],
        );
        $this->rememberQuestionEscalation($conversation, $customer, $reason, $result['status']);

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
        if (! in_array($booking->booking_status, [BookingStatus::Confirmed, BookingStatus::Paid, BookingStatus::Completed], true)) {
            WaLog::warning('[HumanEscalation] booking forward skipped because booking is not finalized', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
                'booking_status' => $booking->booking_status?->value,
            ]);

            return;
        }

        $adminPhone = $this->adminPhone();

        if ($adminPhone === '') {
            WaLog::warning('[HumanEscalation] booking not forwarded because admin phone is missing', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
            ]);

            return;
        }

        if ($this->bookingAlreadyForwarded($conversation, $booking)) {
            WaLog::info('[HumanEscalation] skipped duplicate booking forward', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
                'admin_phone' => WaLog::maskPhone($adminPhone),
            ]);

            return;
        }

        $summary = $this->formatter->formatBookingForward(
            booking: $booking,
            customerPhone: $customer->phone_e164 ?? '-',
        );
        $this->rememberBookingForward($conversation, $booking, $customer, 'pending');

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
        $this->rememberBookingForward($conversation, $booking, $customer, $result['status']);

        WaLog::info('[HumanEscalation] booking forwarded to admin', [
            'conversation_id' => $conversation->id,
            'booking_id' => $booking->id,
            'admin_phone' => WaLog::maskPhone($adminPhone),
            'status' => $result['status'],
        ]);

        if ($result['status'] !== 'sent') {
            WaLog::warning('[HumanEscalation] booking forward did not send successfully', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
                'admin_phone' => WaLog::maskPhone($adminPhone),
                'status' => $result['status'],
                'error' => $result['error'],
            ]);
        }
    }

    private function bookingAlreadyForwarded(Conversation $conversation, BookingRequest $booking): bool
    {
        $state = $this->stateService->get($conversation, self::STATE_ADMIN_BOOKING_FORWARD);

        return is_array($state)
            && (int) ($state['booking_id'] ?? 0) === $booking->id;
    }

    private function questionEscalationAlreadySent(Conversation $conversation): bool
    {
        $state = $this->stateService->get($conversation, self::STATE_ADMIN_QUESTION_ESCALATION);

        return is_array($state) && $state !== [];
    }

    private function rememberBookingForward(
        Conversation $conversation,
        BookingRequest $booking,
        Customer $customer,
        string $status,
    ): void {
        $this->stateService->put($conversation, self::STATE_ADMIN_BOOKING_FORWARD, [
            'booking_id' => $booking->id,
            'booking_status' => $booking->booking_status?->value,
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone_e164,
            'status' => $status,
            'sent_at' => now()->toIso8601String(),
        ]);
    }

    private function rememberQuestionEscalation(
        Conversation $conversation,
        Customer $customer,
        string $reason,
        string $status,
    ): void {
        $this->stateService->put($conversation, self::STATE_ADMIN_QUESTION_ESCALATION, [
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone_e164,
            'reason' => $reason,
            'status' => $status,
            'sent_at' => now()->toIso8601String(),
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
        $this->stateService->put($conversation, 'waiting_admin_takeover', true);
        $this->stateService->put($conversation, 'booking_intent_status', BookingFlowState::WaitingAdminTakeover->value);
    }
}
