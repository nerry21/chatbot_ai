<?php

namespace App\Enums;

enum AuditActionType: string
{
    case ConversationTakeover     = 'conversation_takeover';
    case ConversationRelease      = 'conversation_release';
    case ConversationMarkedEscalated = 'conversation_marked_escalated';
    case ConversationMarkedUrgent = 'conversation_marked_urgent';
    case ConversationUrgencyCleared = 'conversation_urgency_cleared';
    case ConversationClosed = 'conversation_closed';
    case ConversationReopened = 'conversation_reopened';
    case ConversationTagged = 'conversation_tagged';
    case CustomerTagged = 'customer_tagged';
    case InternalNoteCreated = 'internal_note_created';
    case AdminReplySent           = 'admin_reply_sent';
    case EscalationAssigned       = 'escalation_assigned';
    case EscalationResolved       = 'escalation_resolved';
    case WhatsAppSendAttempt      = 'whatsapp_send_attempt';
    case WhatsAppSendSuccess      = 'whatsapp_send_success';
    case WhatsAppSendFailure      = 'whatsapp_send_failure';
    case WhatsAppSendSkipped      = 'whatsapp_send_skipped';
    case BotReplySkippedTakeover  = 'bot_reply_skipped_takeover';
    case NotificationMarkRead     = 'notification_mark_read';
    case NotificationMarkAllRead  = 'notification_mark_all_read';
    // Tahap 9 — Reliability
    case MessageResendManual         = 'message_resend_manual';
    case HealthCheckIssueNotified    = 'health_check_issue_notified';
    case CleanupCommandRun           = 'cleanup_command_run';

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    public function label(): string
    {
        return match ($this) {
            self::ConversationTakeover    => 'Admin mengambil alih percakapan',
            self::ConversationRelease     => 'Admin melepas percakapan ke bot',
            self::ConversationMarkedEscalated => 'Percakapan ditandai escalated',
            self::ConversationMarkedUrgent => 'Percakapan ditandai urgent',
            self::ConversationUrgencyCleared => 'Tanda urgent pada percakapan dibersihkan',
            self::ConversationClosed => 'Percakapan ditutup',
            self::ConversationReopened => 'Percakapan dibuka kembali',
            self::ConversationTagged => 'Tag ditambahkan ke percakapan',
            self::CustomerTagged => 'Tag ditambahkan ke customer',
            self::InternalNoteCreated => 'Catatan internal dibuat',
            self::AdminReplySent          => 'Admin mengirim balasan manual',
            self::EscalationAssigned      => 'Eskalasi di-assign ke admin',
            self::EscalationResolved      => 'Eskalasi diselesaikan',
            self::WhatsAppSendAttempt     => 'Percobaan kirim WhatsApp',
            self::WhatsAppSendSuccess     => 'Pengiriman WhatsApp berhasil',
            self::WhatsAppSendFailure     => 'Pengiriman WhatsApp gagal',
            self::WhatsAppSendSkipped     => 'Pengiriman WhatsApp dilewati',
            self::BotReplySkippedTakeover => 'Auto-reply bot diblokir (admin takeover aktif)',
            self::NotificationMarkRead       => 'Notifikasi ditandai dibaca',
            self::NotificationMarkAllRead    => 'Semua notifikasi ditandai dibaca',
            self::MessageResendManual        => 'Admin kirim ulang pesan outbound secara manual',
            self::HealthCheckIssueNotified   => 'Health check menemukan isu — notifikasi dibuat',
            self::CleanupCommandRun          => 'Cleanup operasional dijalankan',
        };
    }
}
