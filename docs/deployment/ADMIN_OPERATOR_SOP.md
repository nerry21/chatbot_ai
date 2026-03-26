# SOP Admin & Operator — Chatbot AI Dashboard

Panduan operasional untuk admin dan operator menggunakan dashboard chatbot.
Dokumen ini ditulis untuk staf non-developer.

---

## Akses Dashboard

### Login

1. Buka browser, masuk ke: `https://yourdomain.com/admin` (atau sesuai URL yang diberikan)
2. Masukkan email dan password akun Anda
3. Jika berhasil, Anda akan masuk ke halaman dashboard utama

> **Jika tidak bisa login:** Hubungi admin sistem untuk reset password atau cek role akun Anda.

---

## Halaman Utama Dashboard

Setelah login, Anda akan melihat ringkasan:

- Jumlah conversation aktif
- Conversation yang sedang dalam mode **takeover** (admin menangani langsung)
- Eskalasi yang belum diselesaikan
- Notifikasi operasional terbaru

---

## Conversation & Takeover

### Melihat Daftar Conversation

1. Klik menu **Conversations** di navigasi
2. Daftar percakapan dengan customer ditampilkan
3. Anda bisa filter berdasarkan status: aktif, takeover, eskalasi, closed

### Melihat Isi Conversation

1. Klik nama customer atau nomor telepon di daftar
2. Histori pesan akan tampil di sisi kanan/bawah
3. Anda bisa melihat semua pesan bot dan customer

### Takeover Conversation (Admin Ambil Alih)

**Kapan harus takeover:**
- Customer marah atau frustrasi dan bot tidak bisa menangani
- Ada permintaan khusus yang di luar kemampuan bot
- Situasi sensitif yang butuh penanganan manusia

**Cara takeover:**
1. Buka conversation yang ingin diambil alih
2. Klik tombol **"Takeover"** atau **"Ambil Alih"**
3. Setelah takeover aktif, bot **tidak akan membalas otomatis** di conversation ini
4. Anda sekarang bisa reply langsung ke customer

> **Perhatian:** Setelah takeover, customer tidak mendapat balasan bot. Pastikan Anda aktif memantau.

### Reply Manual ke Customer

1. Pastikan conversation sudah dalam mode takeover
2. Ketik pesan di kotak teks di bagian bawah
3. Klik **"Kirim"** atau tekan Enter
4. Pesan akan dikirim via WhatsApp ke customer

### Release ke Bot (Kembalikan ke Mode Otomatis)

**Kapan boleh release:**
- Masalah customer sudah teratasi
- Customer sudah puas dan tidak butuh bantuan lanjutan
- Anda yakin bot bisa melanjutkan percakapan dengan baik

**Cara release:**
1. Buka conversation yang sedang dalam takeover
2. Klik tombol **"Release"** atau **"Kembalikan ke Bot"**
3. Bot akan kembali aktif membalas customer secara otomatis

> **Jangan release** jika masalah belum selesai atau customer masih menunggu tindak lanjut.

---

## Eskalasi

### Melihat Daftar Eskalasi

1. Klik menu **Escalations** atau **Eskalasi**
2. Daftar eskalasi yang open, assigned, dan resolved ditampilkan

### Assign Eskalasi ke Operator

1. Buka eskalasi yang ingin di-assign
2. Klik tombol **"Assign"**
3. Pilih nama operator dari daftar
4. Klik **Simpan**

### Resolve Eskalasi

1. Buka eskalasi yang sudah ditangani
2. Isi catatan resolusi jika diperlukan
3. Klik tombol **"Resolve"** atau **"Selesaikan"**
4. Eskalasi akan berubah status menjadi "Resolved"

> **Jangan resolve** eskalasi sebelum masalah customer benar-benar selesai.

---

## Failed Messages & Resend

### Melihat Pesan Gagal

1. Klik menu **Failed Messages** atau **Pesan Gagal**
2. Daftar pesan yang gagal dikirim ke customer ditampilkan
3. Setiap item menampilkan: nomor customer, isi pesan, waktu gagal, jumlah percobaan

### Resend Pesan yang Gagal

