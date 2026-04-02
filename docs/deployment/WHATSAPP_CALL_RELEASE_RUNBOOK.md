# WhatsApp Call Release Runbook

Dokumen ini dipakai untuk audit akhir, test manual, release checklist, rollout, dan rollback modul WhatsApp Call pada stack:

- Laravel backend `chatbot_ai`
- Flutter admin `what_jet`
- domain production `https://spesial.online`
- target hosting utama: Hostinger / shared hosting

Dokumen ini sengaja fokus pada kondisi riil codebase saat ini:

- status call, permission, webhook, analytics, dan history sudah aktif
- audio live/WebRTC admin masih fallback operasional
- sumber kebenaran utama tetap `whatsapp_call_sessions`

## 1. Snapshot Arsitektur

### Backend

- Admin memanggil endpoint:
  - `POST /api/admin-mobile/conversations/{conversation}/call/start`
  - `POST /api/admin-mobile/conversations/{conversation}/call/request-permission`
  - `POST /api/admin-mobile/conversations/{conversation}/call/accept`
  - `POST /api/admin-mobile/conversations/{conversation}/call/reject`
  - `POST /api/admin-mobile/conversations/{conversation}/call/end`
  - `GET /api/admin-mobile/conversations/{conversation}/call/status`
  - `GET /api/admin-mobile/call-analytics/summary`
  - `GET /api/admin-mobile/call-analytics/recent`
  - `GET /api/admin-mobile/conversations/{conversation}/call-history`
  - `GET /api/admin-mobile/call/readiness`
- Session lokal disimpan di `whatsapp_call_sessions`.
- Webhook Meta masuk lewat `GET/POST /webhook/whatsapp`.
- Dedup webhook memakai tabel `whatsapp_webhook_dedup_events`.
- Audit log memakai `WaLog` + `WhatsAppCallAuditService`.

### Flutter

- Tombol call ada di dashboard thread chat.
- Call page fallback tetap dibuka untuk `permission_requested`, `call_started`, `permission_still_pending`, `call_already_processing`, dan beberapa failure yang masih perlu dipantau.
- Polling call status berjalan per conversation dan berhenti saat call selesai/dispose.
- Timeline, history, analytics, recent calls, dan banner sudah membaca data backend.
- Audio live belum diklaim aktif. UI selalu jujur bahwa media real-time belum tersedia di build admin ini.

## 2. Status Utama

### Call status backend

| Status | Arti operasional |
|---|---|
| `permission_requested` | Izin panggilan baru diminta atau masih menunggu |
| `initiated` | Outbound call sudah dicoba ke Meta |
| `ringing` | Panggilan sedang berdering |
| `connecting` | Signaling bergerak menuju connected |
| `connected` | Backend menerima bukti call tersambung |
| `rejected` | Panggilan ditolak pengguna |
| `missed` | Panggilan tidak dijawab / timeout |
| `ended` | Panggilan selesai normal |
| `failed` | Panggilan gagal secara teknis atau provider error |

### Permission status internal

| Permission status | Arti |
|---|---|
| `unknown` | Belum ada informasi valid |
| `required` | Belum ada izin, boleh minta izin |
| `requested` | Izin sedang diminta / masih pending |
| `granted` | Boleh start outbound call |
| `denied` | Pengguna menolak izin |
| `expired` | Izin pernah ada tetapi sudah kedaluwarsa |
| `rate_limited` | Meta atau backend sedang memberlakukan cooldown |
| `failed` | Error teknis / config pada proses permission |

### Final status analytics

| Final status | Arti |
|---|---|
| `completed` | Call sempat connected lalu ended |
| `missed` | Tidak pernah connected dan berakhir tanpa jawaban |
| `rejected` | Ditolak |
| `failed` | Gagal teknis |
| `cancelled` | Diakhiri sebelum connect oleh admin/system |
| `permission_pending` | Berhenti pada tahap permission / throttling / expired |
| `in_progress` | Masih aktif |

## 3. Gap Status Saat Ini

