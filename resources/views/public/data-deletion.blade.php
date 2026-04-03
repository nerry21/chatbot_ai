<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ url('/data-deletion') }}">
    <style>
        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            background: #ffffff;
            color: #1f2937;
            font-family: Arial, Helvetica, sans-serif;
            line-height: 1.65;
        }

        main {
            width: 100%;
            max-width: 760px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        h1 {
            margin: 0 0 16px;
            font-size: 32px;
            line-height: 1.2;
            color: #111827;
        }

        h2 {
            margin: 0 0 12px;
            font-size: 20px;
            line-height: 1.3;
            color: #111827;
        }

        p {
            margin: 0 0 14px;
        }

        section {
            margin-top: 28px;
            padding-top: 20px;
            border-top: 1px solid #e5e7eb;
        }

        ul,
        ol {
            margin: 0;
            padding-left: 20px;
        }

        li {
            margin-bottom: 10px;
        }

        .lead {
            display: block;
            margin-bottom: 18px;
            font-size: 16px;
            color: #374151;
        }

        .small {
            color: #4b5563;
        }

        .contact {
            margin-top: 12px;
        }

        .contact p {
            margin-bottom: 8px;
        }

        footer {
            margin-top: 32px;
            font-size: 14px;
            color: #6b7280;
        }
    </style>
</head>
<body>
    <main>
        <h1>Data Deletion</h1>
        <p class="lead">
            Halaman ini memuat instruksi penghapusan data pengguna untuk layanan
            {{ $appName }}. Pengguna dapat meminta penghapusan data pribadi yang
            tersimpan dalam sistem kami melalui kontak resmi yang tersedia di bawah.
        </p>

        <section>
            <h2>Nama Aplikasi / Bisnis</h2>
            <p>{{ $appName }}</p>
            <p class="small">{{ $websiteUrl }}</p>
        </section>

        <section>
            <h2>Data yang Dapat Dihapus</h2>
            <p>
                Data yang dapat diminta untuk dihapus dapat meliputi nama, nomor
                WhatsApp, isi percakapan, metadata interaksi, dan lampiran terkait
                sepanjang tersedia di sistem kami.
            </p>
            <ul>
                <li>Nama atau identitas kontak pengguna</li>
                <li>Nomor WhatsApp terkait</li>
                <li>Isi percakapan atau riwayat komunikasi</li>
                <li>Metadata interaksi, termasuk waktu dan status pesan</li>
                <li>Lampiran terkait yang tersedia di sistem</li>
            </ul>
        </section>

        <section>
            <h2>Cara Mengajukan Permintaan</h2>
            <p>
                Permintaan penghapusan data dapat diajukan melalui email admin atau
                WhatsApp admin resmi.
            </p>
            <ol>
                <li>Hubungi admin melalui email atau WhatsApp resmi.</li>
                <li>Sampaikan bahwa Anda ingin mengajukan penghapusan data.</li>
                <li>Sertakan data verifikasi minimum agar permintaan dapat diproses.</li>
            </ol>
        </section>

        <section>
            <h2>Informasi Verifikasi</h2>
            <p>
                Untuk verifikasi, pengguna wajib menyertakan nama, nomor WhatsApp
                terkait, dan deskripsi permintaan. Admin dapat meminta informasi
                tambahan yang wajar untuk memastikan permintaan berasal dari pihak
                yang berhak.
            </p>
        </section>

        <section>
            <h2>Estimasi Proses</h2>
            <p>
                Permintaan yang valid diproses dalam waktu yang wajar, umumnya
                sekitar 7 sampai 30 hari kerja, tergantung kebutuhan verifikasi
                dan ketersediaan data pada sistem.
            </p>
        </section>

        <section>
            <h2>Pengecualian</h2>
            <p>
                Sebagian data dapat tetap disimpan apabila diwajibkan oleh hukum,
                kebutuhan audit, keamanan sistem, anti-penyalahgunaan, atau
                penyelesaian sengketa. Komunikasi melalui WhatsApp juga tunduk pada
                kebijakan WhatsApp / Meta.
            </p>
        </section>

        <section>
            <h2>Kontak Admin</h2>
            <div class="contact">
                <p>Email: {{ $adminEmail }}</p>
                <p>WhatsApp: {{ $adminWhatsApp }}</p>
            </div>
        </section>

        <section>
            <h2>Tanggal Berlaku</h2>
            <p>{{ $effectiveDate }}</p>
        </section>

        <footer>
            <p>{{ $appName }} - {{ $websiteUrl }}</p>
        </footer>
    </main>
</body>
</html>
