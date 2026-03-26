<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login — {{ config('app.name', 'ChatBot AI') }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --primary: #6c63ff;
            --primary-dark: #4f46e5;
            --primary-glow: rgba(108, 99, 255, 0.4);
            --accent: #a78bfa;
            --bg-dark: #0a0a1a;
            --bg-card: rgba(255,255,255,0.04);
            --border: rgba(255,255,255,0.08);
            --text: #f1f5f9;
            --text-muted: #94a3b8;
            --error: #f87171;
        }

        html, body {
            min-height: 100vh;
            font-family: 'Inter', sans-serif;
            background: var(--bg-dark);
            color: var(--text);
            overflow-x: hidden;
        }

        /* ── Animated background ── */
        .bg-scene {
            position: fixed;
            inset: 0;
            z-index: 0;
            background: radial-gradient(ellipse 80% 60% at 20% 10%, rgba(108,99,255,0.18) 0%, transparent 60%),
                        radial-gradient(ellipse 60% 50% at 80% 90%, rgba(167,139,250,0.15) 0%, transparent 60%),
                        radial-gradient(ellipse 100% 80% at 50% 50%, rgba(15,10,40,1) 0%, #0a0a1a 100%);
        }

        .grid-lines {
            position: fixed;
            inset: 0;
            z-index: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.025) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.025) 1px, transparent 1px);
            background-size: 60px 60px;
            mask-image: radial-gradient(ellipse 80% 80% at 50% 50%, black 30%, transparent 100%);
        }

        /* Floating orbs */
        .orb {
            position: fixed;
            border-radius: 50%;
            filter: blur(80px);
            animation: drift 12s ease-in-out infinite alternate;
            z-index: 0;
            pointer-events: none;
        }
        .orb-1 { width: 400px; height: 400px; top: -100px; left: -80px; background: rgba(108,99,255,0.12); animation-delay: 0s; }
        .orb-2 { width: 300px; height: 300px; bottom: -80px; right: -60px; background: rgba(167,139,250,0.10); animation-delay: -4s; }
        .orb-3 { width: 200px; height: 200px; top: 40%; left: 55%; background: rgba(99,102,241,0.08); animation-delay: -8s; }

        @keyframes drift {
            from { transform: translate(0, 0) scale(1); }
            to   { transform: translate(30px, 20px) scale(1.05); }
        }

        /* ── Layout ── */
        .page {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
        }

        /* ── Card ── */
        .card {
            width: 100%;
            max-width: 420px;
            background: var(--bg-card);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 48px 40px;
            backdrop-filter: blur(24px);
            -webkit-backdrop-filter: blur(24px);
            box-shadow:
                0 0 0 1px rgba(255,255,255,0.05) inset,
                0 32px 64px rgba(0,0,0,0.5),
                0 0 80px var(--primary-glow);
            animation: fadeUp 0.6s cubic-bezier(0.22, 1, 0.36, 1) both;
        }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(32px) scale(0.97); }
            to   { opacity: 1; transform: translateY(0) scale(1); }
        }

        /* ── Logo / Brand ── */
        .brand {
            text-align: center;
            margin-bottom: 36px;
        }

        .brand-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 64px;
            height: 64px;
            border-radius: 18px;
            background: linear-gradient(135deg, var(--primary), var(--accent));
            box-shadow: 0 8px 32px var(--primary-glow);
            margin-bottom: 20px;
            animation: pulse-icon 3s ease-in-out infinite;
        }

        @keyframes pulse-icon {
            0%, 100% { box-shadow: 0 8px 32px var(--primary-glow); }
            50%       { box-shadow: 0 8px 48px rgba(108,99,255,0.6); }
        }

        .brand-icon svg {
            width: 30px;
            height: 30px;
            fill: none;
            stroke: #fff;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .brand h1 {
            font-size: 22px;
            font-weight: 700;
            letter-spacing: -0.3px;
            color: var(--text);
        }

        .brand p {
            font-size: 13px;
            color: var(--text-muted);
            margin-top: 6px;
            font-weight: 400;
        }

        /* ── Status / Alert ── */
        .alert {
            background: rgba(248, 113, 113, 0.1);
            border: 1px solid rgba(248, 113, 113, 0.25);
            border-radius: 12px;
            padding: 12px 16px;
            font-size: 13px;
            color: var(--error);
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .alert-success {
            background: rgba(52, 211, 153, 0.1);
            border-color: rgba(52, 211, 153, 0.25);
            color: #34d399;
        }

        /* ── Form ── */
        .form-group {
            margin-bottom: 20px;
        }

        label {
            display: block;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-muted);
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .input-wrap {
            position: relative;
        }

        .input-icon {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            transition: color 0.2s;
        }

        .input-icon svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        input[type="email"],
        input[type="password"],
        input[type="text"] {
            width: 100%;
            padding: 14px 16px 14px 46px;
            background: rgba(255,255,255,0.05);
            border: 1px solid var(--border);
            border-radius: 12px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            color: var(--text);
            outline: none;
            transition: border-color 0.2s, background 0.2s, box-shadow 0.2s;
            -webkit-appearance: none;
        }

        input::placeholder { color: rgba(148,163,184,0.5); }

        input:focus {
            border-color: var(--primary);
            background: rgba(108,99,255,0.06);
            box-shadow: 0 0 0 3px rgba(108,99,255,0.15);
        }

        input:focus + .focus-ring { opacity: 1; }

        .toggle-password {
            position: absolute;
            right: 14px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: var(--text-muted);
            padding: 4px;
            display: flex;
            align-items: center;
            transition: color 0.2s;
        }

        .toggle-password:hover { color: var(--text); }

        .toggle-password svg {
            width: 16px;
            height: 16px;
            stroke: currentColor;
            fill: none;
            stroke-width: 2;
            stroke-linecap: round;
            stroke-linejoin: round;
        }

        .field-error {
            font-size: 12px;
            color: var(--error);
            margin-top: 6px;
            display: flex;
            align-items: center;
            gap: 4px;
        }

        .pw-hint {
            font-size: 11px;
            color: var(--text-muted);
            margin-top: 6px;
        }

        /* ── Submit Button ── */
        .btn-submit {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, var(--primary) 0%, var(--primary-dark) 100%);
            color: #fff;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Inter', sans-serif;
            border: none;
            border-radius: 12px;
            cursor: pointer;
            margin-top: 8px;
            position: relative;
            overflow: hidden;
            transition: transform 0.15s, box-shadow 0.2s;
            box-shadow: 0 4px 24px rgba(108,99,255,0.35);
            letter-spacing: 0.02em;
        }

        .btn-submit::before {
            content: '';
            position: absolute;
            inset: 0;
            background: linear-gradient(135deg, rgba(255,255,255,0.12) 0%, transparent 60%);
        }

        .btn-submit:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 32px rgba(108,99,255,0.5);
        }

        .btn-submit:active { transform: translateY(0); }

        .btn-submit .btn-text { position: relative; z-index: 1; }

        .btn-submit .spinner {
            display: none;
            position: absolute;
            inset: 0;
            align-items: center;
            justify-content: center;
            z-index: 2;
        }

        .btn-submit.loading .btn-text { opacity: 0; }
        .btn-submit.loading .spinner { display: flex; }

        @keyframes spin { to { transform: rotate(360deg); } }

        .spin-circle {
            width: 20px;
            height: 20px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.7s linear infinite;
        }

        /* ── Divider ── */
        .divider {
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 28px 0 0;
        }

        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: var(--border);
        }

        .divider span {
            font-size: 11px;
            color: var(--text-muted);
            white-space: nowrap;
        }

        /* ── Footer ── */
        .card-footer {
            margin-top: 28px;
            text-align: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .card-footer strong {
            display: block;
            font-size: 11px;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: rgba(148,163,184,0.5);
            margin-top: 4px;
        }

        /* Scrollbar */
        ::-webkit-scrollbar { width: 6px; }
        ::-webkit-scrollbar-track { background: transparent; }
        ::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.1); border-radius: 3px; }
    </style>