1. Pilih pesan yang ingin dikirim ulang
2. Klik tombol **"Resend"** atau **"Kirim Ulang"**
3. Sistem akan mencoba mengirim kembali
4. Tunggu beberapa detik, lalu refresh halaman untuk melihat status terbaru

> **Catatan:** Ada batas waktu cooldown antar resend. Jika tombol resend tidak aktif, tunggu beberapa menit.

---

## Notifikasi

### Membaca Notifikasi

1. Ikon notifikasi ada di pojok kanan atas dashboard (biasanya berupa bel)
2. Klik ikon untuk melihat daftar notifikasi
3. Notifikasi penting meliputi:
   - Health issue sistem
   - Eskalasi baru
   - Pesan gagal terkirim dalam jumlah banyak
   - Conversation masuk saat takeover aktif

### Menandai Notifikasi Sudah Dibaca

1. Klik notifikasi untuk membukanya
2. Atau klik **"Tandai Semua Sudah Dibaca"** untuk bersihkan semua

---

## AI Quality Summary

### Melihat Ringkasan Kualitas AI

1. Klik menu **AI Quality** atau **Kualitas AI**
2. Anda akan melihat ringkasan jawaban bot dalam beberapa hari terakhir:
   - Persentase jawaban dengan confidence tinggi vs rendah
   - Contoh jawaban yang dinilai berkualitas rendah
   - Tren kualitas dari hari ke hari

### Cara Membaca

| Indikator | Artinya |
|---|---|
| **High confidence** | Bot yakin dengan jawabannya — biasanya aman |
| **Low confidence** | Bot tidak yakin — perlu diperhatikan |
| **Fallback** | Bot tidak bisa menjawab dan memberikan respons default |

> Jika terlalu banyak low confidence atau fallback, laporkan ke admin teknis untuk review knowledge base.

---

## Batasan Operasional

### Kapan Operator HARUS Takeover

- Customer meminta berbicara dengan manusia secara eksplisit
- Bot memberikan jawaban yang salah atau menyesatkan berulang kali
- Situasi darurat atau keluhan serius
- Transaksi atau booking yang nilainya besar dan perlu konfirmasi manual

### Kapan BOLEH Release ke Bot

- Pertanyaan customer sudah dijawab tuntas
- Customer hanya ingin informasi sederhana (jadwal, harga, lokasi)
- Tidak ada tanda-tanda customer frustrasi

### Kapan Harus Eskalasi ke Admin Penuh

- Ada masalah teknis yang operator tidak bisa selesaikan
- Ada keluhan customer yang berpotensi legal atau reputasional
- Ada anomali dalam sistem (pesan hilang, bot tidak merespons sama sekali)

### Yang TIDAK BOLEH Dilakukan Sembarangan

> **JANGAN** lakukan ini tanpa konfirmasi admin:
>
> - Me-reset atau menghapus conversation customer
> - Mengubah pengaturan sistem dari dashboard
> - Membuat user admin baru
> - Menonaktifkan notifikasi sistem
> - Me-resolve eskalasi yang belum benar-benar selesai
> - Memaksakan resend pesan berkali-kali dalam waktu singkat

---

## Kontak Bantuan

Jika ada masalah teknis yang tidak bisa diselesaikan dari dashboard:

1. **Pertama:** Catat error atau situasi yang terjadi (screenshot jika bisa)
2. **Kedua:** Hubungi admin teknis melalui jalur yang disepakati tim
3. **Ketiga:** Jangan mencoba "memperbaiki" sendiri dengan cara yang tidak yakin

---

## Ringkasan Cepat (Quick Reference)

| Situasi | Tindakan |
|---|---|
| Customer marah, bot tidak bisa tangani | Takeover → Reply manual → Release jika sudah selesai |
| Pesan tidak sampai ke customer | Cek Failed Messages → Resend |
| Masalah butuh eskalasi | Buat eskalasi → Assign ke operator tepat |
| Banyak notifikasi health issue | Laporkan ke admin teknis |
| Bot memberikan jawaban salah berulang | Takeover dulu, laporkan ke admin teknis |
