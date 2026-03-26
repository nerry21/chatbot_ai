# Queue Worker & Scheduler Setup

Panduan lengkap menjalankan queue worker dan scheduler Laravel di production.

---

## Queue Worker

### Rekomendasi Connection

| Environment | Connection | Alasan |
|---|---|---|
| Development | `sync` | Langsung proses, mudah debug |
| Staging/Production (tanpa Redis) | `database` | Sederhana, tidak perlu dependensi tambahan |
| Production (dengan Redis) | `redis` | Lebih cepat, lebih ringan beban DB |

Default project ini: **`database`** — cocok untuk VPS tanpa Redis.

---

### Menjalankan Worker (Development)

```bash
# Worker sederhana, semua queue, proses satu per satu
php artisan queue:work

# Worker dengan timeout dan memory limit
php artisan queue:work --timeout=60 --memory=128

# Worker dengan queue spesifik (prioritas: high dulu, baru default)
php artisan queue:work --queue=high,default
```

---

### Menjalankan Worker (Production)

Jangan jalankan `queue:work` secara manual di terminal — gunakan **Supervisor** agar worker otomatis restart jika mati.

```bash
# Perintah yang dijalankan Supervisor (lihat konfigurasi di bawah)
php /var/www/chatbot_ai/artisan queue:work database \
    --sleep=3 \
    --tries=3 \
    --timeout=60 \
    --memory=128 \
    --max-jobs=500 \
    --max-time=3600
```

**Penjelasan flag:**

| Flag | Nilai | Keterangan |
|---|---|---|
| `--sleep=3` | 3 detik | Jeda polling jika queue kosong |
| `--tries=3` | 3x | Maksimum percobaan sebelum job masuk failed_jobs |
| `--timeout=60` | 60 detik | Maksimum waktu eksekusi satu job |
| `--memory=128` | 128 MB | Restart worker jika melebihi limit ini |
| `--max-jobs=500` | 500 job | Restart worker setelah memproses 500 job (cegah memory leak) |
| `--max-time=3600` | 1 jam | Restart worker setelah berjalan 1 jam |

---

## Konfigurasi Supervisor

Supervisor adalah process manager yang memastikan queue worker selalu berjalan.

### Instalasi Supervisor

```bash
# Ubuntu/Debian
sudo apt-get update && sudo apt-get install supervisor -y

# CentOS/RHEL
sudo yum install supervisor -y
```

### File Konfigurasi Supervisor

Buat file: `/etc/supervisor/conf.d/chatbot-worker.conf`

```ini
[program:chatbot-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/chatbot_ai/artisan queue:work database --sleep=3 --tries=3 --timeout=60 --memory=128 --max-jobs=500 --max-time=3600
directory=/var/www/chatbot_ai
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/chatbot-worker.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=5
stopwaitsecs=90
```

**Penjelasan konfigurasi:**

| Parameter | Nilai | Keterangan |
|---|---|---|
| `numprocs` | `2` | Jalankan 2 worker paralel (sesuaikan dengan CPU) |
| `user` | `www-data` | Jalankan sebagai user web server, bukan root |
| `stopwaitsecs` | `90` | Beri waktu worker menyelesaikan job yang sedang berjalan |
| `autorestart` | `true` | Restart otomatis jika worker crash |

### Aktivasi & Kontrol Supervisor

```bash
# Reload konfigurasi Supervisor setelah membuat/mengubah .conf
sudo supervisorctl reread
sudo supervisorctl update

# Mulai worker
sudo supervisorctl start chatbot-worker:*

# Cek status worker
sudo supervisorctl status chatbot-worker:*

# Restart worker (setelah deploy)
sudo supervisorctl restart chatbot-worker:*

# Stop worker dengan aman (tunggu job selesai)
sudo supervisorctl stop chatbot-worker:*

# Lihat log worker
sudo tail -f /var/log/supervisor/chatbot-worker.log
```

---

## Restart Worker Saat Deploy

**Wajib dilakukan setelah setiap deploy** agar worker memuat kode terbaru:

```bash
# Opsi 1: Restart langsung via Supervisor (direkomendasikan)
sudo supervisorctl restart chatbot-worker:*

# Opsi 2: Signal graceful reload (worker selesaikan job dulu, lalu restart)
php artisan queue:restart
# Kemudian pastikan Supervisor restart worker baru
sudo supervisorctl restart chatbot-worker:*
```