### `ready`

- Route admin mobile call sudah lengkap.
- Permission status internal sudah stabil dan defensif.
- Retry outbound ke Meta hanya berjalan untuk koneksi/5xx yang retryable.
- Permission cooldown, rate-limit cooldown, dan action lock sudah aktif.
- Webhook dedup berbasis DB sudah aktif.
- Timeline, history, analytics, dan recent calls memakai sumber data session yang sama.
- Readiness endpoint admin-only sudah tersedia untuk smoke test config.
- Flutter sudah tahan terhadap response duplicate/no-op/pending/rate-limited.

### `acceptable_with_fallback`

- Call page Flutter masih fallback operasional untuk media suara langsung.
- Backend sudah siap untuk status/signaling dasar, tetapi belum menyediakan jalur WebRTC penuh `offer/answer/ice`.
- Dashboard admin jujur bahwa audio live belum tersedia, jadi tetap aman untuk operasional monitoring call.

### `needs_attention_before_release`

- Pastikan Meta App benar-benar subscribe field `calls`; tanpa ini polling masih jalan tetapi state real-time akan tertinggal.
- Pastikan `WHATSAPP_WEBHOOK_SECRET` terisi jika `WHATSAPP_CALL_WEBHOOK_SIGNATURE_ENABLED=true`.
- Deploy script `deploy/production-deploy.sh` masih bergaya VPS/Supervisor; untuk Hostinger shared hosting gunakan checklist manual di bawah, bukan script itu mentah-mentah.
- Jika storage Hostinger bermasalah, log utama bisa kosong. Gunakan `storage/logs/whatsapp-emergency.log` sebagai fallback check pertama.

### `future_enhancement`

- Jalur media WebRTC end-to-end untuk Flutter admin.
- Metrik voice lanjutan: answer time, ringing time, media connected rate, permission acceptance rate.
- Dashboard chart yang lebih kaya jika volume call naik.
- Admin-only tooling untuk replay payload staging secara lebih otomatis.

## 4. Skenario Uji Manual End-to-End

### Skenario 1 — Start call normal

1. Login admin Flutter.
2. Buka conversation WhatsApp yang valid.
3. Tekan tombol call.
4. Backend harus:
   - resolve conversation
   - cek/buat session
   - cek permission
   - jika `granted`, start outbound call ke Meta
5. Flutter membuka call page.
6. Polling `GET /call/status` aktif.
7. Webhook update masuk.
8. UI berubah ke `Memanggil...`, `Berdering...`, lalu outcome final.

### Skenario 2 — Permission belum ada

1. Pastikan user belum punya call permission valid.
2. Tekan tombol call.
3. Backend harus mengembalikan `call_action=permission_requested`.
4. Flutter tetap membuka call page fallback.
5. UI utama harus menunjukkan `Meminta izin panggilan...`.
6. Tidak boleh ada indikasi audio tersambung.

### Skenario 3 — Permission masih pending / cooldown

1. Segera tekan tombol call lagi pada conversation yang sama.
2. Backend tidak boleh mengirim permission request baru ke Meta.
3. Response harus berupa `permission_still_pending` atau `permission_rate_limited`.
4. Flutter harus menampilkan pesan jelas dan tetap stabil.

### Skenario 4 — Permission denied / expired

1. Simulasikan Meta mengembalikan `denied` atau `expired`.
2. Session lokal harus ikut berubah.
3. Start call tidak boleh dilanjutkan.
4. Flutter menampilkan outcome yang benar.
5. Timeline/history harus mencatat konteks permission.

### Skenario 5 — Outbound call gagal karena Meta error

1. Mulai call dengan config valid.
2. Paksa Meta/mocking mengembalikan 5xx atau error teknis.
3. Retry boleh terjadi sesuai policy.
4. Setelah retry habis, response harus tetap jelas.
5. Session tidak boleh pindah ke `connected`.
6. Audit log harus memuat `outbound_call_failed` dan `call_retry_attempt` bila ada retry.

