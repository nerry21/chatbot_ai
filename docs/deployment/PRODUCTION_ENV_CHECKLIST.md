# Production Environment Checklist

Dokumen ini memandu pengisian `.env` untuk deployment production chatbot AI.
Salin `.env.example` ke `.env`, lalu isi setiap nilai sesuai panduan di bawah.

---

## Cara Penggunaan

```bash
cp .env.example .env
# Edit .env — JANGAN commit .env ke git
nano .env
php artisan key:generate
```

---

## Kelompok Variabel & Panduan

### 1. App Dasar

| Variabel | Nilai Production | Catatan |
|---|---|---|
| `APP_NAME` | `Chatbot AI` | Nama tampil di notifikasi dan UI |
| `APP_ENV` | `production` | **Wajib `production`** |
| `APP_KEY` | _(generate)_ | `php artisan key:generate` — wajib ada |
| `APP_DEBUG` | `false` | **Wajib `false`** — jangan bocorkan error stack |
| `APP_URL` | `https://yourdomain.com` | URL publik lengkap dengan https |
| `APP_TIMEZONE` | `Asia/Jakarta` | Pastikan konsisten dengan database server |

> **Risiko:** Jika `APP_DEBUG=true` di production, stack trace dan environment variable bisa terekspos ke user.

---

### 2. Database

| Variabel | Nilai Production | Catatan |
|---|---|---|
| `DB_CONNECTION` | `mysql` | Gunakan MySQL/MariaDB, bukan SQLite |
| `DB_HOST` | `127.0.0.1` | Localhost atau IP database server |
| `DB_PORT` | `3306` | Port standar MySQL |
| `DB_DATABASE` | `chatbot_ai` | Nama database |
| `DB_USERNAME` | `chatbot_user` | Jangan gunakan root |
| `DB_PASSWORD` | _(string kuat)_ | Minimal 20 karakter, campur huruf/angka/simbol |

> **Aturan keamanan:** Buat user MySQL khusus dengan hak akses minimal (SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER pada database chatbot saja).

```sql
-- Contoh pembuatan user database (jalankan di MySQL CLI sebagai root)
CREATE DATABASE chatbot_ai CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'chatbot_user'@'localhost' IDENTIFIED BY 'strong_password_here';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, DROP, INDEX, ALTER ON chatbot_ai.* TO 'chatbot_user'@'localhost';
FLUSH PRIVILEGES;
```

---

### 3. Queue / Cache / Session

| Variabel | Nilai Direkomendasikan | Catatan |
|---|---|---|
| `QUEUE_CONNECTION` | `database` | Redis lebih cepat jika tersedia |
| `CACHE_STORE` | `database` | Redis lebih cepat jika tersedia |
| `SESSION_DRIVER` | `database` | Aman untuk multi-process |

> **Jika Redis tersedia:** Gunakan `QUEUE_CONNECTION=redis` dan `CACHE_STORE=redis` untuk performa lebih baik.

---

### 4. Logging

| Variabel | Nilai Production | Catatan |
|---|---|---|
| `LOG_CHANNEL` | `stack` | Gabungkan beberapa channel |
| `LOG_STACK` | `daily` | Rotasi harian, retensi 14 hari (default Laravel) |
| `LOG_LEVEL` | `error` | Jangan gunakan `debug` di production |

> **File log utama:** `storage/logs/laravel-YYYY-MM-DD.log`
> **Log khusus chatbot:** `storage/logs/chatbot-health.log`, `storage/logs/chatbot-cleanup.log`

---

### 5. WhatsApp Cloud API (Meta)

| Variabel | Sumber | Catatan |
|---|---|---|
| `WHATSAPP_ACCESS_TOKEN` | Meta for Developers | Token permanen (System User), bukan temporary |
| `WHATSAPP_PHONE_NUMBER_ID` | Meta for Developers → WhatsApp → API Setup | ID numerik |
| `WHATSAPP_VERIFY_TOKEN` | Buat sendiri | String random unik, cocokkan dengan webhook config di Meta |
| `WHATSAPP_WEBHOOK_SECRET` | Meta App Dashboard → App Secret | Untuk validasi X-Hub-Signature-256 |
| `WHATSAPP_GRAPH_BASE_URL` | `https://graph.facebook.com/v19.0` | Jangan diubah kecuali upgrade versi API |
| `WHATSAPP_DEFAULT_COUNTRY_CODE` | `62` | Kode negara Indonesia tanpa `+` |

> **Checklist WhatsApp:**
> - [ ] Webhook URL sudah didaftarkan di Meta App Dashboard
> - [ ] VERIFY_TOKEN cocok antara `.env` dan Meta Dashboard
> - [ ] Subscription webhook aktif: `messages`, `message_deliveries`, `message_reads`
> - [ ] Nomor telepon sudah diverifikasi dan aktif

---

### 6. OpenAI / LLM

