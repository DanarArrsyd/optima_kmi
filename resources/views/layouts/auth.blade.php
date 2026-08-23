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

        /* ══ Kanvas desain ══
           login_page.png sudah memuat SELURUH tampilan: lockup perusahaan,
           maskot, garis kaki, dan panel abu kosong tempat form duduk.

           Kanvas MENGISI PENUH layar dan gambarnya diregangkan mengikuti,
           bukan dipasang background-size:cover.

           cover memotong sisi gambar begitu rasio layar berbeda, dan yang ikut
           bergeser bukan cuma hiasan tapi PANEL ABUNYA — form akan meleset dari
           kotaknya, beda-beda di tiap layar. Meregangkan tidak memotong apa
           pun, dan karena panel diposisikan dalam PERSEN dari kanvas, ia tetap
           duduk tepat di kotaknya walau gambarnya sedikit meregang.

           Mengunci rasio 16:9 juga sudah dicoba: hasilnya bilah kosong di sisi
           layar yang rasionya berbeda, dan tepi gambar ini tidak satu warna
           (kiri #e6e6e6 di tengah, kanan #d8edfa) jadi bilah itu mustahil
           disamarkan dengan satu warna latar. */
        .auth-layar {
            min-height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            background: #fff;
        }

        .auth-kanvas {
            position: relative;
            width: 100vw;
            height: 100vh;
            background-image: url('{{ asset('img/login_page.png') }}');
            background-size: 100% 100%;
            background-repeat: no-repeat;

            /* Semua ukuran di dalam kanvas memakai satuan cqw, jadi form
               menyekala PERSIS bersama gambarnya di layar mana pun. */
            container-type: size;
        }

        /* Panel abu di desain: kira-kira 0–48.7% mendatar, 8–90% menurun.
           Form ditaruh di dalamnya dengan sisa ruang di tiap sisi. */
        .auth-panel {
            position: absolute;
            left: 3.2%;
            top: 12%;
            width: 41%;
            height: 72%;
            display: flex;
            flex-direction: column;
            justify-content: center;
            overflow: auto;
        }

        .login-title {
            font-size: 3.1cqw;
            font-weight: 700;
            letter-spacing: -0.02em;
            margin: 0;
            text-align: center;
        }
        .auth-garis {
            height: 0.22cqw;
            background: #1f3a63;
            margin: 1.1cqw 0 0.9cqw;
        }
        .login-subtitle {
            text-align: center;
            font-size: 1.05cqw;
            color: #374151;
            margin-bottom: 1.9cqw;
        }

        .form-label {
            display: block;
            font-size: 1cqw;
            font-weight: 700;
            margin-bottom: 0.5cqw;
            color: #111827;
        }

        /* Kotak input berikon — dipakai juga oleh halaman lupa/atur-ulang
           password, jadi nama kelasnya jangan diubah. */
        .input-group-icon { position: relative; }
        .input-group-icon > i {
            position: absolute;
            left: 1.1cqw;
            top: 50%;
            transform: translateY(-50%);
            font-size: 1.4cqw;
            color: #6b7280;
            pointer-events: none;
        }
        .form-control {
            width: 100%;
            height: 3.7cqw;
            border: 1px solid #9ca3af;
            border-radius: 0.85cqw;
            background: #fff;
            padding: 0 1.1cqw 0 3.3cqw;
            font-size: 1.1cqw;
            color: #111827;
        }
        .form-control::placeholder { color: #9ca3af; font-style: italic; }
        .form-control:focus {
            outline: none;
            border-color: #1f3a63;
            box-shadow: 0 0 0 0.2cqw rgba(31, 58, 99, .14);
        }
        .form-control.is-invalid { border-color: #b91c1c; }
        .invalid-feedback {
            display: block;
            font-size: 0.85cqw;
            color: #b91c1c;
            margin-top: 0.4cqw;
        }

        .lihat-sandi {
            position: absolute;
            right: 0.7cqw;
            top: 50%;
            transform: translateY(-50%);
            border: 0;
            background: transparent;
            color: #4b5563;
            font-size: 1.4cqw;
            line-height: 1;
            padding: 0.4cqw 0.5cqw;
            cursor: pointer;
        }

        .form-check {
            display: flex;
            align-items: center;
            gap: 0.55cqw;
            margin-top: 1cqw;
            font-size: 0.9cqw;
            color: #374151;
        }
        .form-check-input { width: 1cqw; height: 1cqw; margin: 0; }

        .btn-login {
            display: block;
            margin: 1.9cqw auto 0;
            border: 0;
            border-radius: 0.55cqw;
            background: #374151;
            color: #fff;
            font-size: 1.2cqw;
            font-weight: 700;
            letter-spacing: .02em;
            padding: 1.1cqw 3cqw;
            cursor: pointer;
        }
        .btn-login:hover { background: #1f2937; }
        .btn-login:focus-visible { outline: 0.2cqw solid #1f3a63; outline-offset: 0.2cqw; }

        .login-footer {
            text-align: center;
            font-size: 0.9cqw;
            color: #1f3a63;
            margin-top: 1.5cqw;
        }

        /* Lockup hanya muncul saat kanvas desainnya dilepas (layar sempit).
           Aturan dasar ini WAJIB berada di atas media query di bawahnya:
           kekhususannya sama, jadi yang ditulis belakangan yang menang. */
        .auth-kepala-mini { display: none; text-align: center; margin-bottom: 18px; }
        .auth-kepala-mini img { max-width: 300px; width: 76%; height: auto; }

        /* ══ Layar sempit ══
           Di bawah 820px kanvas 16:9 membuat form mengecil sampai tak terbaca.
           Desainnya dilepas dan form kembali jadi halaman biasa dengan ukuran
           piksel — halaman login harus tetap bisa dipakai dari ponsel. */
        /* Di layar yang sangat persegi (rasio di bawah 1.4, mis. monitor 5:4
           lama) regangannya sudah terlalu jauh — maskotnya kelihatan gepeng.
           Di situ desainnya dilepas, sama seperti perlakuan layar sempit. */
        @media (max-width: 820px), (max-aspect-ratio: 7 / 5) {
            .auth-kanvas {
                width: 100%;
                height: auto;
                min-height: 100vh;
                aspect-ratio: auto;
                background-image: none;
                container-type: normal;
                padding: 26px 18px 34px;

                /* Tanpa ini isinya nempel ke atas dan menyisakan ruang kosong
                   besar di bawah — kanvasnya setinggi layar, isinya tidak. */
                display: flex;
                flex-direction: column;
                justify-content: center;
            }
            .auth-panel {
                position: static;
                width: 100%;
                height: auto;
                max-width: 460px;
                margin: 0 auto;
                background: #eceef1;
                border-radius: 22px;
                padding: 26px 22px 30px;
            }
            .auth-kepala-mini { display: block; }

            .login-title { font-size: 30px; }
            .auth-garis { height: 3px; margin: 12px 0 10px; }
            .login-subtitle { font-size: 14px; margin-bottom: 20px; }
            .form-label { font-size: 14px; margin-bottom: 6px; }
            .input-group-icon > i { left: 14px; font-size: 18px; }
            .form-control { height: 50px; border-radius: 12px; padding: 0 14px 0 44px; font-size: 16px; }
            .invalid-feedback { font-size: 12px; margin-top: 5px; }
            .lihat-sandi { right: 8px; font-size: 18px; padding: 6px 8px; }
            .form-check { gap: 8px; margin-top: 12px; font-size: 13px; }
            .form-check-input { width: 15px; height: 15px; }
            .btn-login { margin-top: 20px; border-radius: 8px; font-size: 16px; padding: 14px 34px; }
            .login-footer { font-size: 12px; margin-top: 16px; }
        }

    </style>
</head>
<body>

<div class="auth-layar">
    <div class="auth-kanvas">

        {{-- Hanya tampil di layar sempit, saat kanvas desainnya dilepas. --}}
        <div class="auth-kepala-mini">
            <img src="{{ asset('img/kmi-lockup.png') }}"
                 alt="PT. Kenco Manufactur Indonesia — Dies, Jigs &amp; Parts Manufacturing">
        </div>

        <div class="auth-panel">
            <h1 class="login-title">@yield('auth_title', 'Welcome to OPTIMA')</h1>
            <div class="auth-garis"></div>
            <div class="login-subtitle">@yield('auth_subtitle', 'Gas Login buat akses sistem ini')</div>

            @yield('content')

            <div class="login-footer">
                @yield('auth_footer', 'OPTIMA - PT. Kenco Manufactur Indonesia')
            </div>
        </div>

    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
@stack('scripts')
</body>
</html>
