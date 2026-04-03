<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Data Deletion Request Status | Chatbot AI</title>
    <meta name="description" content="Status permintaan penghapusan data pengguna untuk layanan Chatbot AI / Admin Jet.">
    <link rel="canonical" href="{{ url('/data-deletion-status/' . $confirmationCode) }}">
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
            font-size: 30px;
            line-height: 1.2;
            color: #111827;
        }

        p {
            margin: 0 0 14px;
        }

        .code-box {
            margin: 24px 0;
            padding: 16px 18px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
        }

        .label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }

        .code {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            word-break: break-all;
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
        <h1>Data Deletion Request Status</h1>
        <p>
            Permintaan penghapusan data telah diterima dan sedang diproses.
        </p>
        <p>
            Simpan kode konfirmasi berikut untuk referensi jika diperlukan dalam komunikasi lanjutan.
        </p>

        <div class="code-box">
            <span class="label">Confirmation Code</span>
            <div class="code">{{ $confirmationCode }}</div>
        </div>

        <p>
            Untuk pertanyaan lebih lanjut, silakan hubungi admin resmi melalui
            <strong>admin@spesial.online</strong> atau WhatsApp
            <strong>+62xxxxxxxxxxx</strong>.
        </p>

        <footer>
            <p>Chatbot AI / Admin Jet - https://spesial.online</p>
        </footer>
    </main>
</body>
</html>
