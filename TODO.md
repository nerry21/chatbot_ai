# CRMWritebackService Refactor TODO

- [ ] Refactor `app/Services/CRM/CRMWritebackService.php` menjadi pipeline writeback terstruktur per langkah.
- [ ] Tambahkan step methods:
  - [ ] `runContactSyncStep`
  - [ ] `runSummarySyncStep`
  - [ ] `runDecisionNoteSyncStep`
  - [ ] `runLeadSyncStep`
  - [ ] `runEscalationSyncStep`
- [ ] Standarkan report hasil per langkah (`status`, `reason`, `queue/action`, `metadata`).
- [ ] Pertahankan backward compatibility key lama (`contact_sync`, `summary_sync`, `decision_note_sync`, `lead`, `needs_escalation`, dll).
- [ ] Tambahkan agregasi `writeback_report` untuk audit trail.
- [ ] Jalankan validasi syntax PHP untuk file yang diubah.
- [ ] Update TODO setelah step selesai.