### Skenario 6 — Webhook duplicate

1. Kirim payload webhook call yang sama dua kali.
2. Request pertama diproses normal.
3. Request kedua harus menghasilkan `ignored_duplicate` atau `noop_already_synced`.
4. Session tidak boleh rusak atau memperbarui timestamp penting secara liar.

### Skenario 7 — Webhook unmatched

1. Kirim webhook dengan `wa_call_id` yang tidak bisa dicocokkan.
2. Backend tidak boleh crash.
3. Audit log harus memuat `webhook_call_unmatched`.
4. Webhook tetap mengembalikan HTTP 200 dengan summary aman.

### Skenario 8 — End call normal

1. Buka call page saat session aktif.
2. Tekan `Akhiri Panggilan`.
3. Backend harus menandai session ended / end requested dengan aman.
4. Polling harus berhenti setelah status final masuk.
5. Call page harus menampilkan summary akhir.
6. Thread history harus memperlihatkan event final.

### Skenario 9 — Call selesai di backend saat UI terbuka

1. Buka call page.
2. Ubah status session lewat webhook menjadi `ended`, `rejected`, atau `failed`.
3. Polling Flutter harus menangkap perubahan.
4. UI harus update tanpa crash.
5. Polling harus berhenti sendiri saat call finished.

### Skenario 10 — Invalid config

1. Kosongkan token atau phone number id di staging.
2. Hit `GET /api/admin-mobile/call/readiness`.
3. Start call harus gagal dengan pesan konfigurasi jelas.
4. Flutter harus tetap menampilkan pesan yang masuk akal, bukan blank page.

### Skenario 11 — Rate limit Meta

1. Simulasikan 429 / throttling dari Meta.
2. Backend harus mengembalikan `permission_rate_limited` atau `call_rate_limited`.
3. Session harus menyimpan cooldown.
4. Retry tidak boleh liar.
5. Flutter harus menampilkan pesan pembatasan sementara.

### Skenario 12 — Hostinger reality check

1. Deploy ke `spesial.online`.
2. Pastikan route call dan webhook bisa diakses.
3. Pastikan `storage/logs` bisa ditulis.
4. Cek `GET /api/admin-mobile/call/readiness`.
5. Test satu conversation nyata dari Flutter production build.

## 5. Payload Uji Webhook

Payload berikut disesuaikan dengan parser/event mapping codebase sekarang. Gunakan `POST /webhook/whatsapp` dengan JSON.

