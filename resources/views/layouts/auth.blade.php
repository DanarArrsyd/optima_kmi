<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPTIMA &mdash; @yield('title', 'Login')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        * { font-family: Arial, Helvetica, sans-serif !important; }

        html, body {
            height: 100%;
            margin: 0;
            background: #0f1117;
        }

        .login-wrapper {
            display: flex;
            min-height: 100vh;
        }

        /* ── LEFT PANEL ─────────────────────────────────────────── */
        .login-left {
            flex: 1;
            background: #111827;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 40px;
            position: relative;
            overflow: hidden;
        }

        /* Subtle grid pattern */
        .login-left::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(rgba(255,255,255,0.03) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255,255,255,0.03) 1px, transparent 1px);
            background-size: 40px 40px;
            pointer-events: none;
        }

        /* Top accent line */
        .login-left::after {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 2px;
            background: linear-gradient(90deg, transparent, #4b5563, #9ca3af, #4b5563, transparent);
        }

        .brand-logo {
            width: 120px;
            height: 120px;
            object-fit: contain;
            margin-bottom: 28px;
            position: relative;
            z-index: 1;
            filter: drop-shadow(0 0 24px rgba(255,255,255,0.08));
        }

        .brand-name {
            font-size: 28px;
            font-weight: 800;
            color: #f3f4f6;
            letter-spacing: 3px;
            text-transform: uppercase;
            position: relative;
            z-index: 1;
            margin-bottom: 8px;
        }

        .brand-tag {
            display: inline-block;
            font-size: 9px;
            font-weight: 700;
            color: #6b7280;
            letter-spacing: 2px;
            text-transform: uppercase;
            border: 1px solid #374151;
            padding: 3px 10px;
            margin-bottom: 40px;
            position: relative;
            z-index: 1;
        }

        .brand-desc {
            font-size: 11px;
            color: #4b5563;
            text-align: center;
            line-height: 1.8;
            letter-spacing: 0.3px;
            max-width: 280px;
            position: relative;
            z-index: 1;
        }

        .brand-divider {
            width: 40px;
            height: 1px;
            background: #374151;
            margin: 28px auto;
            position: relative;
            z-index: 1;
        }

        .brand-stats {
            display: flex;
            gap: 32px;
            position: relative;
            z-index: 1;
        }
        .stat-item {
            text-align: center;
        }
        .stat-item .stat-num {
            font-size: 18px;
            font-weight: 700;
            color: #9ca3af;
            letter-spacing: 1px;
        }
        .stat-item .stat-lbl {
            font-size: 9px;
            color: #4b5563;
            text-transform: uppercase;
            letter-spacing: 1px;
            margin-top: 2px;
        }

        .corner-mark {
            position: absolute;
            bottom: 24px;
            left: 24px;
            font-size: 9px;
            color: #374151;
            letter-spacing: 1px;
            text-transform: uppercase;
        }

        /* ── RIGHT PANEL ────────────────────────────────────────── */
        .login-right {
            width: 440px;
            min-width: 440px;
            background: #f3f4f6;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 48px 40px;
            border-left: 1px solid #1f2937;
        }

        .login-card {
            width: 100%;
            max-width: 360px;
        }

        .login-title {
            font-size: 16px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 4px;
            letter-spacing: 0.3px;
        }
        .login-subtitle {
            font-size: 11.5px;
            color: #6b7280;
            margin-bottom: 28px;
        }

        .form-label {
            font-size: 10.5px;
            font-weight: 700;
            color: #374151;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 5px;
            display: block;
        }

        .form-control {
            border-radius: 2px;
            border: 1px solid #d1d5db;
            font-size: 13px;
            padding: 8px 11px;
            color: #111827;
            background: #ffffff;
            width: 100%;
            transition: border-color 0.15s;
        }
        .form-control:focus {
            border-color: #374151;
            outline: none;
            box-shadow: none;
        }
        .form-control.is-invalid { border-color: #b91c1c; }
        .invalid-feedback {
            font-size: 11px;
            color: #b91c1c;
            margin-top: 4px;
            display: block;
        }

        .input-group-icon {
            position: relative;
            margin-bottom: 16px;
        }
        .input-group-icon i {
            position: absolute;
            left: 10px;
            top: 50%;
            transform: translateY(-50%);
            color: #9ca3af;
            font-size: 14px;
            pointer-events: none;
        }
        .input-group-icon .form-control {
            padding-left: 34px;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
        }
        .form-check-input {
            width: 14px;
            height: 14px;
            border-radius: 2px;
            border: 1px solid #d1d5db;
            cursor: pointer;
            accent-color: #374151;
        }
        .form-check-label {
            font-size: 12px;
            color: #6b7280;
            cursor: pointer;
        }

        .btn-login {
            width: 100%;
            background: #111827;
            border: none;
            color: #fff;
            font-size: 12px;
            font-weight: 700;
            letter-spacing: 0.8px;
            text-transform: uppercase;
            padding: 11px;
            border-radius: 2px;
            cursor: pointer;
            transition: background 0.15s;
        }
        .btn-login:hover { background: #1f2937; }
        .btn-login:active { background: #374151; }

        .login-footer {
            margin-top: 36px;
            font-size: 10px;
            color: #9ca3af;
            text-align: center;
            letter-spacing: 0.3px;
        }

        /* ── Responsive ─────────────────────────────────────────── */
        @media (max-width: 768px) {
            .login-left { display: none; }
            .login-right {
                width: 100%;
                min-width: 0;
                border-left: none;
                background: #111827;
                padding: 32px 24px;
            }
            .login-title { color: #f3f4f6; }
            .login-subtitle { color: #6b7280; }
            .form-label { color: #9ca3af; }
            .form-control { background: #1f2937; border-color: #374151; color: #f3f4f6; }
            .form-check-label { color: #6b7280; }
        }
    </style>
</head>
<body>

<div class="login-wrapper">

    {{-- ── LEFT PANEL ── --}}
    <div class="login-left">

        <img src="{{ asset('logo.png') }}" alt="Logo" class="brand-logo">

        <div class="brand-name">OPTIMA</div>
        <div class="brand-tag">MES</div>

        <div class="brand-desc">
            Operator & Production Tracking<br>
            Information Management System
        </div>

        <div class="brand-divider"></div>

        <div class="brand-stats">
            <div class="stat-item">
                <div class="stat-num">VCB</div>
                <div class="stat-lbl">Dashboard</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">LHP</div>
                <div class="stat-lbl">Produksi</div>
            </div>
            <div class="stat-item">
                <div class="stat-num">QC</div>
                <div class="stat-lbl">Quality</div>
            </div>
        </div>

        <div class="corner-mark">v1.0 &nbsp;&bull;&nbsp; OPTIMA MES</div>
    </div>

    {{-- ── RIGHT PANEL ── --}}
    <div class="login-right">
        <div class="login-card">

            <div class="login-title">Selamat Datang</div>
            <div class="login-subtitle">Masuk ke sistem OPTIMA MES</div>

            @yield('content')

            <div class="login-footer">
                &copy; {{ date('Y') }} OPTIMA &mdash; Manufactured Excellence System
            </div>
        </div>
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
</body>
</html>