> **Catatan:** `php artisan queue:restart` hanya memberi sinyal — worker yang sedang berjalan akan finish job saat ini lalu stop. Supervisor akan otomatis start worker baru.

---

## Mengelola Failed Jobs

```bash
# Lihat daftar failed jobs
php artisan queue:failed

# Lihat detail satu failed job berdasarkan ID
php artisan queue:failed --id=JOB_UUID

# Retry satu failed job
php artisan queue:retry JOB_UUID

# Retry semua failed jobs
php artisan queue:retry all

# Hapus satu failed job
php artisan queue:forget JOB_UUID

# Hapus semua failed jobs (hati-hati!)
php artisan queue:flush

# Cek jumlah failed jobs di database langsung
php artisan tinker --execute="echo \DB::table('failed_jobs')->count();"
```

---

## Memantau Queue Worker

```bash
# Cek apakah Supervisor berjalan
sudo systemctl status supervisor

# Cek status semua worker
sudo supervisorctl status

# Lihat job yang sedang antri (database queue)
php artisan tinker --execute="echo \DB::table('jobs')->count();"

# Lihat log Supervisor
sudo tail -100 /var/log/supervisor/chatbot-worker.log

# Cek apakah ada proses artisan queue:work yang berjalan
ps aux | grep "queue:work"
```

---

## Scheduler Laravel

Laravel scheduler berjalan melalui satu cron entry yang memanggil `php artisan schedule:run` setiap menit. Scheduler kemudian memutuskan task mana yang perlu dijalankan.

### Cron Entry

Tambahkan ke crontab server (jalankan sebagai user yang sama dengan web server):

```bash
# Edit crontab
crontab -e -u www-data

# Tambahkan baris ini:
* * * * * cd /var/www/chatbot_ai && php artisan schedule:run >> /dev/null 2>&1
```

> **Pastikan:** Path ke project dan PHP sudah benar. Jika PHP tidak di PATH default, gunakan path absolut:
> ```
> * * * * * cd /var/www/chatbot_ai && /usr/bin/php artisan schedule:run >> /dev/null 2>&1
> ```

### Verifikasi Scheduler Berjalan

```bash
# Test jalankan scheduler manual (tidak menunggu cron)
php artisan schedule:run

# Lihat daftar task terjadwal
php artisan schedule:list

# Lihat log scheduler di Laravel log
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log | grep -i "schedule\|cleanup\|health"

# Lihat log health check khusus
tail -f storage/logs/chatbot-health.log

# Lihat log cleanup khusus
tail -f storage/logs/chatbot-cleanup.log
```

### Task Terjadwal di Project Ini

| Task | Jadwal | Deskripsi |
|---|---|---|
| `chatbot:health-check` | Setiap 30 menit | Cek kesehatan sistem, buat notifikasi jika ada masalah |
| `chatbot:cleanup` | Setiap hari pukul 02:00 | Bersihkan data lama (notifikasi, audit log, AI log, eskalasi) |

### Kaitan Scheduler dengan routes/console.php

Project ini menggunakan Laravel 11 dengan pendekatan `routes/console.php` untuk mendefinisikan scheduled tasks:

```php
// routes/console.php
Schedule::command('chatbot:health-check')->everyThirtyMinutes();
Schedule::command('chatbot:cleanup --dry-run=0')->dailyAt('02:00');
```

---

## Troubleshooting

### Worker tidak memproses job

```bash
# 1. Cek apakah worker berjalan
sudo supervisorctl status chatbot-worker:*

# 2. Cek apakah ada job di queue
php artisan tinker --execute="echo \DB::table('jobs')->count();"

# 3. Cek log error worker
sudo tail -50 /var/log/supervisor/chatbot-worker.log

# 4. Cek permission storage/ (worker perlu bisa menulis log)
ls -la storage/logs/
```

### Scheduler tidak jalan

```bash
# 1. Cek apakah cron aktif
sudo systemctl status cron

# 2. Test manual
php artisan schedule:run --verbose

# 3. Cek crontab
crontab -l -u www-data

# 4. Cek apakah ada error permission
php artisan schedule:run 2>&1
```

### Job timeout terus

```bash
# Periksa apakah CHATBOT_MAX_SEND_ATTEMPTS sudah diset dengan benar di .env
# Periksa timeout di config/queue.php (retry_after)
# Pertimbangkan naikkan --timeout pada worker jika job membutuhkan waktu lebih lama
```
