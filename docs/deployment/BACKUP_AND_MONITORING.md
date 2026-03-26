# Backup & Monitoring Operasional

Panduan backup database dan monitoring log untuk production chatbot AI.

---

## Bagian 1: Backup Database

### Backup Manual dengan mysqldump

```bash
# Format perintah
mysqldump -u DB_USERNAME -p DB_DATABASE > /backup/chatbot_ai_$(date +%Y%m%d_%H%M%S).sql

# Contoh konkret
mysqldump -u chatbot_user -p chatbot_ai > /backup/chatbot_ai_20260326_020000.sql

# Dengan kompresi gzip (direkomendasikan untuk file besar)
mysqldump -u chatbot_user -p chatbot_ai | gzip > /backup/chatbot_ai_$(date +%Y%m%d_%H%M%S).sql.gz

# Backup dengan opsi aman (termasuk stored procedures, events, triggers)
mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  -u chatbot_user -p \
  chatbot_ai \
  | gzip > /backup/chatbot_ai_$(date +%Y%m%d_%H%M%S).sql.gz
```

**Penjelasan flag penting:**

| Flag | Keterangan |
|---|---|
| `--single-transaction` | Backup konsisten tanpa lock tabel (untuk InnoDB) |
| `--routines` | Sertakan stored procedures |
| `--triggers` | Sertakan triggers |
| `--events` | Sertakan scheduled events MySQL |

---

### Penamaan File Backup

Gunakan format yang konsisten dan mudah diidentifikasi:

```
chatbot_ai_YYYYMMDD_HHMMSS.sql.gz
```

Contoh:
```
chatbot_ai_20260326_020000.sql.gz   ← backup harian jam 02:00
chatbot_ai_20260326_120000.sql.gz   ← backup siang
chatbot_ai_pre_deploy_20260326.sql.gz  ← sebelum deploy
```

---

### Lokasi Penyimpanan Backup

```
/backup/chatbot_ai/
├── daily/
│   ├── chatbot_ai_20260326_020000.sql.gz
│   ├── chatbot_ai_20260325_020000.sql.gz
│   └── ...
└── pre-deploy/
    ├── chatbot_ai_pre_deploy_20260326.sql.gz
    └── ...
```

```bash
# Buat direktori backup
sudo mkdir -p /backup/chatbot_ai/daily
sudo mkdir -p /backup/chatbot_ai/pre-deploy
sudo chown -R www-data:www-data /backup/chatbot_ai
sudo chmod 750 /backup/chatbot_ai
```

---

### Retensi Backup Sederhana

Hapus backup yang lebih dari 30 hari:

```bash
# Hapus backup harian lebih dari 30 hari
find /backup/chatbot_ai/daily -name "*.sql.gz" -mtime +30 -delete

# Hapus backup pre-deploy lebih dari 60 hari
find /backup/chatbot_ai/pre-deploy -name "*.sql.gz" -mtime +60 -delete
```

---

### Script Backup Harian

Simpan sebagai `/usr/local/bin/chatbot-backup.sh`:

```bash
#!/bin/bash
set -euo pipefail

BACKUP_DIR="/backup/chatbot_ai/daily"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="$BACKUP_DIR/chatbot_ai_$TIMESTAMP.sql.gz"

# Load kredensial dari file .env (jangan hardcode)
ENV_FILE="/var/www/chatbot_ai/.env"
DB_DATABASE=$(grep "^DB_DATABASE=" "$ENV_FILE" | cut -d'=' -f2)
DB_USERNAME=$(grep "^DB_USERNAME=" "$ENV_FILE" | cut -d'=' -f2)
DB_PASSWORD=$(grep "^DB_PASSWORD=" "$ENV_FILE" | cut -d'=' -f2)
DB_HOST=$(grep "^DB_HOST=" "$ENV_FILE" | cut -d'=' -f2 | tr -d '\r')

echo "[$(date)] Memulai backup: $BACKUP_FILE"

mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  -h "$DB_HOST" \
  -u "$DB_USERNAME" \
  -p"$DB_PASSWORD" \
  "$DB_DATABASE" \
  | gzip > "$BACKUP_FILE"

# Hapus backup lebih dari 30 hari
find "$BACKUP_DIR" -name "*.sql.gz" -mtime +30 -delete

echo "[$(date)] Backup selesai: $BACKUP_FILE ($(du -sh "$BACKUP_FILE" | cut -f1))"
```

```bash
# Set permission script
sudo chmod +x /usr/local/bin/chatbot-backup.sh

# Test manual
sudo -u www-data /usr/local/bin/chatbot-backup.sh

# Tambahkan ke crontab untuk jalan setiap hari jam 01:00 (sebelum cleanup jam 02:00)
crontab -e -u www-data
# Tambahkan:
# 0 1 * * * /usr/local/bin/chatbot-backup.sh >> /var/log/chatbot-backup.log 2>&1
```

---

### Restore Database

```bash
# Restore dari file .sql.gz
gunzip < /backup/chatbot_ai/daily/chatbot_ai_20260326_020000.sql.gz | \
  mysql -u chatbot_user -p chatbot_ai

# Restore dari file .sql (tidak dikompresi)
mysql -u chatbot_user -p chatbot_ai < /backup/chatbot_ai_20260326_020000.sql

# Verifikasi setelah restore
mysql -u chatbot_user -p chatbot_ai -e "SHOW TABLES; SELECT COUNT(*) FROM customers; SELECT COUNT(*) FROM messages;"
```

---

### Peringatan Keamanan Backup

> **PENTING:**
> - File backup berisi data customer — lindungi sebaik mungkin
> - Set permission 640 pada file backup: `chmod 640 /backup/chatbot_ai/daily/*.sql.gz`
> - Jangan letakkan backup di folder public/
> - Jika backup dikirim ke tempat lain (remote server, cloud), enkripsi dulu
> - Backup di server yang sama dengan database bukan "backup sejati" — idealnya ada offsite copy