</head>
<body>

    <!-- Background -->
    <div class="bg-scene"></div>
    <div class="grid-lines"></div>
    <div class="orb orb-1"></div>
    <div class="orb orb-2"></div>
    <div class="orb orb-3"></div>

    <!-- Page -->
    <div class="page">
        <div class="card">

            <!-- Brand -->
            <div class="brand">
                <div class="brand-icon">
                    <!-- Bot / chat icon -->
                    <svg viewBox="0 0 24 24">
                        <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/>
                        <circle cx="9" cy="10" r="1" fill="#fff" stroke="none"/>
                        <circle cx="12" cy="10" r="1" fill="#fff" stroke="none"/>
                        <circle cx="15" cy="10" r="1" fill="#fff" stroke="none"/>
                    </svg>
                </div>
                <h1>{{ config('app.name', 'ChatBot AI') }}</h1>
                <p>Masuk ke panel administrasi</p>
            </div>

            <!-- Session Status -->
            @if (session('status'))
                <div class="alert alert-success">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                    {{ session('status') }}
                </div>
            @endif

            <!-- Auth Errors -->
            @if ($errors->any())
                <div class="alert">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    {{ $errors->first() }}
                </div>
            @endif

            <!-- Login Form -->
            <form method="POST" action="{{ route('login') }}" id="loginForm">
                @csrf

                <!-- Email -->
                <div class="form-group">
                    <label for="email">Alamat Email</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
                        </span>
                        <input
                            id="email"
                            type="email"
                            name="email"
                            value="{{ old('email') }}"
                            placeholder="admin@example.com"
                            required
                            autofocus
                            autocomplete="username"
                        >
                    </div>
                    @error('email')
                        <div class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <!-- Password -->
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="input-wrap">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
                        </span>
                        <input
                            id="password"
                            type="password"
                            name="password"
                            placeholder="Masukkan password"
                            required
                            autocomplete="current-password"
                            maxlength="8"
                        >
                        <button type="button" class="toggle-password" onclick="togglePw()" aria-label="Tampilkan password">
                            <svg id="eyeIcon" viewBox="0 0 24 24"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </button>
                    </div>
                    <div class="pw-hint">Maksimal 8 karakter</div>
                    @error('password')
                        <div class="field-error">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                            {{ $message }}
                        </div>
                    @enderror
                </div>

                <!-- Submit -->
                <button type="submit" class="btn-submit" id="submitBtn">
                    <span class="btn-text">Masuk Sekarang</span>
                    <span class="spinner"><span class="spin-circle"></span></span>
                </button>
            </form>

            <!-- Divider -->
            <div class="divider"><span>sistem terlindungi</span></div>

            <!-- Footer -->
            <div class="card-footer">
                Hanya untuk pengguna yang berwenang
                <strong>{{ config('app.name', 'ChatBot AI') }} &copy; {{ date('Y') }}</strong>
            </div>

        </div>
    </div>

    <script>
        // Toggle password visibility
        function togglePw() {
            const input = document.getElementById('password');
            const icon  = document.getElementById('eyeIcon');
            const show  = input.type === 'password';
            input.type  = show ? 'text' : 'password';
            icon.innerHTML = show
                ? '<path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>'
                : '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>';
        }

        // Loading state on submit
        document.getElementById('loginForm').addEventListener('submit', function () {
            document.getElementById('submitBtn').classList.add('loading');
        });
    </script>

</body>
</html>
