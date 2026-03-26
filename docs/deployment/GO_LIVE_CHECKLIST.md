# Go-Live Checklist

Checklist ini harus diikuti langkah demi langkah sebelum, saat, dan setelah deployment production.
Centang setiap item setelah dikonfirmasi.

---

## BAGIAN A: Sebelum Go-Live

### A1. Environment & Konfigurasi

- [ ] File `.env` sudah dibuat dari `.env.example` dan diisi lengkap
- [ ] `APP_ENV=production`
- [ ] `APP_DEBUG=false`
- [ ] `APP_URL` sudah diset ke URL production yang benar (dengan https)
- [ ] `APP_KEY` sudah di-generate (`php artisan key:generate`)
- [ ] `APP_TIMEZONE=Asia/Jakarta`
- [ ] `DB_CONNECTION=mysql` (bukan sqlite)
- [ ] Kredensial database sudah diisi dan valid
- [ ] `LOG_LEVEL=error` (bukan debug)
- [ ] `LOG_STACK=daily`
- [ ] Permission file `.env` sudah di-set: `chmod 600 .env`
- [ ] `.env` tidak masuk git (`git status` tidak menampilkan .env)

### A2. Database

- [ ] Database MySQL sudah dibuat
- [ ] User database sudah dibuat dengan hak akses minimal
- [ ] Koneksi database berhasil: `php artisan db:monitor`
- [ ] Semua migration sudah berjalan: `php artisan migrate:status`
- [ ] Tidak ada migration yang pending

### A3. Cache & Optimasi

- [ ] Config cache dibersihkan: `php artisan config:clear`
- [ ] Config cache dibuat ulang: `php artisan config:cache`
- [ ] Route cache dibuat: `php artisan route:cache`
- [ ] View cache dibuat: `php artisan view:cache`
- [ ] `php artisan optimize` berhasil dijalankan

### A4. Permission Server

- [ ] `storage/` dapat ditulis oleh web server: `ls -la storage/`
- [ ] `bootstrap/cache/` dapat ditulis: `ls -la bootstrap/cache/`
- [ ] Tidak ada error saat Laravel menulis log: `php artisan about`
- [ ] Permission sudah diset:
  ```bash
  sudo chown -R www-data:www-data storage bootstrap/cache
  sudo chmod -R 775 storage bootstrap/cache
  ```

### A5. WhatsApp

- [ ] `WHATSAPP_ACCESS_TOKEN` valid dan tidak expired
- [ ] `WHATSAPP_PHONE_NUMBER_ID` sudah diisi dengan benar
- [ ] `WHATSAPP_VERIFY_TOKEN` sudah di-set
- [ ] Webhook URL sudah didaftarkan di Meta App Dashboard
- [ ] Webhook subscription aktif: `messages`, `message_deliveries`, `message_reads`
- [ ] Test webhook verification berhasil (Meta mengirim GET request dan mendapat 200)
- [ ] Nomor WhatsApp bisnis aktif

### A6. OpenAI / LLM

- [ ] `OPENAI_API_KEY` sudah diisi
- [ ] API key valid (test: `curl https://api.openai.com/v1/models -H "Authorization: Bearer $OPENAI_API_KEY"`)
- [ ] Model yang digunakan (`gpt-4o-mini`) tersedia di akun
- [ ] Billing OpenAI aktif dan ada saldo/limit
- [ ] Usage limit sudah diset sesuai budget

### A7. HubSpot (jika digunakan)

- [ ] `HUBSPOT_ACCESS_TOKEN` sudah diisi dan valid
- [ ] Atau `HUBSPOT_ACCESS_TOKEN` dikosongkan jika tidak pakai HubSpot (mode local-only)

### A8. Queue Worker

- [ ] Supervisor sudah terinstall: `sudo supervisorctl version`
- [ ] File konfigurasi Supervisor sudah dibuat: `/etc/supervisor/conf.d/chatbot-worker.conf`
- [ ] Worker sudah aktif: `sudo supervisorctl status chatbot-worker:*`
- [ ] Worker berhasil memproses test job

### A9. Scheduler / Cron

- [ ] Cron entry sudah ditambahkan: `crontab -l -u www-data`
- [ ] Scheduler test manual berhasil: `php artisan schedule:run --verbose`
- [ ] Task terdaftar lengkap: `php artisan schedule:list`

### A10. Backup

- [ ] Backup database pertama sudah dibuat sebelum go-live
- [ ] Script backup harian sudah disiapkan
- [ ] Lokasi backup ada dan dapat ditulis