### Permission requested

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "changes": [
        {
          "field": "calls",
          "value": {
            "calls": [
              {
                "id": "wa_call_001",
                "event": "permission_requested",
                "direction": "business_initiated",
                "from": "628111111111",
                "to": "628222222222",
                "timestamp": "2026-04-03T10:00:00+07:00"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

### Ringing

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "changes": [
        {
          "field": "calls",
          "value": {
            "calls": [
              {
                "id": "wa_call_001",
                "event": "ringing",
                "direction": "business_initiated",
                "from": "628111111111",
                "to": "628222222222",
                "timestamp": "2026-04-03T10:00:15+07:00"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

### Connected

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "changes": [
        {
          "field": "calls",
          "value": {
            "calls": [
              {
                "id": "wa_call_001",
                "event": "connected",
                "direction": "business_initiated",
                "from": "628111111111",
                "to": "628222222222",
                "timestamp": "2026-04-03T10:00:24+07:00"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

### Rejected

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "changes": [
        {
          "field": "calls",
          "value": {
            "calls": [
              {
                "id": "wa_call_001",
                "event": "rejected",
                "direction": "business_initiated",
                "from": "628111111111",
                "to": "628222222222",
                "timestamp": "2026-04-03T10:01:00+07:00",
                "termination_reason": "rejected_by_user"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

### Ended

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "changes": [
        {
          "field": "calls",
          "value": {
            "calls": [
              {
                "id": "wa_call_001",
                "event": "ended",
                "direction": "business_initiated",
                "from": "628111111111",
                "to": "628222222222",
                "timestamp": "2026-04-03T10:03:00+07:00",
                "termination_reason": "completed"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

### Failed

```json
{
  "object": "whatsapp_business_account",
  "entry": [
    {
      "changes": [
        {
          "field": "calls",
          "value": {
            "calls": [
              {
                "id": "wa_call_001",
                "event": "failed",
                "direction": "business_initiated",
                "from": "628111111111",
                "to": "628222222222",
                "timestamp": "2026-04-03T10:00:40+07:00",
                "termination_reason": "transport_error"
              }
            ]
          }
        }
      ]
    }
  ]
}
```

### Duplicate webhook

Kirim ulang payload `ringing` atau `ended` yang sama persis. Hasil yang diharapkan:

- HTTP 200 tetap aman
- `summary.ignored_calls` naik
- audit log berisi `webhook_call_ignored_duplicate`

## 6. Checklist Production Release

### Backend config

- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL=https://spesial.online`
- [ ] `WHATSAPP_CALLING_ENABLED=true`
- [ ] `WHATSAPP_CALLING_BASE_URL` valid
- [ ] `WHATSAPP_CALLING_API_VERSION` terisi
- [ ] `WHATSAPP_CALLING_ACCESS_TOKEN` atau `WHATSAPP_ACCESS_TOKEN` valid
- [ ] `WHATSAPP_CALLING_PHONE_NUMBER_ID` atau `WHATSAPP_PHONE_NUMBER_ID` valid
- [ ] `WHATSAPP_VERIFY_TOKEN` benar
- [ ] `WHATSAPP_WEBHOOK_SECRET` terisi jika signature validation diaktifkan
- [ ] `WHATSAPP_CALL_RETRY_ENABLED`, `WHATSAPP_CALL_MAX_RETRIES`, `WHATSAPP_CALL_RETRY_BACKOFF_MS` sesuai target produksi
- [ ] `WHATSAPP_CALL_PERMISSION_COOLDOWN_SECONDS` dan `WHATSAPP_CALL_RATE_LIMIT_COOLDOWN_SECONDS` sudah ditetapkan

### Database

- [ ] Semua migration call terbaru sudah jalan
- [ ] `whatsapp_call_sessions` memiliki field analytics + hardening
- [ ] `whatsapp_webhook_dedup_events` sudah ada
- [ ] Index status/final_status/started_at/ended_at tersedia

### Webhook

- [ ] Meta subscribe field `calls`
- [ ] GET verify berhasil
- [ ] POST webhook dapat diakses dari internet
- [ ] Signature validation sesuai setup environment
- [ ] Test unmatched dan duplicate webhook pernah dilakukan

### Logging

- [ ] `storage/logs` ada
- [ ] `storage/logs` writable
- [ ] `bootstrap/cache` writable
- [ ] `whatsapp-emergency.log` dapat dibuat bila logger utama gagal
- [ ] Log tidak membocorkan token, signature, atau nomor penuh

### API

- [ ] `GET /api/admin-mobile/call/readiness` OK untuk admin login
- [ ] `POST /call/start` berjalan
- [ ] `GET /call/status` berjalan
- [ ] `POST /call/end` berjalan
- [ ] `GET /call-analytics/summary` berjalan
- [ ] `GET /call-analytics/recent` berjalan
- [ ] Detail conversation mengembalikan `call_session`, `call_timeline`, `call_history_summary`, `call_history`

### Flutter

- [ ] Build Flutter memakai base URL production yang benar
- [ ] Admin login production valid
- [ ] Tombol call tidak double-trigger saat loading
- [ ] Call page fallback terbuka dan polling stabil
- [ ] Banner/timeline/history tampil tanpa blank state aneh
- [ ] Pesan error untuk permission pending, rate limit, duplicate action, config error sudah dipahami admin

### Operasional

- [ ] Satu test permission request pernah dicoba
- [ ] Satu test outbound call pernah dicoba
- [ ] Satu test duplicate webhook pernah dicoba
- [ ] Perilaku invalid config sudah diketahui tim
- [ ] Tim paham bahwa audio live admin masih fallback operasional

## 7. Rollout Paling Aman

1. Backup database dan backup file `.env`.
   Risiko: migration analytics/hardening menambah kolom baru dan tabel dedup.
2. Deploy backend lebih dulu.
   Risiko: Flutter lama tetap aman selama kontrak inti tetap kompatibel.
3. Jalankan `php artisan migrate --force`.
4. Jalankan:
   - `php artisan optimize:clear`
   - `php artisan config:cache`
   - `php artisan route:cache`
5. Pastikan `storage/logs` dan `bootstrap/cache` writable.
6. Smoke test backend:
   - `GET /api/admin-mobile/call/readiness`
   - `GET verify /webhook/whatsapp`
7. Test POST webhook manual dengan satu payload `ringing`.
8. Test permission request manual dari admin.
9. Test duplicate webhook manual.
10. Setelah backend stabil, baru deploy/update Flutter admin.
11. Login Flutter ke production dan uji:
    - tombol call
    - call page fallback
    - polling
    - timeline/history
12. Uji analytics summary dan recent calls.
13. Observasi log 15-30 menit pertama production.

## 8. Rollback

### Code rollback

- Gunakan commit terakhir yang diketahui stabil.
- Untuk environment VPS bisa pakai `deploy/rollback.sh`.
- Untuk Hostinger shared hosting, rollback biasanya manual:
  - restore file release sebelumnya
  - restore `.env` jika berubah
  - clear/cache ulang artisan

### Database rollback

- Migration call terbaru bersifat additive.
- Jika perlu mematikan module sementara, lebih aman:
  - set `WHATSAPP_CALLING_ENABLED=false`
  - jangan drop tabel/kolom langsung saat insiden
- Restore database penuh hanya jika ada kerusakan skema/data besar.

### Feature-flag fallback

Jika modul call harus dinonaktifkan sementara tanpa mematikan chat utama:

- set `WHATSAPP_CALLING_ENABLED=false`
- opsional set `WHATSAPP_CALL_PERMISSION_REQUEST_ENABLED=false`
- clear/cache config

Efek yang diharapkan:

- chat existing tetap berjalan
- start call akan gagal secara jelas sebagai config/calling disabled
- analytics/history lama tetap bisa dibaca

## 9. Catatan Operasional Singkat

### Jika call gagal

Periksa urutan ini:

1. `GET /api/admin-mobile/call/readiness`
2. log `permission_request_*` atau `outbound_call_*`
3. `meta_error.code` dan `meta_error.message`
4. apakah session masuk cooldown / rate-limited

### Jika webhook tidak masuk

Periksa:

1. Meta webhook subscription field `calls`
2. GET verify token
3. POST webhook bisa diakses dari internet
4. signature validation cocok dengan `WHATSAPP_WEBHOOK_SECRET`
5. `storage/logs/whatsapp-emergency.log`

### Jika UI tidak update

Periksa:

1. `GET /call/status` response
2. polling controller masih aktif
3. `call_session.status`, `permission_status`, `final_status`
4. apakah webhook duplicate/unmatched

### Jika log kosong di Hostinger

Periksa:

1. `storage/logs` ada dan writable
2. `bootstrap/cache` writable
3. channel logging Laravel tidak rusak
4. fallback file `storage/logs/whatsapp-emergency.log`

## 10. Catatan Rollout Hostinger / Shared Hosting

- Jangan asumsikan `supervisorctl` tersedia.
- Jangan asumsikan queue worker panjang selalu aktif.
- Gunakan perubahan sinkron atau graceful degraded behavior yang sudah ada.
- Prioritaskan:
  - config cache benar
  - route cache benar
  - storage writable
  - webhook publicly reachable
  - smoke test manual setelah deploy

Untuk Hostinger, checklist manual ini lebih relevan daripada script `deploy/production-deploy.sh` yang ditulis untuk environment VPS.
