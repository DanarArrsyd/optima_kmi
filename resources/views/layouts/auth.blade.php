<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPTIMA &mdash; @yield('title', 'Login')</title>
    <link rel="icon" type="image/png" href="{{ asset('logo.png') }}">
    <link rel="manifest" href="{{ asset('manifest.json') }}">
    <meta name="theme-color" content="#111827">
    <link rel="apple-touch-icon" href="{{ asset('icons/icon-192x192.png') }}">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="OPTIMA">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet" integrity="sha384-tViUnnbYAV00FLIhhi3v/dWt3Jxw4gZQcNoSCxCIFNJVCx7/D55/wXsrNIRANwdD" crossorigin="anonymous">
    <style>
        *, *::before, *::after { box-sizing: border-box; }
        * { font-family: Arial, Helvetica, sans-serif !important; }

        html, body {
            height: 100%;
            margin: 0;
            background: #fff;
            color: #111827;
        }

        /* Latar: gradien biru muda menyapu dari kanan bawah, seperti langit. */
        .auth-bg {
            position: fixed;
            inset: 0;
            background:
                radial-gradient(120% 90% at 100% 60%, #dbeafe 0%, #eff6ff 38%, #ffffff 70%),
                linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            z-index: 0;
        }

        .auth-page {
            position: relative;
            z-index: 1;
            min-height: 100%;
            display: flex;
            flex-direction: column;
            padding: 22px clamp(16px, 4vw, 56px) 0;
        }

        /* ── Kepala: lockup perusahaan, rata kanan ── */
        .auth-kepala {
            display: flex;
            justify-content: flex-end;
        }
        .auth-lockup {
            /* Berkasnya RGB tanpa alpha — latarnya putih solid. multiply
               melebur putih itu ke latar gradien, jadi tidak ada kotak putih
               menggantung. Kalau suatu saat asetnya diganti PNG transparan,
               baris ini boleh dicabut. */
            mix-blend-mode: multiply;
            height: clamp(38px, 5vw, 62px);
            width: auto;
        }

        /* ── Badan: panel kiri + maskot kanan ── */
        .auth-badan {
            flex: 1;
            display: grid;
            grid-template-columns: minmax(0, 520px) minmax(0, 1fr);
            align-items: center;
            gap: clamp(16px, 4vw, 48px);
        }

        .auth-panel {
            background: #eceef1;
            border-radius: 26px;
            padding: clamp(24px, 3vw, 44px) clamp(22px, 3vw, 46px) clamp(30px, 4vw, 52px);
            position: relative;
            overflow: hidden;
        }

        .login-title {
            font-size: clamp(26px, 3.4vw, 44px);
            font-weight: 700;
            letter-spacing: -0.5px;
            margin: 0;
        }
        .auth-garis {
            height: 3px;
            background: #1f3a63;
            margin: 14px 0 12px;
        }
        .login-subtitle {
            text-align: center;
            font-size: 15px;
            color: #374151;
            margin-bottom: 26px;
        }

        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 700;
            margin-bottom: 7px;
            color: #111827;
        }

        /* Kotak input berikon — dipakai juga oleh halaman lupa/atur-ulang
           password, jadi nama kelasnya jangan diubah. */
        .input-group-icon { position: relative; }
        .input-group-icon > i {
            position: absolute;
            left: 16px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 20px;
            color: #6b7280;
            pointer-events: none;
        }
        .form-control {
            width: 100%;
            height: 54px;
            border: 1px solid #9ca3af;
            border-radius: 12px;
            background: #fff;
            padding: 0 16px 0 48px;
            font-size: 16px;
            color: #111827;
        }
        .form-control::placeholder { color: #9ca3af; font-style: italic; }
        .form-control:focus {
            outline: none;
            border-color: #1f3a63;
            box-shadow: 0 0 0 3px rgba(31, 58, 99, .12);
        }
        .form-control.is-invalid { border-color: #b91c1c; }
        .invalid-feedback {
            display: block;
            font-size: 12px;
            color: #b91c1c;
            margin-top: 6px;
        }

        .lihat-sandi {
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #4b5563;
            font-size: 20px;
            line-height: 1;
            padding: 6px 8px;
            cursor: pointer;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 8px;
            margin: 14px 0 0;
            font-size: 13px;
            color: #374151;
        }
        .form-check-input { width: 15px; height: 15px; margin: 0; }

        /* ── Tombol + awan ── */
        .gas-wrap {
            position: relative;
            margin-top: 26px;
            padding-bottom: 6px;
            display: flex;
            justify-content: center;
        }
        .btn-login {
            border: 0;
            border-radius: 8px;
            background: #374151;
            color: #fff;
            font-size: 17px;
            font-weight: 700;
            letter-spacing: .3px;
            padding: 16px 42px;
            cursor: pointer;
        }
        .btn-login:hover { background: #1f2937; }
        .btn-login:focus-visible { outline: 3px solid #1f3a63; outline-offset: 3px; }

        /* Awan HANYA lapisan tampilan. pointer-events:none supaya ia tidak
           pernah bisa menelan klik, dan tombolnya tetap bisa ditekan lewat
           Tab + Enter meski sedang tertutup. Halaman login adalah satu-satunya
           halaman yang kalau salah bikin SEMUA orang tidak bisa masuk. */
        .awan {
            position: absolute;
            left: 50%;
            bottom: -34px;
            width: 420px;
            max-width: 132%;
            filter: drop-shadow(0 6px 14px rgba(31, 58, 99, .13));
            transform: translate(-50%, 130%);
            opacity: 0;
            pointer-events: none;
            transition: transform .6s cubic-bezier(.22, .61, .36, 1), opacity .4s ease;
            will-change: transform;
        }
        /* Kelas ini dipasang JS. Tanpa JS, awannya tidak pernah menutup dan
           tombolnya kelihatan biasa — gagal ke arah yang aman. */
        .awan.awan-aktif { transform: translate(-50%, 0); opacity: 1; }
        .awan.awan-turun { transform: translate(-50%, 130%); opacity: 0; }

        @media (prefers-reduced-motion: reduce) {
            .awan { transition: opacity .2s linear; }
        }

        .login-footer {
            text-align: center;
            font-size: 13px;
            color: #1f3a63;
            margin-top: 22px;
        }

        /* ── Maskot ── */
        .auth-maskot-kotak {
            display: flex;
            justify-content: center;
            align-items: center;
        }
        .auth-maskot {
            /* Sama seperti lockup: berkasnya berlatar putih solid. */
            mix-blend-mode: multiply;
            width: 100%;
            max-width: 540px;
            height: auto;
        }

        /* ── Kaki halaman ── */
        .auth-kaki {
            border-top: 3px solid #1f3a63;
            margin-top: 18px;
            padding: 12px 0 16px;
            text-align: right;
            font-style: italic;
            font-size: clamp(13px, 1.5vw, 19px);
            color: #1f3a63;
        }

        @media (max-width: 900px) {
            .auth-badan { grid-template-columns: 1fr; }
            .auth-maskot-kotak { order: -1; }
            .auth-maskot { max-width: 320px; }
            .auth-kaki { text-align: center; }
        }
    </style>
</head>
<body>

<div class="auth-bg"></div>

<div class="auth-page">

    <div class="auth-kepala">
        <img src="{{ asset('img/kmi-lockup.png') }}"
             alt="PT. Kenco Manufactur Indonesia — Dies, Jigs &amp; Parts Manufacturing"
             class="auth-lockup">
    </div>

    <div class="auth-badan">

        <div class="auth-panel">
            <h1 class="login-title">@yield('auth_title', 'Welcome to OPTIMA')</h1>
            <div class="auth-garis"></div>
            <div class="login-subtitle">@yield('auth_subtitle', 'Gas Login buat akses sistem ini')</div>

            @yield('content')

            <div class="login-footer">
                @yield('auth_footer', 'OPTIMA - PT. Kenco Manufactur Indonesia')
            </div>
        </div>

        <div class="auth-maskot-kotak">
            <img src="{{ asset('img/kmi-maskot.png') }}" alt="" aria-hidden="true" class="auth-maskot">
        </div>

    </div>

    <div class="auth-kaki">
        OPTIMA | Copyright {{ date('Y') }} PT. Kenco Manufactur Indonesia
    </div>

</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
@stack('scripts')
</body>
</html>
