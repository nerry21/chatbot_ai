<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ url('/privacy-policy') }}">
    <style>
        :root {
            --bg: #f4f7f6;
            --surface: #ffffff;
            --surface-alt: #eef5f2;
            --text: #17312b;
            --muted: #5d746d;
            --line: #d6e2dd;
            --accent: #0f7a5c;
            --accent-dark: #0b5c45;
            --shadow: 0 18px 50px rgba(16, 42, 35, 0.08);
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
            background:
                radial-gradient(circle at top right, rgba(15, 122, 92, 0.08), transparent 28%),
                linear-gradient(180deg, #f8fbfa 0%, var(--bg) 100%);
            color: var(--text);
            line-height: 1.7;
        }

        .page-shell {
            min-height: 100vh;
            padding: 32px 20px 56px;
        }

        .container {
            width: 100%;
            max-width: 960px;
            margin: 0 auto;
        }

        .hero {
            background: linear-gradient(135deg, #0f7a5c 0%, #134c3d 100%);
            color: #ffffff;
            border-radius: 28px;
            padding: 40px 32px;
            box-shadow: var(--shadow);
            margin-bottom: 24px;
        }

        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: 8px;
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
            max-width: 720px;
            font-size: 16px;
            color: rgba(255, 255, 255, 0.9);
        }

        .content-card {
            background: var(--surface);
            border: 1px solid rgba(214, 226, 221, 0.9);
            border-radius: 28px;
            box-shadow: var(--shadow);
            overflow: hidden;
        }

        .content-header {
            padding: 24px 32px;
            background: var(--surface-alt);
            border-bottom: 1px solid var(--line);
        }

        .content-header p {
            margin: 6px 0 0;
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
            color: var(--accent-dark);
        }

        .section p {
            margin: 0 0 12px;
        }

        .section p:last-child {
            margin-bottom: 0;
        }

        .section ul {
            margin: 12px 0 0;
            padding-left: 20px;
        }

        .section li {
            margin-bottom: 10px;
        }

        .highlight {
            margin-top: 14px;
            padding: 16px 18px;
            border-left: 4px solid var(--accent);
            background: #f7fbf9;
            border-radius: 14px;
            color: var(--text);
        }

        .contact-box {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 16px;
            margin-top: 16px;
        }

        .contact-item {
            padding: 18px;
            background: #f9fcfb;
            border: 1px solid var(--line);
            border-radius: 18px;
        }

        .contact-label {
            display: block;
            font-size: 13px;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 6px;
        }

        .contact-value {
            font-size: 16px;
            font-weight: 600;
            color: var(--text);
            word-break: break-word;
        }

        .footer-note {
            margin-top: 20px;
            color: var(--muted);
            font-size: 14px;
            text-align: center;
        }

        a {
            color: var(--accent-dark);
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
                <div class="eyebrow">Kebijakan Privasi Layanan</div>
                <h1>Privacy Policy</h1>
                <p>
                    Halaman ini menjelaskan kebijakan privasi penggunaan layanan {{ $appName }}
                    pada domain {{ $domain }}, termasuk penggunaan data untuk chatbot, layanan
                    customer service, integrasi omnichannel, dan komunikasi melalui WhatsApp.
                </p>
            </header>

            <main class="content-card">
                <div class="content-header">
                    <strong>{{ $appName }}</strong>
                    <p>
                        Kami berkomitmen untuk memproses data pengguna secara wajar, proporsional,
                        dan seperlunya demi menjalankan layanan serta mendukung operasional admin.
                    </p>
                </div>

                <section class="section">
                    <h2>Nama Aplikasi / Bisnis</h2>
                    <p>
                        Layanan ini dijalankan dengan identitas <strong>{{ $appName }}</strong>
                        dan diakses melalui domain <strong>{{ $domain }}</strong>. Layanan ini
                        digunakan untuk mendukung komunikasi pelanggan, chatbot, customer service,
                        serta pengelolaan percakapan omnichannel oleh admin yang berwenang.
                    </p>
                </section>

                <section class="section">
                    <h2>Data yang Dikumpulkan</h2>
                    <p>
                        Dalam penggunaan layanan ini, sistem dapat mengumpulkan dan memproses data
                        yang relevan untuk menjalankan layanan secara normal, termasuk namun tidak
                        terbatas pada:
                    </p>
                    <ul>
                        <li>nama pengguna atau nama kontak yang tersedia pada percakapan;</li>
                        <li>nomor WhatsApp atau informasi kontak lain yang dikirim pengguna;</li>
                        <li>isi pesan, riwayat percakapan, serta metadata komunikasi;</li>
                        <li>lampiran seperti gambar, audio, dokumen, atau media lain jika dikirimkan;</li>
                        <li>waktu interaksi, status pengiriman, dan data teknis operasional sistem.</li>
                    </ul>
                </section>

                <section class="section">
                    <h2>Penggunaan Data WhatsApp</h2>
                    <p>
                        Data yang berasal dari komunikasi WhatsApp digunakan untuk menjalankan
                        fungsi layanan, termasuk chatbot otomatis, tindak lanjut customer service,
                        eskalasi ke admin, pencatatan histori percakapan, pemantauan kualitas
                        layanan, dan peningkatan performa operasional.
                    </p>
                    <p>
                        Pengguna memahami bahwa komunikasi melalui WhatsApp juga tunduk pada
                        kebijakan dan ketentuan yang berlaku pada platform WhatsApp dan/atau Meta.
                        Sistem kami hanya memproses data yang diperlukan untuk kepentingan layanan
                        dan operasional yang sah.
                    </p>
                    <div class="highlight">
                        Data percakapan tidak dijual kepada pihak lain. Pemrosesan data dilakukan
                        seperlunya untuk penyediaan layanan, dukungan admin, keamanan sistem, dan
                        evaluasi mutu layanan.
                    </div>
                </section>

                <section class="section">
                    <h2>Penyimpanan dan Keamanan Data</h2>
                    <p>
                        Kami berupaya menjaga data pengguna dengan langkah teknis dan administratif
                        yang wajar, termasuk pembatasan akses, pencatatan aktivitas sistem, dan
                        pengelolaan infrastruktur yang diperlukan untuk operasional layanan.
                    </p>
                    <p>
                        Walaupun demikian, tidak ada metode transmisi data atau penyimpanan digital
                        yang dapat dijamin sepenuhnya bebas risiko. Karena itu, pengguna juga
                        disarankan menjaga keamanan perangkat, akun, serta akses terhadap aplikasi
                        yang digunakan untuk berkomunikasi.
                    </p>
                </section>

                <section class="section">
                    <h2>Kontak Admin</h2>
                    <p>
                        Jika Anda memiliki pertanyaan, permintaan klarifikasi, atau membutuhkan
                        bantuan terkait kebijakan privasi ini, Anda dapat menghubungi admin melalui
                        informasi berikut:
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
                        Dengan menggunakan layanan {{ $appName }}, pengguna dianggap telah membaca
                        dan memahami bahwa data komunikasi dapat diproses secara terbatas untuk
                        kebutuhan operasional sistem, pelayanan pelanggan, tindak lanjut admin,
                        pemeliharaan keamanan, dan peningkatan kualitas layanan.
                    </p>
                    <p>
                        Kami berhak memperbarui kebijakan privasi ini dari waktu ke waktu sesuai
                        kebutuhan operasional, perkembangan sistem, atau ketentuan hukum yang
                        berlaku. Perubahan akan berlaku sejak tanggal pembaruan ditetapkan pada
                        halaman ini.
                    </p>
                </section>

                <section class="section">
                    <h2>Tanggal Berlaku</h2>
                    <p>
                        Kebijakan Privasi ini berlaku sejak <strong>{{ $effectiveDate }}</strong>.
                    </p>
                </section>
            </main>

            <p class="footer-note">
                &copy; {{ date('Y') }} {{ $appName }}. Seluruh informasi pada halaman ini disediakan
                untuk menjelaskan pengelolaan privasi layanan di {{ $domain }}.
            </p>
        </div>
    </div>
</body>
</html>
