# Rollback Plan

Rencana tindakan jika deployment production gagal dan sistem perlu dikembalikan ke kondisi sebelumnya.

---

## Kapan Rollback Diperlukan?

Rollback diperlukan jika setelah deploy terjadi salah satu dari kondisi berikut:

| Indikator | Keterangan |
|---|---|
| Error 500 / crash terus-menerus | Aplikasi tidak bisa diakses |
| Migration gagal di tengah jalan | Skema database tidak konsisten |
| Worker queue langsung crash | Semua job gagal diproses |
| WhatsApp webhook tidak merespons | Bot tidak bisa menerima pesan |
| Health check status CRITICAL segera setelah deploy | Sistem tidak berfungsi |
| Data production corrupt atau hilang | Perlu restore dari backup |

---

## Indikator Deploy Gagal

Amati dalam 5-10 menit pertama setelah deploy:

```bash
# Cek log langsung
tail -f storage/logs/laravel-$(date +%Y-%m-%d).log

# Cek apakah ada error 500
# (dari log Nginx/Apache, atau dari browser)

# Cek worker
sudo supervisorctl status chatbot-worker:*

# Cek health
php artisan chatbot:health-check

# Cek failed jobs melonjak
php artisan queue:failed
```

---

## Langkah Rollback

### Langkah 1: Stop Risiko Segera

```bash
# Aktifkan maintenance mode agar user tidak kena error
php artisan down --message="Sistem sedang dalam pemeliharaan." --retry=120

# Hentikan worker agar tidak memproses job dengan kode baru yang rusak
sudo supervisorctl stop chatbot-worker:*
```

### Langkah 2: Kembali ke Release Sebelumnya

**Jika menggunakan git:**

```bash
# Lihat commit terakhir yang stabil
git log --oneline -10

# Kembali ke commit sebelum deploy yang gagal
# GANTI <commit_hash> dengan hash commit yang ingin dituju
git checkout <commit_hash>

# Atau jika deploy via branch:
git reset --hard HEAD~1
# (hati-hati: ini menghapus commit terbaru dari working tree)
```

**Jika menggunakan deploy directory (misal: releases/):**

```bash
# Ganti symlink current ke release sebelumnya
ln -sfn /var/www/chatbot_ai/releases/PREVIOUS_RELEASE /var/www/chatbot_ai/current
```

### Langkah 3: Restore Composer Dependencies

```bash
# Jika ada perubahan di composer.json
composer install --no-dev --optimize-autoloader
```

### Langkah 4: Restore Database (Jika Migration Sempat Jalan)

> **BACA DULU: Apakah migration bersifat reversible?**
>
> - Migration `add column` → biasanya bisa di-rollback dengan `php artisan migrate:rollback`
> - Migration `drop column` atau `drop table` → **data sudah hilang, rollback tidak bisa mengembalikan data**
> - Selalu restore dari backup jika ada keraguan

```bash
# Opsi A: Rollback migration (jika reversible dan yakin aman)
php artisan migrate:rollback --step=1

# Opsi B: Restore dari backup (lebih aman, DIREKOMENDASIKAN)
# Hentikan koneksi aktif ke database dulu jika memungkinkan
gunzip < /backup/chatbot_ai/pre-deploy/chatbot_ai_pre_deploy_TANGGAL.sql.gz | \
  mysql -u chatbot_user -p chatbot_ai

# Verifikasi tabel kritis setelah restore
mysql -u chatbot_user -p chatbot_ai -e "
  SELECT COUNT(*) as customers FROM customers;
  SELECT COUNT(*) as conversations FROM conversations;
  SELECT COUNT(*) as messages FROM messages;
"
```

### Langkah 5: Clear Cache Setelah Rollback

```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### Langkah 6: Restart Worker

```bash
# Restart worker dengan kode yang sudah di-rollback
sudo supervisorctl start chatbot-worker:*
sudo supervisorctl status chatbot-worker:*
```

### Langkah 7: Nonaktifkan Maintenance Mode

```bash
php artisan up
```

### Langkah 8: Verifikasi Sistem Pulih

```bash
# Cek aplikasi bisa jalan
php artisan about

# Cek health
php artisan chatbot:health-check

# Cek worker
sudo supervisorctl status chatbot-worker:*

# Cek log — pastikan error berhenti
tail -50 storage/logs/laravel-$(date +%Y-%m-%d).log

# Test manual: kirim pesan WhatsApp ke bot dan cek respons
```

---

## Checklist Rollback

```
[ ] Maintenance mode diaktifkan
[ ] Worker dihentikan
[ ] Code dikembalikan ke versi sebelumnya
[ ] composer install dijalankan (jika diperlukan)
[ ] Database di-restore dari backup (jika migration sempat jalan)
[ ] Cache dibersihkan dan di-rebuild
[ ] Worker distart ulang
[ ] Maintenance mode dinonaktifkan
[ ] Health check OK
[ ] Test kirim/terima pesan berhasil
[ ] Log bersih (tidak ada error baru)
```

---

## Peringatan Migration Irreversible

> **PERHATIAN KERAS:**
>
> Beberapa jenis migration **tidak bisa di-rollback tanpa kehilangan data**:
>
> - `dropColumn()` — kolom dan datanya hilang
> - `dropTable()` / `drop()` — tabel dan semua datanya hilang
> - `change()` yang mempersempit tipe kolom — data bisa terpotong
>
> **Untuk kasus ini, satu-satunya cara rollback yang aman adalah restore dari backup.**
>
> Ini adalah alasan mengapa backup sebelum deploy adalah **wajib**, bukan opsional.

---

## Script Rollback

Gunakan `deploy/rollback.sh` untuk rollback semi-otomatis (lihat di folder `deploy/`).

```bash
# Jalankan script rollback
bash deploy/rollback.sh <commit_hash_atau_release_name>
```

---

## Post-Rollback: Root Cause Analysis

Setelah sistem pulih, **jangan langsung deploy ulang**. Lakukan:

1. Baca log error secara lengkap
2. Identifikasi penyebab gagal (migration error, code error, konfigurasi salah)
3. Fix di environment staging/development
4. Test ulang di staging sebelum deploy ulang ke production
5. Siapkan backup baru sebelum deploy ulang
