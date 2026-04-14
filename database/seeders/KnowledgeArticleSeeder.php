<?php

namespace Database\Seeders;

use App\Models\KnowledgeArticle;
use Illuminate\Database\Seeder;

class KnowledgeArticleSeeder extends Seeder
{
    public function run(): void
    {
        $articles = $this->articles();

        foreach ($articles as $article) {
            KnowledgeArticle::updateOrCreate(
                ['slug' => $article['slug']],
                [
                    'category'  => $article['category'],
                    'title'     => $article['title'],
                    'content'   => $article['content'],
                    'keywords'  => $article['keywords'],
                    'is_active' => true,
                ]
            );
        }
    }

    /**
     * @return array<int, array{category: string, title: string, slug: string, content: string, keywords: array}>
     */
    private function articles(): array
    {
        return [
            // ─── JADWAL ───────────────────────────────────────────────
            [
                'category' => 'jadwal',
                'title'    => 'Jadwal Keberangkatan Travel',
                'slug'     => 'jadwal-keberangkatan-travel',
                'content'  => "Travel JET beroperasi setiap hari termasuk hari Minggu dan hari libur.\n\nJadwal keberangkatan yang tersedia:\n- Subuh: 05.30 WIB\n- Pagi: 07.00 WIB\n- Pagi: 09.00 WIB\n- Siang: 13.00 WIB\n- Sore: 16.00 WIB\n- Malam: 19.00 WIB\n\nJadwal bisa dipilih sesuai kebutuhan. Kalau mau berangkat habis subuh, habis zuhur, habis asar, atau habis isya — tinggal pilih jadwal yang paling dekat.\n\nSebaiknya booking dari jauh hari terutama saat musim libur atau hari raya karena biasanya cepat penuh.",
                'keywords' => ['jadwal', 'jam', 'keberangkatan', 'berangkat', 'pagi', 'siang', 'sore', 'malam', 'subuh', 'hari', 'minggu', 'libur', 'lebaran', 'raya', 'azan', 'zuhur', 'asar', 'isya', 'magrib'],
            ],

            // ─── HARGA / ONGKOS ───────────────────────────────────────
            [
                'category' => 'harga',
                'title'    => 'Tarif dan Ongkos Travel',
                'slug'     => 'tarif-ongkos-travel',
                'content'  => "Tarif travel JET per orang (sekali jalan):\n\n• Rute Rokan Hulu (SKPD, Simpang D, SKPC, SKPA, SKPB, Simpang Kumu, Muara Rumbai, Surau Tinggi, Pasir Pengaraian) ke Pekanbaru: Rp 150.000\n• Rute Rokan Hulu ke Kabun: Rp 120.000\n• Rute Rokan Hulu ke Tandun: Rp 100.000\n• Rute Rokan Hulu ke Petapahan: Rp 130.000\n• Rute Rokan Hulu ke Suram: Rp 120.000\n• Rute Rokan Hulu ke Aliantan: Rp 120.000\n• Rute Rokan Hulu ke Bangkinang: Rp 130.000\n• Bangkinang ke Pekanbaru: Rp 100.000\n• Ujung Batu ke Pekanbaru: Rp 130.000\n• Suram ke Pekanbaru: Rp 120.000\n• Petapahan ke Pekanbaru: Rp 100.000\n\nSemua rute berlaku bolak-balik (bidirectional). Tarif bisa ada penyesuaian saat musim libur. Untuk ongkos final akan dikonfirmasi ulang oleh Admin Utama menyesuaikan lokasi jemput dan pengantaran.",
                'keywords' => ['harga', 'tarif', 'ongkos', 'biaya', 'berapa', 'bayar', 'rupiah', 'rp', 'murah', 'mahal', 'diskon', 'promo'],
            ],

            // ─── SEAT / TEMPAT DUDUK ──────────────────────────────────
            [
                'category' => 'seat',
                'title'    => 'Ketersediaan dan Pilihan Seat',
                'slug'     => 'ketersediaan-pilihan-seat',
                'content'  => "Travel JET menyediakan pilihan seat:\n- CC (depan samping sopir)\n- BS Kiri (baris kedua kiri)\n- BS Kanan (baris kedua kanan)\n- BS Tengah (baris kedua tengah, perlu konfirmasi admin)\n- Belakang Kiri\n- Belakang Kanan\n\nSeat bisa dipilih saat booking selama masih tersedia. Sistem akan mengunci seat yang sudah dipilih sehingga penumpang lain tidak bisa memilih seat yang sama di tanggal dan jam yang sama.\n\nUntuk mengetahui ketersediaan seat di tanggal dan jam tertentu, silakan langsung tanyakan dan kami akan cek secara real-time.\n\nKalau mau duduk dekat jendela, dekat pintu, atau seat yang lega — bisa request dan kami usahakan sesuai ketersediaan.",
                'keywords' => ['seat', 'kursi', 'tempat duduk', 'duduk', 'kosong', 'tersedia', 'pilih', 'depan', 'belakang', 'tengah', 'jendela', 'cc', 'bs'],
            ],

            // ─── BOOKING / PEMESANAN ──────────────────────────────────
            [
                'category' => 'booking',
                'title'    => 'Cara Booking Travel',
                'slug'     => 'cara-booking-travel',
                'content'  => "Booking travel JET mudah, cukup lewat WhatsApp.\n\nData yang dibutuhkan:\n1. Nama penumpang\n2. Jumlah penumpang\n3. Nomor HP aktif (boleh pakai nomor orang lain asal aktif)\n4. Tanggal keberangkatan\n5. Jam keberangkatan\n6. Pilihan seat\n7. Lokasi penjemputan\n8. Alamat lengkap jemput\n9. Tujuan pengantaran\n\nBisa booking untuk diri sendiri, orang tua, teman, atau keluarga. Booking bisa dilakukan dari jauh hari — malah lebih aman supaya seat nggak habis. Booking dadakan juga bisa selama seat masih ada.\n\nSetelah data lengkap dan booking diproses, seat langsung diamankan. Konfirmasi booking akan dikirim lewat chat.",
                'keywords' => ['booking', 'boking', 'pesan', 'pemesanan', 'order', 'reservasi', 'cara', 'data', 'syarat', 'konfirmasi', 'bukti'],
            ],

            // ─── PENJEMPUTAN ──────────────────────────────────────────
            [
                'category' => 'penjemputan',
                'title'    => 'Penjemputan Penumpang',
                'slug'     => 'penjemputan-penumpang',
                'content'  => "Travel JET menjemput penumpang di alamat yang ditentukan.\n\nArea penjemputan meliputi: SKPD, Simpang D, SKPC, SKPA, SKPB, Simpang Kumu, Muara Rumbai, Surau Tinggi, Pasir Pengaraian, Ujung Batu, Tandun, Petapahan, Suram, Aliantan, Kuok, Kabun, Bangkinang, Silam, Pekanbaru.\n\nBisa dijemput di rumah, kantor, hotel, rumah sakit, terminal, atau titik manapun yang dijangkau mobil. Kalau gang kecil atau susah akses, bisa janjian di titik terdekat.\n\nBisa kirim shareloc atau patokan alamat. Driver akan menghubungi sebelum penjemputan. Biasanya dijemput lebih awal dari jam berangkat supaya semua penumpang terkumpul.",
                'keywords' => ['jemput', 'penjemputan', 'dijemput', 'alamat', 'lokasi', 'titik', 'rumah', 'kantor', 'hotel', 'shareloc', 'driver'],
            ],

            // ─── PENGANTARAN ──────────────────────────────────────────
            [
                'category' => 'pengantaran',
                'title'    => 'Pengantaran Penumpang',
                'slug'     => 'pengantaran-penumpang',
                'content'  => "Penumpang diantar sampai alamat tujuan selama masih dalam area layanan.\n\nBisa turun di alamat tujuan, simpang tertentu, mall, kampus, bandara, atau titik lain yang searah rute. Kalau tujuan berubah di perjalanan, bisa dikomunikasikan ke driver.\n\nTujuan antar yang tersedia: SKPD, Simpang D, SKPC, SKPA, SKPB, Simpang Kumu, Muara Rumbai, Surau Tinggi, Pasir Pengaraian, Ujung Batu, Tandun, Petapahan, Suram, Aliantan, Kuok, Kabun, Bangkinang, Silam, Pekanbaru.\n\nUntuk bandara Sultan Syarif Kasim juga bisa, tinggal tulis tujuan bandara saat booking.",
                'keywords' => ['antar', 'pengantaran', 'tujuan', 'turun', 'sampai', 'alamat', 'bandara', 'mall', 'kampus', 'panam'],
            ],

            // ─── RUTE ─────────────────────────────────────────────────
            [
                'category' => 'rute',
                'title'    => 'Rute dan Area Layanan Travel',
                'slug'     => 'rute-area-layanan',
                'content'  => "Travel JET melayani rute Pekanbaru – Rokan Hulu dan Rokan Hulu – Pekanbaru.\n\nLokasi yang dilayani:\n- Area Rokan Hulu: SKPD, Simpang D, SKPC, SKPA, SKPB, Simpang Kumu, Muara Rumbai, Surau Tinggi, Pasir Pengaraian\n- Area Ujung Batu\n- Area Kampar: Tandun, Petapahan, Suram, Aliantan, Kuok, Kabun, Bangkinang, Silam\n- Pekanbaru\n\nRute perjalanan menyesuaikan titik jemput dan antar penumpang. Travel tidak berputar-putar, tapi tetap menyesuaikan rute penumpang lain dalam satu perjalanan.",
                'keywords' => ['rute', 'area', 'layanan', 'lokasi', 'daerah', 'lewat', 'singgah', 'tol', 'ujung batu', 'tandun', 'kabun', 'bangkinang'],
            ],

            // ─── ARMADA ───────────────────────────────────────────────
            [
                'category' => 'armada',
                'title'    => 'Armada dan Kendaraan Travel',
                'slug'     => 'armada-kendaraan',
                'content'  => "Travel JET menggunakan mobil travel standar seperti Innova, Avanza, atau unit sejenis. Semua unit dilengkapi AC.\n\nTravel ini bukan 1 kendaraan saja — ada beberapa armada yang beroperasi setiap hari sesuai jadwal dan rute. Kapasitas penumpang per unit menyesuaikan jenis mobil yang digunakan.\n\nTravel gabung dengan penumpang lain (bukan carter). Kalau mau sewa satu mobil penuh (carter), tersedia dengan tarif terpisah.",
                'keywords' => ['mobil', 'kendaraan', 'armada', 'innova', 'avanza', 'ac', 'unit', 'nyaman', 'berapa', 'isi', 'kapasitas'],
            ],

            // ─── BAGASI ──────────────────────────────────────────────
            [
                'category' => 'bagasi',
                'title'    => 'Bagasi dan Barang Bawaan',
                'slug'     => 'bagasi-barang-bawaan',
                'content'  => "Penumpang boleh membawa koper standar, tas, dan ransel. Untuk barang besar atau banyak, sebaiknya diinfokan dulu supaya bisa disiapkan ruang bagasi.\n\nBarang mudah pecah bisa dibawa asal dikemas aman. Stroller bayi juga boleh. Untuk hewan peliharaan perlu konfirmasi dulu.\n\nKalau barang berlebih biasanya ada penyesuaian biaya bagasi tambahan.",
                'keywords' => ['bagasi', 'koper', 'tas', 'barang', 'bawaan', 'bawa', 'berat', 'besar', 'pecah', 'stroller', 'hewan'],
            ],

            // ─── PEMBAYARAN ──────────────────────────────────────────
            [
                'category' => 'pembayaran',
                'title'    => 'Metode Pembayaran',
                'slug'     => 'metode-pembayaran',
                'content'  => "Pembayaran bisa dilakukan dengan:\n- Cash saat dijemput\n- Transfer bank\n- QRIS\n\nUntuk jadwal yang ramai biasanya diminta DP dulu supaya seat aman. Setelah transfer full, booking langsung di-lock.\n\nBukti transfer bisa dikirim lewat chat. Invoice juga bisa diminta kalau diperlukan.\n\nKalau orang lain yang berangkat, pembayaran tetap bisa dilakukan oleh pemesan.",
                'keywords' => ['bayar', 'pembayaran', 'transfer', 'dp', 'cash', 'tunai', 'qris', 'rekening', 'invoice', 'bukti', 'lunas'],
            ],

            // ─── CANCEL / REFUND ─────────────────────────────────────
            [
                'category' => 'cancel',
                'title'    => 'Pembatalan dan Refund',
                'slug'     => 'pembatalan-refund',
                'content'  => "Pembatalan booking bisa dilakukan dengan menghubungi admin.\n\nUntuk DP yang sudah dibayar, mengikuti ketentuan pembatalan — tergantung waktu pembatalan dan kondisi booking. Refund bisa diproses sesuai kebijakan yang berlaku.\n\nSebaiknya kalau memang jadi berangkat, langsung konfirmasi supaya seat tetap aman.",
                'keywords' => ['batal', 'cancel', 'refund', 'uang kembali', 'dp', 'pembatalan', 'hangus'],
            ],

            // ─── RESCHEDULE ──────────────────────────────────────────
            [
                'category' => 'reschedule',
                'title'    => 'Ubah Jadwal dan Data Booking',
                'slug'     => 'ubah-jadwal-data-booking',
                'content'  => "Perubahan booking bisa dilakukan selama seat di jadwal baru masih tersedia.\n\nYang bisa diubah:\n- Tanggal keberangkatan\n- Jam keberangkatan\n- Nama penumpang\n- Nomor HP\n- Alamat jemput\n- Alamat tujuan\n- Seat\n\nUntuk perubahan jadwal, bisa dikomunikasikan langsung lewat chat. Kalau sudah dekat waktu keberangkatan, sebaiknya hubungi admin segera.",
                'keywords' => ['ubah', 'ganti', 'pindah', 'reschedule', 'jadwal', 'tanggal', 'jam', 'nama', 'alamat', 'koreksi', 'revisi'],
            ],

            // ─── KIRIM BARANG ────────────────────────────────────────
            [
                'category' => 'kirim_barang',
                'title'    => 'Pengiriman Barang / Paket',
                'slug'     => 'pengiriman-barang-paket',
                'content'  => "Selain penumpang, travel JET juga melayani titip barang dan pengiriman paket antar kota.\n\nBisa kirim dokumen, oleh-oleh, makanan (asal dikemas rapi), dan barang lainnya. Tarif barang berbeda dengan tarif penumpang.\n\nData yang dibutuhkan: detail pengirim, detail penerima, dan jenis barang. Pembayaran bisa di tempat asal atau di tempat tujuan (COD).",
                'keywords' => ['kirim', 'paket', 'barang', 'titip', 'dokumen', 'oleh-oleh', 'makanan', 'pengiriman', 'cod'],
            ],

            // ─── CARTER ──────────────────────────────────────────────
            [
                'category' => 'carter',
                'title'    => 'Carter / Sewa Mobil',
                'slug'     => 'carter-sewa-mobil',
                'content'  => "Selain travel reguler (gabung penumpang lain), JET juga melayani carter — sewa satu mobil penuh khusus untuk Anda.\n\nBedanya: travel reguler per seat (lebih murah), carter satu mobil penuh (lebih privat). Tarif carter tergantung tujuan dan jarak.\n\nUntuk carter dan rental, bisa hubungi admin untuk penawaran harga.",
                'keywords' => ['carter', 'sewa', 'rental', 'pribadi', 'privat', 'full', 'satu mobil', 'dropping'],
            ],

            // ─── DRIVER ──────────────────────────────────────────────
            [
                'category' => 'driver',
                'title'    => 'Informasi Driver',
                'slug'     => 'informasi-driver',
                'content'  => "Driver akan menghubungi penumpang sebelum penjemputan. Biasanya 30 menit atau saat mendekati jam jemput.\n\nKalau ada permintaan khusus (penumpang lansia, barang banyak, minta diingatkan saat sampai tujuan), bisa dicatatkan dan disampaikan ke driver.\n\nDriver kami ramah dan berpengalaman melayani penumpang harian.",
                'keywords' => ['driver', 'sopir', 'hubungi', 'telepon', 'kabari', 'ramah', 'nomor', 'kontak'],
            ],

            // ─── PERJALANAN ──────────────────────────────────────────
            [
                'category' => 'perjalanan',
                'title'    => 'Selama Perjalanan',
                'slug'     => 'selama-perjalanan',
                'content'  => "Selama perjalanan, penumpang bisa minta berhenti ke toilet atau istirahat sebentar (untuk perjalanan jauh).\n\nKalau dibutuhkan berhenti untuk salat, bisa dikomunikasikan ke driver. Travel tetap berangkat saat hujan — selama kondisi aman.\n\nEstimasi waktu perjalanan tergantung kondisi jalan dan jumlah titik jemput-antar. Kami usahakan perjalanan efisien tanpa berputar-putar.",
                'keywords' => ['perjalanan', 'jalan', 'istirahat', 'toilet', 'salat', 'sholat', 'hujan', 'estimasi', 'lama', 'sampai', 'macet'],
            ],

            // ─── KELUARGA & ANAK ─────────────────────────────────────
            [
                'category' => 'keluarga',
                'title'    => 'Travel dengan Anak dan Keluarga',
                'slug'     => 'travel-anak-keluarga',
                'content'  => "Bisa membawa anak kecil dan bayi. Kalau anak pakai seat sendiri, biasanya kena tarif penuh. Bayi yang dipangku biasanya berbeda — bisa dikonfirmasi ke admin.\n\nTravel JET aman untuk siapa saja — termasuk perempuan yang berangkat sendiri, lansia, dan keluarga dengan anak kecil.",
                'keywords' => ['anak', 'bayi', 'keluarga', 'lansia', 'orang tua', 'perempuan', 'sendiri', 'aman', 'tarif anak'],
            ],

            // ─── PULANG PERGI ────────────────────────────────────────
            [
                'category' => 'pp',
                'title'    => 'Booking Pulang Pergi',
                'slug'     => 'booking-pulang-pergi',
                'content'  => "Bisa booking pulang pergi (PP) sekaligus. Kirim tanggal pergi dan tanggal pulang.\n\nPulang hari yang sama juga bisa, tergantung jadwal yang tersedia. Masing-masing perjalanan dihitung terpisah sesuai tarif rute.",
                'keywords' => ['pulang', 'pergi', 'pp', 'bolak', 'balik', 'kembali', 'pulang pergi'],
            ],

            // ─── OPERASIONAL UMUM ────────────────────────────────────
            [
                'category' => 'operasional',
                'title'    => 'Informasi Operasional',
                'slug'     => 'informasi-operasional',
                'content'  => "Travel JET beroperasi setiap hari. Booking cukup lewat WhatsApp, tidak perlu datang ke kantor.\n\nKalau penumpang telat, usahakan segera hubungi admin karena travel menyesuaikan jadwal penumpang lain. Travel tetap berangkat saat hujan.\n\nKalau jadwal penuh, bisa masuk waiting list — nanti dikabari kalau ada seat kosong. Kadang ada jadwal tambahan kalau penumpang banyak.",
                'keywords' => ['operasional', 'buka', 'tutup', 'kantor', 'telat', 'terlambat', 'tunggu', 'waiting', 'penuh', 'hujan'],
            ],
        ];
    }
}