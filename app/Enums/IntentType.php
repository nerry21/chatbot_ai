<?php

namespace App\Enums;

enum IntentType: string
{
    case Greeting = 'greeting';
    case SalamIslam = 'salam_islam';
    case Booking = 'booking';
    case BookingConfirm = 'booking_confirm';
    case BookingCancel = 'booking_cancel';
    case ScheduleInquiry = 'schedule_inquiry';
    case PriceInquiry = 'price_inquiry';
    case LocationInquiry = 'location_inquiry';
    case TanyaKeberangkatanHariIni = 'tanya_keberangkatan_hari_ini';
    case TanyaHarga = 'tanya_harga';
    case TanyaRute = 'tanya_rute';
    case TanyaJam = 'tanya_jam';
    case KonfirmasiBooking = 'konfirmasi_booking';
    case UbahDataBooking = 'ubah_data_booking';
    case PertanyaanTidakTerjawab = 'pertanyaan_tidak_terjawab';
    case CloseIntent = 'close_intent';
    case Support = 'support';
    case HumanHandoff = 'human_handoff';
    case Farewell = 'farewell';
    case OutOfScope = 'out_of_scope';
    case Unknown = 'unknown';
    case Confirmation = 'confirmation';
    case Rejection = 'rejection';

    public function label(): string
    {
        return match ($this) {
            self::Greeting => 'Greeting',
            self::SalamIslam => 'Salam Islam',
            self::Booking => 'Booking Request',
            self::BookingConfirm => 'Booking Confirmation',
            self::BookingCancel => 'Booking Cancellation',
            self::ScheduleInquiry => 'Schedule Inquiry',
            self::PriceInquiry => 'Price Inquiry',
            self::LocationInquiry => 'Location Inquiry',
            self::TanyaKeberangkatanHariIni => 'Tanya Keberangkatan Hari Ini',
            self::TanyaHarga => 'Tanya Harga',
            self::TanyaRute => 'Tanya Rute',
            self::TanyaJam => 'Tanya Jam',
            self::KonfirmasiBooking => 'Konfirmasi Booking',
            self::UbahDataBooking => 'Ubah Data Booking',
            self::PertanyaanTidakTerjawab => 'Pertanyaan Tidak Terjawab',
            self::CloseIntent => 'Close Intent',
            self::Support => 'Support',
            self::HumanHandoff => 'Human Handoff Request',
            self::Farewell => 'Farewell',
            self::OutOfScope => 'Out of Scope',
            self::Unknown => 'Unknown',
            self::Confirmation => 'Confirmation',
            self::Rejection => 'Rejection',
        };
    }

    public function requiresHuman(): bool
    {
        return match ($this) {
            self::HumanHandoff,
            self::Support,
            self::PertanyaanTidakTerjawab => true,
            default => false,
        };
    }

    public function isBookingRelated(): bool
    {
        return match ($this) {
            self::Booking,
            self::BookingConfirm,
            self::BookingCancel,
            self::KonfirmasiBooking,
            self::UbahDataBooking,
            self::Confirmation,
            self::Rejection,
            self::PriceInquiry,
            self::ScheduleInquiry,
            self::LocationInquiry,
            self::TanyaHarga,
            self::TanyaJam,
            self::TanyaRute,
            self::TanyaKeberangkatanHariIni => true,
            default => false,
        };
    }

    public function isConversationEnder(): bool
    {
        return match ($this) {
            self::Farewell, self::HumanHandoff, self::CloseIntent => true,
            default => false,
        };
    }
}