| Variabel | Nilai | Catatan |
|---|---|---|
| `OPENAI_API_KEY` | `sk-...` | Dari platform.openai.com/api-keys |
| `OPENAI_MODEL_INTENT` | `gpt-4o-mini` | Hemat biaya, cukup untuk intent detection |
| `OPENAI_MODEL_EXTRACTION` | `gpt-4o-mini` | Ekstraksi data booking/info |
| `OPENAI_MODEL_REPLY` | `gpt-4o-mini` | Generate reply ke customer |
| `OPENAI_MODEL_SUMMARY` | `gpt-4o-mini` | Summary conversation |

> **Checklist OpenAI:**
> - [ ] API key valid (test: `curl https://api.openai.com/v1/models -H "Authorization: Bearer $OPENAI_API_KEY"`)
> - [ ] Billing aktif dan tidak ada hard limit
> - [ ] Usage limit diset sesuai budget

---

### 7. HubSpot CRM

| Variabel | Nilai | Catatan |
|---|---|---|
| `HUBSPOT_ACCESS_TOKEN` | _(token)_ | Kosongkan jika tidak pakai HubSpot |
| `HUBSPOT_BASE_URL` | `https://api.hubapi.com` | Tidak perlu diubah |

> Jika `HUBSPOT_ACCESS_TOKEN` dikosongkan, sistem berjalan dalam mode local-only tanpa sinkronisasi CRM.

---

### 8. Chatbot Feature Flags

| Variabel | Production Default | Keterangan |
|---|---|---|
| `CHATBOT_REQUIRE_ADMIN` | `true` | Dashboard hanya untuk user dengan role admin/operator |
| `CHATBOT_ALLOW_OPERATOR_ACTIONS` | `true` | Operator dapat takeover, release, assign escalation |
| `CHATBOT_NOTIFICATIONS_ENABLED` | `true` | Notifikasi operasional ke admin aktif |
| `CHATBOT_AUDIT_ENABLED` | `true` | Semua aksi admin tercatat di audit log |
| `CHATBOT_KNOWLEDGE_ENABLED` | `true` | Aktifkan knowledge base untuk AI |
| `CHATBOT_AI_QUALITY_ENABLED` | `true` | Evaluasi kualitas jawaban AI |
| `CHATBOT_AI_LOW_CONFIDENCE_THRESHOLD` | `0.40` | Di bawah nilai ini = low confidence |
| `CHATBOT_MAX_SEND_ATTEMPTS` | `3` | Retry kirim pesan maksimum 3x |
| `CHATBOT_RESEND_COOLDOWN_MINUTES` | `5` | Jeda minimal antar resend |

---

### 9. Reliability & Health Check

| Variabel | Nilai Aman | Keterangan |
|---|---|---|
| `CHATBOT_HEALTH_QUEUE_BACKLOG_THRESHOLD` | `50` | Alert jika queue menumpuk > 50 job |
| `CHATBOT_HEALTH_FAILED_MESSAGE_THRESHOLD` | `10` | Alert jika > 10 pesan gagal |
| `CHATBOT_HEALTH_OPEN_ESCALATION_THRESHOLD` | `20` | Alert jika > 20 eskalasi terbuka |

---

### 10. Cleanup Retention

| Variabel | Nilai Default | Keterangan |
|---|---|---|
| `CHATBOT_CLEANUP_READ_NOTIFICATIONS_DAYS` | `30` | Hapus notifikasi terbaca > 30 hari |
| `CHATBOT_CLEANUP_AUDIT_LOGS_DAYS` | `90` | Hapus audit log > 90 hari |
| `CHATBOT_CLEANUP_AI_LOGS_DAYS` | `60` | Hapus AI log > 60 hari |
| `CHATBOT_CLEANUP_CLOSED_ESCALATIONS_DAYS` | `90` | Hapus eskalasi closed > 90 hari |
| `CHATBOT_CLEANUP_DRY_RUN_DEFAULT` | `true` | Default preview saja, tidak hapus sungguhan |

> **Penting:** Ubah `CHATBOT_CLEANUP_DRY_RUN_DEFAULT=false` setelah memverifikasi bahwa cleanup berjalan benar di production.

---

## Verifikasi Setelah Pengisian .env

```bash
# 1. Pastikan APP_KEY terisi
php artisan key:generate --show

# 2. Test koneksi database
php artisan db:monitor

# 3. Cek konfigurasi terload dengan benar
php artisan config:show app

# 4. Cek environment saat ini
php artisan env

# 5. Jalankan health check
php artisan chatbot:health-check
```

---

## Keamanan File .env

```bash
# Set permission agar hanya owner yang bisa baca
chmod 600 .env

# Pastikan .env tidak masuk git
echo ".env" >> .gitignore
git status  # verifikasi .env tidak muncul
```

> **Jangan pernah:**
> - Commit `.env` ke repository
> - Share `.env` via chat atau email
> - Menaruh `.env` di folder public/