---

### Jadwal Backup Direkomendasikan

| Frekuensi | Waktu | Keterangan |
|---|---|---|
| Harian | 01:00 | Backup penuh setiap hari |
| Sebelum deploy | Manual | Wajib sebelum setiap deploy production |
| Sebelum migration | Manual | Wajib sebelum `php artisan migrate` |

---

## Bagian 2: Monitoring Log

### File Log Utama

| File | Isi | Cara Akses |
|---|---|---|
| `storage/logs/laravel-YYYY-MM-DD.log` | Log utama aplikasi (error, warning, info) | `tail -f storage/logs/laravel-$(date +%Y-%m-%d).log` |
| `storage/logs/chatbot-health.log` | Hasil health check setiap 30 menit | `tail -f storage/logs/chatbot-health.log` |
| `storage/logs/chatbot-cleanup.log` | Hasil cleanup harian | `tail -f storage/logs/chatbot-cleanup.log` |
| `/var/log/supervisor/chatbot-worker.log` | Output queue worker | `tail -f /var/log/supervisor/chatbot-worker.log` |

---

### Cara Monitoring Log

```bash
# Pantau log utama Laravel secara real-time
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log

# Filter hanya baris ERROR
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i "ERROR\|CRITICAL\|EMERGENCY"

# Filter error WhatsApp
grep -i "whatsapp\|webhook\|graph.facebook" storage/logs/laravel-$(date +%Y-%m-%d).log | tail -50

# Filter error OpenAI
grep -i "openai\|gpt\|llm\|intent\|extraction" storage/logs/laravel-$(date +%Y-%m-%d).log | tail -50

# Pantau health check
tail -f storage/logs/chatbot-health.log

# Pantau worker queue
tail -f /var/log/supervisor/chatbot-worker.log | grep -i "fail\|error\|exception"
```

---

### Indikator Error Penting

Perhatikan baris log berikut sebagai sinyal bahwa ada masalah:

| Pola Log | Artinya | Tindakan |
|---|---|---|
| `CRITICAL` atau `EMERGENCY` | Error parah | Segera investigasi |
| `WhatsApp send failed` | Pesan tidak terkirim | Cek token WhatsApp, cek failed messages di dashboard |
| `OpenAI request failed` / `timeout` | LLM tidak merespons | Cek API key, cek quota OpenAI |
| `Queue backlog` (dari health check) | Antrian job menumpuk | Cek worker, mungkin perlu restart atau tambah proses |
| `Failed message threshold` | Terlalu banyak pesan gagal | Cek WhatsApp API, cek koneksi |
| `Job timed out` | Job queue melebihi timeout | Selidiki job mana, mungkin perlu naikkan timeout |
| `Connection refused` / `SQLSTATE` | Masalah koneksi database | Cek MySQL service, cek kredensial |

---

### Cek Failed Jobs

```bash
# Via artisan
php artisan queue:failed

# Jumlah failed jobs
php artisan tinker --execute="echo \DB::table('failed_jobs')->count() . ' failed jobs';"

# Failed jobs per jam terakhir
php artisan tinker --execute="
  \$count = \DB::table('failed_jobs')
    ->where('failed_at', '>=', now()->subHour())
    ->count();
  echo \"Failed jobs dalam 1 jam terakhir: \$count\";
"
```

---

### Cek Health Check Status

```bash
# Jalankan health check manual dan lihat hasilnya
php artisan chatbot:health-check

# Lihat notifikasi health issue yang belum dibaca
php artisan tinker --execute="
  \$n = \App\Models\AdminNotification::where('type', 'health_issue')
    ->whereNull('read_at')
    ->count();
  echo \"Health notifications belum dibaca: \$n\";
"
```

---

### Cek Queue Worker Aktif

```bash
# Cek proses worker
ps aux | grep "queue:work" | grep -v grep

# Cek via Supervisor
sudo supervisorctl status chatbot-worker:*

# Jumlah job yang menunggu diproses
php artisan tinker --execute="echo \DB::table('jobs')->count() . ' jobs dalam antrian';"
```

---

### Cek Outbound Message Failure

```bash
# Lihat pesan dengan status failed
php artisan tinker --execute="
  \$failed = \DB::table('messages')
    ->where('direction', 'outbound')
    ->where('status', 'failed')
    ->where('created_at', '>=', now()->subDay())
    ->count();
  echo \"Pesan outbound gagal (24 jam terakhir): \$failed\";
"
```

---

### Cek Operasional Takeover & Escalation

```bash
# Conversation yang sedang dalam mode takeover admin
php artisan tinker --execute="
  \$count = \DB::table('conversations')
    ->where('admin_takeover', true)
    ->count();
  echo \"Conversation dalam takeover: \$count\";
"

# Eskalasi yang masih open
php artisan tinker --execute="
  \$count = \DB::table('escalations')
    ->where('status', 'open')
    ->count();
  echo \"Eskalasi terbuka: \$count\";
"
```

---

### Rekomendasi Log Stack Production

Untuk production sederhana tanpa layanan monitoring eksternal:

1. **Laravel daily log** — rotasi harian, retensi 14 hari (sudah dikonfigurasi di `config/logging.php`)
2. **Supervisor log** — untuk worker output
3. **Cron/syslog** — log scheduler OS
4. **Pantau manual** dengan `tail -f` minimal sekali sehari pada awal go-live

> **Jika ingin monitoring lebih lanjut** di masa depan: pertimbangkan Sentry (error tracking), UptimeRobot (uptime), atau Grafana + Loki (log aggregation). Tapi itu di luar scope Stage 11.
