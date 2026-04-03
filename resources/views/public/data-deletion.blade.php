<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ url('/data-deletion') }}">
    <style>
        :root {
            --bg: #f6f8fb;
            --surface: #ffffff;
            --surface-soft: #edf4ff;
            --text: #1d2a3d;
            --muted: #66758d;
            --line: #dbe4f1;
            --accent: #2563c9;
            --accent-deep: #183f7a;
            --shadow: 0 20px 46px rgba(20, 46, 87, 0.08);
        }

        * {
            box-sizing: border-box;
        }

        html {
            scroll-behavior: smooth;
        }

        body {
            margin: 0;
            font-family: "Segoe UI", Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text);
            line-height: 1.7;
            background:
                radial-gradient(circle at top right, rgba(37, 99, 201, 0.08), transparent 28%),
                linear-gradient(180deg, #fafcff 0%, var(--bg) 100%);
        }

        .page-shell {
            min-height: 100vh;
            padding: 32px 20px 56px;
        }

        .container {
            width: 100%;
            max-width: 980px;
            margin: 0 auto;
        }

        .hero {
            margin-bottom: 24px;
            padding: 42px 34px;
            border-radius: 28px;
            background: linear-gradient(135deg, #2563c9 0%, #183f7a 100%);
            color: #ffffff;
            box-shadow: var(--shadow);
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            padding: 8px 14px;
            border-radius: 999px;
            background: rgba(255, 255, 255, 0.14);
            font-size: 13px;
            font-weight: 600;
            letter-spacing: 0.02em;
            margin-bottom: 18px;
        }

        .hero h1 {
            margin: 0 0 12px;
            font-size: clamp(32px, 5vw, 48px);
            line-height: 1.1;
        }

        .hero p {
            margin: 0;
            max-width: 760px;
            font-size: 16px;
            color: rgba(255, 255, 255, 0.92);
        }

        .content-card {
            background: var(--surface);
            border: 1px solid var(--line);
            border-radius: 28px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .content-header {
            padding: 24px 32px;
            border-bottom: 1px solid var(--line);
            background: var(--surface-soft);
        }

        .content-header strong {
            display: block;
            margin-bottom: 6px;
            font-size: 18px;
        }

        .content-header p {
            margin: 0;
            color: var(--muted);
            font-size: 15px;
        }

        .section {
            padding: 28px 32px;
            border-bottom: 1px solid var(--line);
        }

        .section:last-child {
            border-bottom: none;
        }

        .section h2 {
            margin: 0 0 12px;
            font-size: 22px;
            line-height: 1.3;
            color: var(--accent-deep);
        }

        .section p {
            margin: 0 0 12px;
        }

        .section p:last-child {
            margin-bottom: 0;
        }

        .section ul,
        .section ol {
            margin: 12px 0 0;
            padding-left: 20px;
        }

        .section li {
            margin-bottom: 10px;
        }

        .callout {
            margin-top: 14px;
            padding: 16px 18px;
            border-left: 4px solid var(--accent);
            background: #f7faff;
            border-radius: 14px;
        }

        .contact-box {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .contact-item {
            padding: 18px;
            border-radius: 18px;
            border: 1px solid var(--line);
            background: #fafcff;
        }

        .contact-label {
            display: block;
            margin-bottom: 6px;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .contact-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
            word-break: break-word;
        }

        .footer-note {
            margin-top: 20px;
            text-align: center;
            font-size: 14px;
            color: var(--muted);
        }

        a {
            color: var(--accent-deep);
        }

        @media (max-width: 720px) {
            .page-shell {
                padding: 18px 14px 32px;
            }

            .hero,
            .content-header,
            .section {
                padding: 22px 18px;
            }

            .contact-box {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="page-shell">
        <div class="container">
            <header class="hero">
                <div class="eyebrow">Instruksi Penghapusan Data Pengguna</div>
                <h1>Data Deletion</h1>
                <p>
                    Halaman ini memuat instruksi bagi pengguna yang ingin mengajukan penghapusan
                    data dari layanan {{ $appName }}, termasuk data yang diproses dalam layanan
                    chatbot, admin omnichannel, live chat, dan integrasi WhatsApp.
                </p>
            </header>

            <main class="content-card">
                <div class="content-header">
                    <strong>{{ $appName }}</strong>
                    <p>
                        Kami menghormati hak pengguna untuk meminta penghapusan data pribadi yang
                        tersimpan di sistem kami, sepanjang penghapusan tersebut dimungkinkan secara
                        operasional dan tidak bertentangan dengan kewajiban hukum, audit, atau
                        keamanan sistem.
                    </p>
                </div>

                <section class="section">
                    <h2>Nama Aplikasi / Bisnis</h2>
                    <p>
                        Layanan ini dijalankan dengan identitas <strong>{{ $appName }}</strong> dan
                        dapat diakses melalui <strong>{{ $websiteUrl }}</strong>. Layanan mendukung
                        komunikasi digital melalui chatbot, live chat, admin omnichannel, dan
                        integrasi WhatsApp untuk kebutuhan bantuan pelanggan dan tindak lanjut admin.
                    </p>
                </section>

                <section class="section">
                    <h2>Hak Pengguna</h2>
                    <p>
                        Pengguna berhak mengajukan permintaan penghapusan data pribadi yang tersedia
                        dalam sistem kami. Setiap permintaan akan ditinjau dan diverifikasi terlebih
                        dahulu untuk memastikan bahwa permintaan tersebut diajukan oleh pihak yang
                        berhak dan sesuai dengan kebijakan yang berlaku.
                    </p>
                </section>

                <section class="section">
                    <h2>Data yang Dapat Dihapus</h2>
                    <p>
                        Sepanjang tersedia di sistem, data yang dapat diminta untuk dihapus dapat
                        meliputi:
                    </p>
                    <ul>
                        <li>nama atau identitas kontak pengguna;</li>
                        <li>nomor WhatsApp yang terkait dengan interaksi layanan;</li>
                        <li>isi percakapan atau riwayat komunikasi;</li>
                        <li>metadata interaksi, seperti waktu komunikasi dan status pesan;</li>
                        <li>lampiran yang terkait dengan percakapan, jika tersimpan dalam sistem.</li>
                    </ul>
                </section>

                <section class="section">
                    <h2>Cara Mengajukan Permintaan Penghapusan Data</h2>
                    <p>
                        Pengguna dapat mengajukan permintaan penghapusan data dengan menghubungi
                        admin resmi melalui email atau WhatsApp yang tercantum pada halaman ini.
                    </p>
                    <ol>
                        <li>Kirim permintaan penghapusan data ke email atau WhatsApp admin resmi.</li>
                        <li>Jelaskan bahwa Anda ingin menghapus data yang tersimpan dalam sistem.</li>
                        <li>Sertakan informasi verifikasi minimum agar permintaan dapat diproses.</li>
                    </ol>
                </section>

                <section class="section">
                    <h2>Informasi yang Diperlukan untuk Verifikasi</h2>
                    <p>
                        Untuk membantu proses verifikasi, pengguna disarankan menyertakan informasi
                        berikut dalam permintaan:
                    </p>
                    <ul>
                        <li>nama yang digunakan saat berinteraksi dengan layanan;</li>
                        <li>nomor WhatsApp terkait;</li>
                        <li>deskripsi singkat mengenai data yang ingin dihapus;</li>
                        <li>informasi tambahan lain yang secara wajar diperlukan untuk verifikasi.</li>
                    </ul>
                    <div class="callout">
                        Admin dapat meminta verifikasi tambahan untuk memastikan bahwa permintaan
                        benar-benar berasal dari pihak yang berhak atas data tersebut.
                    </div>
                </section>

                <section class="section">
                    <h2>Proses dan Estimasi Waktu</h2>
                    <p>
                        Permintaan yang valid akan diproses dalam waktu yang wajar setelah verifikasi
                        selesai dilakukan. Estimasi penanganan berkisar antara <strong>7 hingga 30
                        hari kerja</strong>, tergantung kompleksitas permintaan, ketersediaan data,
                        dan kebutuhan verifikasi tambahan.
                    </p>
                </section>

                <section class="section">
                    <h2>Pengecualian Penyimpanan Data</h2>
                    <p>
                        Dalam kondisi tertentu, sebagian data dapat tetap disimpan apabila diperlukan
                        untuk memenuhi kewajiban hukum, audit, keamanan sistem, pencegahan
                        penyalahgunaan, penyelesaian sengketa, atau kepentingan operasional lain yang
                        sah.
                    </p>
                    <p>
                        Komunikasi melalui WhatsApp juga tunduk pada kebijakan dan mekanisme retensi
                        data yang berlaku pada platform WhatsApp dan/atau Meta.
                    </p>
                </section>

                <section class="section">
                    <h2>Kontak Admin</h2>
                    <p>
                        Untuk mengajukan permintaan penghapusan data atau meminta klarifikasi lebih
                        lanjut, silakan hubungi admin melalui:
                    </p>
                    <div class="contact-box">
                        <div class="contact-item">
                            <span class="contact-label">Email Admin</span>
                            <span class="contact-value">{{ $adminEmail }}</span>
                        </div>
                        <div class="contact-item">
                            <span class="contact-label">WhatsApp Admin</span>
                            <span class="contact-value">{{ $adminWhatsApp }}</span>
                        </div>
                    </div>
                </section>

                <section class="section">
                    <h2>Pernyataan Privasi</h2>
                    <p>
                        Kami berupaya memproses dan menghapus data pengguna secara bertanggung jawab
                        sesuai kebutuhan operasional, kebijakan internal, dan kewajiban hukum yang
                        berlaku. Setiap permintaan penghapusan akan ditangani secara wajar, sopan,
                        dan profesional.
                    </p>
                </section>

                <section class="section">
                    <h2>Tanggal Berlaku</h2>
                    <p>
                        Instruksi penghapusan data ini berlaku sejak
                        <strong>{{ $effectiveDate }}</strong>.
                    </p>
                </section>
            </main>

            <p class="footer-note">
                &copy; {{ date('Y') }} {{ $appName }}. Halaman ini disediakan untuk kebutuhan
                instruksi penghapusan data pengguna pada {{ $websiteUrl }}.
            </p>
        </div>
    </div>
</body>
</html>
