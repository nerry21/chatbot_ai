<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $metaDescription }}">
    <meta name="robots" content="index,follow">
    <link rel="canonical" href="{{ url('/terms') }}">
    <style>
        :root {
            --bg: #f5f7fb;
            --surface: #ffffff;
            --surface-soft: #eef3fb;
            --text: #1b2940;
            --muted: #66758b;
            --line: #dbe3f0;
            --accent: #1f5fbf;
            --accent-deep: #153f7d;
            --shadow: 0 20px 48px rgba(19, 43, 79, 0.08);
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
                radial-gradient(circle at top left, rgba(31, 95, 191, 0.08), transparent 26%),
                linear-gradient(180deg, #f9fbff 0%, var(--bg) 100%);
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
            background: linear-gradient(135deg, #1f5fbf 0%, #153f7d 100%);
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

        .section ul {
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
                <div class="eyebrow">Syarat dan Ketentuan Layanan</div>
                <h1>Terms of Service</h1>
                <p>
                    Halaman ini memuat syarat dan ketentuan penggunaan layanan {{ $appName }},
                    termasuk layanan chatbot, admin omnichannel, live chat, dan integrasi
                    WhatsApp untuk komunikasi antara pengguna dan admin.
                </p>
            </header>

            <main class="content-card">
                <div class="content-header">
                    <strong>{{ $appName }}</strong>
                    <p>
                        Dengan mengakses atau menggunakan layanan pada {{ $websiteUrl }}, pengguna
                        dianggap telah membaca, memahami, dan menyetujui syarat dan ketentuan yang
                        berlaku pada halaman ini.
                    </p>
                </div>

                <section class="section">
                    <h2>Nama Aplikasi / Bisnis</h2>
                    <p>
                        Layanan ini disediakan dengan identitas <strong>{{ $appName }}</strong>
                        dan dioperasikan melalui website <strong>{{ $websiteUrl }}</strong>.
                        Layanan dirancang untuk mendukung komunikasi digital, pengelolaan pesan,
                        layanan pelanggan, tindak lanjut admin, serta aktivitas operasional
                        omnichannel.
                    </p>
                </section>

                <section class="section">
                    <h2>Deskripsi Layanan</h2>
                    <p>
                        Layanan mencakup penggunaan chatbot, admin omnichannel, live chat, dan
                        integrasi WhatsApp untuk mendukung pertanyaan, permintaan bantuan, tindak
                        lanjut pelanggan, dan komunikasi layanan secara umum. Fitur tertentu dapat
                        diperbarui, disesuaikan, dibatasi, atau dihentikan sesuai kebutuhan
                        operasional dan pengembangan sistem.
                    </p>
                </section>

                <section class="section">
                    <h2>Ketentuan Penggunaan</h2>
                    <p>
                        Pengguna wajib menggunakan layanan ini secara sah, wajar, dan tidak
                        bertentangan dengan hukum, peraturan, atau kepentingan pihak lain.
                    </p>
                    <ul>
                        <li>Pengguna tidak boleh menggunakan layanan untuk spam, penipuan, penyebaran konten terlarang, atau aktivitas yang merugikan.</li>
                        <li>Pengguna tidak boleh mencoba mengganggu, menembus, atau menyalahgunakan sistem, infrastruktur, atau akun pihak lain.</li>
                        <li>Pengguna bertanggung jawab atas informasi, pesan, dan materi yang dikirimkan melalui layanan.</li>
                    </ul>
                </section>

                <section class="section">
                    <h2>Penggunaan Layanan WhatsApp</h2>
                    <p>
                        Komunikasi yang berlangsung melalui integrasi WhatsApp digunakan untuk
                        operasional layanan, dukungan admin, dan tindak lanjut komunikasi dengan
                        pengguna. Penggunaan kanal WhatsApp juga tunduk pada kebijakan, aturan,
                        dan ketentuan yang berlaku dari WhatsApp dan/atau Meta.
                    </p>
                    <div class="callout">
                        Penyedia layanan berhak membatasi atau menghentikan penggunaan kanal tertentu
                        apabila ditemukan indikasi penyalahgunaan, pelanggaran kebijakan, atau risiko
                        keamanan dan operasional.
                    </div>
                </section>

                <section class="section">
                    <h2>Kewajiban dan Tanggung Jawab Pengguna</h2>
                    <p>
                        Pengguna berkewajiban untuk memberikan informasi yang benar sepanjang
                        diperlukan, menjaga keamanan perangkat dan akun yang digunakan, serta tidak
                        membagikan akses kepada pihak yang tidak berwenang.
                    </p>
                    <p>
                        Pengguna juga bertanggung jawab untuk memastikan bahwa penggunaan layanan
                        tidak melanggar hukum, hak pihak ketiga, atau ketentuan platform komunikasi
                        yang digunakan.
                    </p>
                </section>

                <section class="section">
                    <h2>Batasan Tanggung Jawab</h2>
                    <p>
                        Kami berupaya menjaga ketersediaan, keamanan, dan kualitas layanan dengan
                        sebaik mungkin. Namun, kami tidak menjamin bahwa layanan akan selalu bebas
                        dari gangguan, penundaan, kesalahan, atau penghentian sementara.
                    </p>
                    <p>
                        Dalam batas yang diperbolehkan oleh hukum, penyedia layanan tidak
                        bertanggung jawab atas kerugian tidak langsung, gangguan operasional,
                        kehilangan data tertentu, atau dampak lain yang timbul dari penggunaan
                        layanan, kegagalan teknis, atau keterbatasan platform pihak ketiga.
                    </p>
                </section>

                <section class="section">
                    <h2>Privasi dan Data</h2>
                    <p>
                        Data pengguna diproses seperlunya untuk menjalankan layanan, memfasilitasi
                        komunikasi, mendukung operasional admin, meningkatkan mutu layanan, dan
                        menjaga keamanan sistem. Informasi lebih lanjut mengenai pengelolaan data
                        dapat merujuk pada kebijakan privasi yang berlaku pada layanan ini.
                    </p>
                    <p>
                        Dengan menggunakan layanan, pengguna memahami bahwa data komunikasi dapat
                        diproses secara terbatas untuk kebutuhan operasional yang sah.
                    </p>
                </section>

                <section class="section">
                    <h2>Perubahan Ketentuan</h2>
                    <p>
                        Syarat dan ketentuan ini dapat diperbarui sewaktu-waktu untuk menyesuaikan
                        perkembangan layanan, kebijakan internal, kebutuhan operasional, atau
                        ketentuan hukum yang berlaku. Versi terbaru berlaku sejak dipublikasikan
                        pada halaman ini.
                    </p>
                </section>

                <section class="section">
                    <h2>Kontak Admin</h2>
                    <p>
                        Jika Anda memiliki pertanyaan atau membutuhkan klarifikasi terkait syarat
                        dan ketentuan ini, Anda dapat menghubungi admin melalui informasi berikut:
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
                    <h2>Tanggal Berlaku</h2>
                    <p>
                        Syarat dan Ketentuan ini berlaku sejak <strong>{{ $effectiveDate }}</strong>.
                    </p>
                </section>
            </main>

            <p class="footer-note">
                &copy; {{ date('Y') }} {{ $appName }}. Halaman ini merupakan syarat dan ketentuan
                penggunaan layanan digital pada {{ $websiteUrl }}.
            </p>
        </div>
    </div>
</body>
</html>
