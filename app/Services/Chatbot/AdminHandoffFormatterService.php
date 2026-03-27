<?php

namespace App\Services\Chatbot;

use App\Models\BookingRequest;
use App\Services\Booking\BookingReviewFormatterService;

class AdminHandoffFormatterService
{
    public function __construct(
        private readonly BookingReviewFormatterService $reviewFormatter,
    ) {}

    public function formatBookingForward(BookingRequest $booking, string $customerPhone): string
    {
        return $this->reviewFormatter->buildAdminReview($booking, $customerPhone);
    }

    public function formatQuestionEscalation(string $customerPhone): string
    {
        return 'Bos, ini ada pertanyaan dari nomor '.$this->digitsOnly($customerPhone).', bisa bantu jawab bos?';
    }

    private function digitsOnly(string $phone): string
    {
        return preg_replace('/\D+/', '', $phone) ?: $phone;
    }
}
