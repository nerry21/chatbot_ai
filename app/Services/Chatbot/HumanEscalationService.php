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
use Illuminate\Support\Facades\Cache;

class HumanEscalationService
{
    private const STATE_ADMIN_BOOKING_FORWARD = 'admin_booking_forward';
    private const STATE_ADMIN_QUESTION_ESCALATION = 'admin_question_escalation';

    public function __construct(
        private readonly WhatsAppSenderService $senderService,
        private readonly ConversationStateService $stateService,
        private readonly AdminHandoffFormatterService $formatter,
        private readonly ConversationTakeoverService $takeoverService,
    ) {}

    public function escalateQuestion(Conversation $conversation, Customer $customer, string $reason): void
    {
        $wasAdminTakeover = $conversation->isAutomationSuppressed();
        $alreadyEscalated = $wasAdminTakeover && $this->questionEscalationAlreadySent($conversation);

        if (! $wasAdminTakeover) {
            $conversation = $this->takeoverService->pauseForEscalation($conversation, $reason);
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

        $allPhones = $this->allAdminPhones();

        if ($allPhones === []) {
            WaLog::warning('[HumanEscalation] escalation not forwarded because admin phone is missing', [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'reason' => $reason,
            ]);

            return;
        }

        $this->rememberQuestionEscalation($conversation, $customer, $reason, 'pending');

        $escalationMessage = $this->formatter->formatQuestionEscalation((string) ($customer->phone_e164 ?? ''));
        $allResults = $this->sendToAllAdmins($escalationMessage, [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'context' => 'question_escalation',
        ]);

        $anySuccess = collect($allResults)->contains('status', 'sent');
        $this->rememberQuestionEscalation($conversation, $customer, $reason, $anySuccess ? 'sent' : 'failed');

        WaLog::info('[HumanEscalation] escalation forwarded to admins', [
            'conversation_id' => $conversation->id,
            'customer_id' => $customer->id,
            'admin_count' => count($allResults),
            'reason' => $reason,
            'results' => $allResults,
        ]);
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

        $bookingForwardLock = Cache::lock('chatbot:booking-forward:'.$booking->id, 30);

        if (! $bookingForwardLock->get()) {
            WaLog::warning('[HumanEscalation] booking forward lock busy', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
            ]);

            return;
        }

        $adminPhone = $this->adminPhone();
        $allPhones = $this->allAdminPhones();
        $summary = $this->formatter->formatBookingForward(
            booking: $booking,
            customerPhone: $customer->phone_e164 ?? '-',
        );
        $adminForwardHash = $this->adminForwardHash($booking, $summary);

        try {
            if ($allPhones === []) {
                WaLog::warning('[HumanEscalation] booking not forwarded because admin phone is missing', [
                    'conversation_id' => $conversation->id,
                    'booking_id' => $booking->id,
                ]);

                return;
            }

            if ($this->bookingAlreadyForwarded($conversation, $booking, $adminForwardHash)) {
                WaLog::info('[HumanEscalation] skipped duplicate booking forward', [
                    'conversation_id' => $conversation->id,
                    'booking_id' => $booking->id,
                    'admin_phones' => count($allPhones),
                    'admin_forward_hash' => $adminForwardHash,
                ]);

                return;
            }

            $this->rememberBookingForward($conversation, $booking, $customer, 'pending', $adminForwardHash);

            $allResults = $this->sendToAllAdmins($summary, [
                'conversation_id' => $conversation->id,
                'customer_id' => $customer->id,
                'booking_id' => $booking->id,
                'context' => 'booking_forward',
            ]);

            $anySuccess = collect($allResults)->contains('status', 'sent');
            $this->rememberBookingForward($conversation, $booking, $customer, $anySuccess ? 'sent' : 'failed', $adminForwardHash);

            WaLog::info('[HumanEscalation] booking forwarded to admins', [
                'conversation_id' => $conversation->id,
                'booking_id' => $booking->id,
                'admin_count' => count($allResults),
                'results' => $allResults,
                'admin_forward_hash' => $adminForwardHash,
            ]);
        } finally {
            rescue(static function () use ($bookingForwardLock): void {
                $bookingForwardLock->release();
            }, report: false);
        }
    }

    private function bookingAlreadyForwarded(Conversation $conversation, BookingRequest $booking, string $adminForwardHash): bool
    {
        $state = $this->stateService->get($conversation, self::STATE_ADMIN_BOOKING_FORWARD);

        return is_array($state)
            && (int) ($state['booking_id'] ?? 0) === $booking->id
            && (string) ($state['admin_forward_hash'] ?? '') === $adminForwardHash
            && (bool) ($state['admin_forwarded'] ?? false) === true;
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
        string $adminForwardHash,
    ): void {
        $this->stateService->put($conversation, 'admin_forwarded', true);
        $this->stateService->put($conversation, 'admin_forward_hash', $adminForwardHash);

        $this->stateService->put($conversation, self::STATE_ADMIN_BOOKING_FORWARD, [
            'booking_id' => $booking->id,
            'booking_status' => $booking->booking_status?->value,
            'customer_id' => $customer->id,
            'customer_phone' => $customer->phone_e164,
            'admin_forwarded' => true,
            'admin_forward_hash' => $adminForwardHash,
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

    /**
     * @return string[]
     */
    private function allAdminPhones(): array
    {
        $primary = $this->adminPhone();
        $phones = array_filter(
            (array) config('chatbot.jet.admin_phones', []),
            static fn (string $phone): bool => trim($phone) !== '',
        );

        // Pastikan primary selalu ada di daftar
        if ($primary !== '' && ! in_array($primary, $phones, true)) {
            array_unshift($phones, $primary);
        }

        return array_values(array_unique($phones));
    }

    /**
     * Kirim pesan ke semua nomor admin yang terdaftar.
     *
     * @return array<int, array{phone: string, status: string, error: string|null}>
     */
    private function sendToAllAdmins(string $message, array $meta = []): array
    {
        $results = [];
        foreach ($this->allAdminPhones() as $phone) {
            $result = $this->senderService->sendText($phone, $message, $meta);
            $results[] = [
                'phone'  => $phone,
                'status' => $result['status'],
                'error'  => $result['error'] ?? null,
            ];
        }
        return $results;
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

    private function adminForwardHash(BookingRequest $booking, string $summary): string
    {
        return hash('sha256', implode('|', [
            (string) $booking->id,
            $booking->booking_status?->value ?? 'unknown',
            $summary,
        ]));
    }
}