### A11. Akses Admin

- [ ] Dashboard admin dapat diakses via browser
- [ ] User admin sudah dibuat dengan role `admin`
- [ ] Login admin berhasil
- [ ] Operator (jika ada) sudah dibuat dengan role `operator`
- [ ] Login operator berhasil

### A12. Health Check Awal

- [ ] `php artisan chatbot:health-check` berjalan tanpa status CRITICAL
- [ ] Tidak ada notifikasi health issue yang mendesak
- [ ] Cleanup dry-run berhasil: `php artisan chatbot:cleanup --dry-run=1`

---

## BAGIAN B: Saat Go-Live (Deploy Steps)

Ikuti urutan ini saat melakukan deploy production pertama kali.

### B1. Aktifkan Maintenance Mode

```bash
php artisan down --message="Sedang dalam pemeliharaan. Kembali dalam beberapa menit." --retry=60
```

### B2. Deploy Code

```bash
# Jika menggunakan git
git pull origin main

# Install/update dependencies (tanpa dev packages)
composer install --no-dev --optimize-autoloader

# Install/build frontend assets jika diperlukan
# npm ci && npm run build
```

### B3. Jalankan Migration

```bash
# PASTIKAN backup sudah ada sebelum ini!
php artisan migrate --force
```

> Jika ada error migration, **STOP dan rollback** (lihat ROLLBACK_PLAN.md).

### B4. Optimasi Cache

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### B5. Restart Queue Worker

```bash
php artisan queue:restart
sudo supervisorctl restart chatbot-worker:*
```

### B6. Nonaktifkan Maintenance Mode

```bash
php artisan up
```

### B7. Verifikasi Pasca Deploy

```bash
# Jalankan script post-deploy check
bash deploy/post-deploy-check.sh

# Atau manual:
php artisan about
php artisan chatbot:health-check
sudo supervisorctl status chatbot-worker:*
```

---

## BAGIAN C: Verifikasi Fungsional

Lakukan pengujian nyata, bukan hanya command:

### C1. Test Inbound/Outbound

- [ ] Kirim pesan WhatsApp ke nomor bisnis
- [ ] Bot membalas dalam waktu wajar (< 30 detik)
- [ ] Pesan masuk tercatat di dashboard admin
- [ ] Reply bot tercatat di conversation

### C2. Test Handoff Takeover

- [ ] Admin takeover conversation berhasil
- [ ] Bot berhenti auto-reply setelah takeover
- [ ] Admin dapat reply manual via dashboard
- [ ] Release ke bot berjalan normal
- [ ] Bot kembali aktif setelah release

### C3. Test Eskalasi

- [ ] Eskalasi bisa dibuat dari dashboard
- [ ] Assign eskalasi ke operator berhasil
- [ ] Resolve eskalasi berhasil

### C4. Test Failed Message & Resend

- [ ] Cek tab "Failed Messages" di dashboard
- [ ] Resend manual berhasil (jika ada pesan gagal)
- [ ] Notifikasi resend muncul

### C5. Test Cleanup Dry-Run

```bash
php artisan chatbot:cleanup --dry-run=1
```

- [ ] Dry-run berhasil tanpa error
- [ ] Output menunjukkan data yang akan dibersihkan (wajar, bukan angka aneh)

---

## BAGIAN D: Setelah Go-Live — Observasi Awal

Lakukan observasi selama **30-60 menit pertama** setelah go-live:

- [ ] Pantau log Laravel: `tail -f storage/logs/laravel-$(date +%Y-%m-%d).log`
- [ ] Tidak ada error CRITICAL atau EMERGENCY
- [ ] Worker queue berjalan normal: `sudo supervisorctl status chatbot-worker:*`
- [ ] Jumlah failed jobs tidak bertambah: `php artisan queue:failed`
- [ ] Health check berjalan (cek setelah 30 menit): `php artisan chatbot:health-check`
- [ ] AI quality log berjalan normal (cek tabel ai_logs terisi)
- [ ] Tidak ada notifikasi health issue baru di dashboard

---

## Ringkasan Perintah Cek Cepat

```bash
# Status sistem secara keseluruhan
php artisan about

# Health check
php artisan chatbot:health-check

# Status worker
sudo supervisorctl status chatbot-worker:*

# Failed jobs
php artisan queue:failed

# Log terbaru
tail -50 storage/logs/laravel-$(date +%Y-%m-%d).log
```
