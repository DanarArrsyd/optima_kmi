<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>OPTIMA &mdash; @yield('title', 'Dashboard')</title>
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
        /* ══════════════════════════════════════════════════════════════
           OPTIMA ERP — Industrial Monochrome Design System
           Reference: SAP / Oracle NetSuite / Odoo Enterprise / MES
        ══════════════════════════════════════════════════════════════ */

        :root {
            /* Layout dimensions */
            --tb-h:        46px;
            --sb-col-w:    64px;
            --sb-exp-w:    220px;

            /* Surfaces */
            --bg:          #f3f4f6;   /* gray-100 */
            --surface:     #ffffff;
            --surface-2:   #f9fafb;   /* zebra / hover */
            --surface-3:   #f3f4f6;

            /* Dark chrome */
            --tb-bg:       #111827;   /* gray-900 */
            --sb-bg:       #1f2937;   /* gray-800 */
            --sb-hover:    #2d3748;
            --sb-active:   #374151;   /* gray-700 */
            --sb-border:   #0a0f1a;

            /* Borders */
            --border:      #d1d5db;   /* gray-300 */
            --border-2:    #e5e7eb;   /* gray-200 */
            --border-3:    #f3f4f6;

            /* Text */
            --text:        #111827;
            --text-2:      #4b5563;   /* gray-600 */
            --text-3:      #6b7280;   /* gray-500 */
            --text-4:      #9ca3af;   /* gray-400 */

            /* Sidebar text */
            --sb-text:     #9ca3af;
            --sb-text-h:   #e5e7eb;
            --sb-text-a:   #ffffff;

            /* Buttons (monochrome primary) */
            --btn-bg:      #4b5563;
            --btn-bg-h:    #374151;
            --btn-bg-a:    #1f2937;

            /* Functional accents (used sparingly for status only) */
            --c-success:   #15803d;
            --c-success-bg:#f0fdf4;
            --c-danger:    #b91c1c;
            --c-danger-bg: #fef2f2;
            --c-warn:      #a16207;
            --c-warn-bg:   #fefce8;
            --c-info:      #1e40af;
            --c-info-bg:   #eff6ff;

            /* Transitions (strict <0.2s) */
            --t:           0.16s ease;
        }

        /* ══ Reset & Base ═══════════════════════════════════════════ */
        *, *::before, *::after { box-sizing: border-box; }
        * { font-family: Arial, Helvetica, sans-serif !important; }
        html, body { height: 100%; }
        body {
            margin: 0;
            font-size: 15px;
            line-height: 1.5;
            background: var(--bg);
            color: var(--text);
            overflow-x: hidden;
            -webkit-font-smoothing: antialiased;
        }
        * { box-shadow: none !important; }
        a { color: var(--btn-bg); text-decoration: none; }
        a:hover { color: var(--btn-bg-h); }
        [x-cloak] { display: none !important; }

        /* ══ Progress Bar ═══════════════════════════════════════════ */
        #page-progress {
            position: fixed;
            top: 0; left: 0;
            height: 2px;
            width: 0%;
            z-index: 9999;
            background: #6b7280;
            transition: width 0.25s linear, opacity 0.2s;
            pointer-events: none;
        }

        /* ══ Topbar ═════════════════════════════════════════════════ */
        #topbar {
            position: fixed;
            top: 0; left: 0; right: 0;
            height: var(--tb-h);
            background: var(--tb-bg);
            border-bottom: 1px solid var(--sb-border);
            display: flex;
            align-items: center;
            z-index: 1100;
        }
        #sb-toggle {
            width: var(--sb-col-w);
            min-width: var(--sb-col-w);
            height: var(--tb-h);
            display: flex; align-items: center; justify-content: center;
            background: transparent;
            border: none;
            border-right: 1px solid var(--sb-border);
            color: #d1d5db;
            font-size: 16px;
            cursor: pointer;
            transition: background var(--t), color var(--t);
            flex-shrink: 0;
        }
        #sb-toggle:hover  { background: var(--sb-hover); color: #fff; }
        #sb-toggle:focus  { outline: none; }
        body.sb-pinned #sb-toggle { color: #fff; background: #374151; }

        /* Brand */
        .app-brand {
            display: flex;
            align-items: center;
            gap: 9px;
            padding: 0 14px;
            height: var(--tb-h);
            white-space: nowrap;
            border-right: 1px solid var(--sb-border);
            color: #fff;
        }
        .brand-mark {
            width: 26px; height: 26px;
            display: flex; align-items: center; justify-content: center;
            flex-shrink: 0;
        }
        .brand-mark img {
            width: 26px;
            height: 26px;
            object-fit: contain;
            display: block;
        }
        .brand-name {
            font-size: 15px;
            font-weight: 700;
            color: #f3f4f6;
            letter-spacing: 0.5px;
        }
        .brand-tag {
            font-size: 11px;
            font-weight: 700;
            color: #6b7280;
            letter-spacing: 1.5px;
            padding: 1px 5px;
            border: 1px solid #374151;
            margin-left: 4px;
            text-transform: uppercase;
        }

        /* Breadcrumb / Page Title in Topbar */
        .topbar-page {
            display: flex;
            align-items: center;
            gap: 6px;
            padding: 0 16px;
            color: #9ca3af;
            font-size: 14px;
            font-weight: 500;
        }
        .topbar-page i { font-size: 13px; color: #6b7280; }
        .topbar-page strong {
            color: #e5e7eb;
            font-weight: 600;
        }

        /* Right side */
        .topbar-right {
            margin-left: auto;
            display: flex;
            align-items: center;
            gap: 0;
            height: var(--tb-h);
        }
        .topbar-item {
            display: flex;
            align-items: center;
            gap: 7px;
            padding: 0 14px;
            height: var(--tb-h);
            border-left: 1px solid var(--sb-border);
            color: #d1d5db;
            font-size: 14px;
        }
        .topbar-item i { font-size: 15px; color: #9ca3af; }
        .topbar-item.user-block {
            gap: 9px;
        }
        .user-info {
            display: flex; flex-direction: column;
            line-height: 1.25;
            text-align: left;
        }
        .user-info .name {
            font-size: 14px;
            font-weight: 600;
            color: #f3f4f6;
        }
        .user-info .role {
            font-size: 11px;
            color: #9ca3af;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        .topbar-logout {
            background: transparent;
            border: none;
            border-left: 1px solid var(--sb-border);
            padding: 0 14px;
            height: var(--tb-h);
            color: #d1d5db;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: background var(--t), color var(--t);
        }
        .topbar-logout:hover { background: #374151; color: #fff; }
        .topbar-logout i { font-size: 15px; }

        /* ══ Sidebar ════════════════════════════════════════════════ */
        #sidebar {
            position: fixed;
            top: var(--tb-h);
            left: 0;
            bottom: 0;
            width: 0;
            background: var(--sb-bg);
            border-right: 1px solid var(--sb-border);
            overflow: hidden;
            z-index: 1050;
            transition: width var(--t);
        }
        body.sb-pinned #sidebar { overflow-y: auto; }
        #sidebar::-webkit-scrollbar { width: 4px; }
        #sidebar::-webkit-scrollbar-track { background: transparent; }
        #sidebar::-webkit-scrollbar-thumb { background: #374151; border-radius: 2px; }

        /* Expanded: pinned only */
        body.sb-pinned #sidebar { width: var(--sb-exp-w); }

        /* Main shifts when pinned */
        body.sb-pinned #main { margin-left: var(--sb-exp-w); }

        /* Sidebar: satu pembungkus saja sejak menu jadi satu tingkat.
           Pemisah antar-section dan label section dibuang — tugasnya
           sudah diambil header grup. */
        .nav-section { padding: 10px 0 4px; }

        .nav-item-link {
            display: flex;
            align-items: center;
            padding: 0 0 0 24px;
            height: 40px;
            color: var(--sb-text);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            white-space: nowrap;
            border-left: 2px solid transparent;
            transition: background var(--t), color var(--t), padding var(--t);
            position: relative;
            overflow: hidden;
        }
        .nav-item-link:hover {
            background: var(--sb-hover);
            color: var(--sb-text-h);
        }
        .nav-item-link.active {
            background: var(--sb-active);
            color: var(--sb-text-a);
            border-left-color: #d1d5db;
            font-weight: 600;
        }
        .nav-item-link i {
            font-size: 17px;
            width: 24px;
            text-align: center;
            margin-left: -8px;
            margin-right: 12px;
            transition: margin var(--t);
        }
        .nav-text {
            white-space: nowrap;
        }

        /* ── Nav accordion group (berlaku untuk keenam grup sidebar,
           bukan cuma satu section "Dashboard" seperti dulu) ── */
        .nav-group-header {
            display: flex; align-items: center; width: 100%;
            background: none; border: none; cursor: pointer;
            padding: 0 14px 0 24px; height: 40px;
            color: var(--sb-text); font-size: 14px; font-weight: 500;
            white-space: nowrap; border-left: 2px solid transparent;
            transition: background var(--t), color var(--t);
        }
        .nav-group-header:hover { background: var(--sb-hover); color: var(--sb-text-h); }
        .nav-group-header.has-active { color: var(--sb-text-a); font-weight: 600; }
        .nav-group-header > i:first-child {
            font-size: 17px; width: 24px; text-align: center;
            margin-left: -8px; margin-right: 12px;
        }
        .nav-group-header .nav-caret {
            margin-left: auto; font-size: 12px; transition: transform var(--t);
        }
        .nav-group-header.open .nav-caret { transform: rotate(180deg); }
        .nav-group-body .nav-item-link { padding-left: 44px; height: 36px; font-size: 13px; }
        .nav-group-body .nav-item-link i { font-size: 15px; }

        /* ══ Mobile ═════════════════════════════════════════════════ */
        #sb-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1040;
        }
        @media (max-width: 767px) {
            body.sb-mobile-open #sb-overlay { display: block; }
            body.sb-mobile-open #sidebar    { width: var(--sb-exp-w); overflow-y: auto; }
            body:not(.sb-mobile-open) #sidebar { width: 0; }
            #main { margin-left: 0 !important; }
        }

        /* ══ Main ═══════════════════════════════════════════════════ */
        /* Jarak dari topbar dipasang sebagai PADDING, bukan margin.
           margin-top di sini runtuh keluar dari <body> sehingga body ikut
           terdorong 46px dan dokumen selalu 46px lebih tinggi dari layar —
           setiap halaman menyisakan gulungan hantu, termasuk /overview yang
           justru dirancang terkunci satu layar tanpa scroll. Padding tidak
           pernah runtuh. Posisi konten tidak berubah: tetap 46 + 18 dari atas. */
        #main {
            margin-left: 0;
            padding: calc(var(--tb-h) + 18px) 22px 18px;
            min-height: 100vh;
            transition: margin-left var(--t);
        }

        @keyframes pageIn {
            from { opacity: 0.4; }
            to   { opacity: 1; }
        }
        /* TANPA `forwards`. Animasi opacity yang isiannya dipertahankan membuat
           #main punya stacking context sendiri yang tidak pernah hilang, sehingga
           SEMUA laci di dalamnya terkurung di bawah topbar (z-index 1100) berapa
           pun z-index laci itu — kepala laci tidak pernah terlihat. Tanpa fill,
           konteksnya lepas begitu animasi selesai; hasil akhirnya sama karena
           opacity dasarnya memang 1. */
        #main { animation: pageIn 0.12s ease; }

        /* ══ Page Header ════════════════════════════════════════════ */
        .page-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin-bottom: 14px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border);
            gap: 12px;
        }
        .page-title {
            font-size: 19px;
            font-weight: 700;
            color: var(--text);
            margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .page-title i {
            color: var(--text-2);
            font-size: 18px;
        }

        /* ══ ERP Panels ═════════════════════════════════════════════ */
        .erp-panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 2px;
            margin-bottom: 12px;
        }
        .erp-panel-header {
            background: var(--surface-2);
            border-bottom: 1px solid var(--border);
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            color: var(--text-2);
            text-transform: uppercase;
            letter-spacing: 0.8px;
            display: flex;
            align-items: center;
            gap: 8px;
            min-height: 34px;
        }
        .erp-panel-body { padding: 14px; }

        /* ══ Tables — Enterprise Database Style ═════════════════════ */
        .erp-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            background: var(--surface);
        }
        .erp-table thead tr {
            background: #e5e7eb;
            border-bottom: 1px solid #9ca3af;
        }
        .erp-table thead th {
            padding: 9px 12px;
            font-weight: 700;
            color: var(--text);
            text-transform: uppercase;
            font-size: 12px;
            letter-spacing: 0.6px;
            border-right: 1px solid var(--border);
            white-space: nowrap;
            text-align: left;
            background: #e5e7eb;
        }
        .erp-table thead th:last-child { border-right: none; }
        .erp-table tbody tr {
            border-bottom: 1px solid var(--border-3);
            transition: background 0.08s ease;
        }
        .erp-table tbody tr:nth-child(even) { background: var(--surface-2); }
        .erp-table tbody tr:hover { background: #eef2f6; }
        .erp-table tbody tr:last-child { border-bottom: none; }
        .erp-table tbody td {
            padding: 8px 12px;
            color: var(--text);
            border-right: 1px solid var(--border-3);
            vertical-align: middle;
            font-size: 14px;
        }
        .erp-table tbody td:last-child { border-right: none; }

        /* Code/monospace cells */
        code {
            font-family: 'Courier New', monospace !important;
            font-size: 13px;
            background: var(--surface-3);
            color: var(--text);
            padding: 1px 5px;
            border: 1px solid var(--border-2);
            border-radius: 2px;
        }

        /* ══ Buttons ════════════════════════════════════════════════ */
        .btn {
            border-radius: 2px;
            font-size: 14px;
            font-weight: 600;
            padding: 7px 14px;
            transition: background var(--t), border-color var(--t), color var(--t);
            border: 1px solid transparent;
            line-height: 1.4;
        }
        .btn:focus, .btn:active, .btn:focus-visible { outline: none !important; }

        .btn-primary,
        .btn-secondary {
            background: var(--btn-bg);
            border-color: var(--btn-bg);
            color: #fff;
        }
        .btn-primary:hover,
        .btn-secondary:hover {
            background: var(--btn-bg-h);
            border-color: var(--btn-bg-h);
            color: #fff;
        }
        .btn-success {
            background: #166534;
            border-color: #166534;
            color: #fff;
        }
        .btn-success:hover { background: #14532d; border-color: #14532d; }
        .btn-danger {
            background: #991b1b;
            border-color: #991b1b;
            color: #fff;
        }
        .btn-danger:hover { background: #7f1d1d; border-color: #7f1d1d; }
        .btn-warning {
            background: #854d0e;
            border-color: #854d0e;
            color: #fff;
        }
        .btn-warning:hover { background: #713f12; border-color: #713f12; color: #fff; }

        .btn-outline-primary, .btn-outline-secondary {
            background: var(--surface);
            border-color: var(--border);
            color: var(--text-2);
        }
        .btn-outline-primary:hover, .btn-outline-secondary:hover {
            background: var(--surface-2);
            border-color: #9ca3af;
            color: var(--text);
        }
        .btn-outline-success {
            background: var(--surface);
            border-color: #86efac;
            color: #166534;
        }
        .btn-outline-success:hover { background: #f0fdf4; border-color: #166534; color: #14532d; }
        .btn-outline-danger {
            background: var(--surface);
            border-color: #fca5a5;
            color: #991b1b;
        }
        .btn-outline-danger:hover { background: #fef2f2; border-color: #991b1b; color: #7f1d1d; }
        .btn-outline-warning {
            background: var(--surface);
            border-color: #fde68a;
            color: #854d0e;
        }
        .btn-outline-warning:hover { background: #fefce8; border-color: #854d0e; color: #713f12; }

        .btn-sm {
            padding: 5px 11px;
            font-size: 13px;
        }

        /* ══ Forms ══════════════════════════════════════════════════ */
        .form-control, .form-select {
            border-radius: 2px;
            border: 1px solid var(--border);
            font-size: 14px;
            padding: 7px 10px;
            color: var(--text);
            background: var(--surface);
            transition: border-color var(--t);
            line-height: 1.4;
        }
        .form-control:focus, .form-select:focus {
            border-color: #6b7280;
            outline: none;
        }
        .form-control:disabled, .form-select:disabled {
            background: var(--surface-3);
            color: var(--text-3);
        }
        .form-label {
            font-size: 13px;
            font-weight: 700;
            color: var(--text-2);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .form-control-sm, .form-select-sm {
            font-size: 13px;
            padding: 5px 8px;
        }
        .invalid-feedback {
            font-size: 12px;
            color: var(--c-danger);
            margin-top: 3px;
        }
        .form-control.is-invalid, .form-select.is-invalid {
            border-color: var(--c-danger);
        }
        textarea.form-control { line-height: 1.5; }

        /* ══ Alerts ═════════════════════════════════════════════════ */
        .alert {
            border-radius: 2px;
            border: 1px solid;
            border-left-width: 3px;
            font-size: 14px;
            padding: 9px 12px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            margin-bottom: 12px;
        }
        .alert i { font-size: 14px; margin-top: 1px; flex-shrink: 0; }
        .alert-success { border-color: var(--c-success); background: var(--c-success-bg); color: var(--c-success); }
        .alert-danger  { border-color: var(--c-danger);  background: var(--c-danger-bg);  color: var(--c-danger); }
        .alert-warning { border-color: var(--c-warn);    background: var(--c-warn-bg);    color: var(--c-warn); }
        .alert-info    { border-color: var(--c-info);    background: var(--c-info-bg);    color: var(--c-info); }
        .alert-dismissible .btn-close {
            padding: 9px 12px;
            font-size: 10px;
        }

        /* ══ Badges ═════════════════════════════════════════════════ */
        .badge {
            border-radius: 2px;
            font-size: 11.5px;
            font-weight: 700;
            padding: 3px 7px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .badge.bg-primary, .badge.bg-secondary {
            background: var(--btn-bg) !important;
            color: #fff;
        }
        .badge.bg-info {
            background: #1e40af !important;
            color: #fff !important;
        }

        /* ══ Filter Bar ═════════════════════════════════════════════ */
        .filter-bar {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: 10px 12px;
            margin-bottom: 12px;
            display: flex;
            align-items: flex-end;
            gap: 10px;
            flex-wrap: wrap;
        }
        .filter-bar .filter-group {
            display: flex;
            flex-direction: column;
            gap: 3px;
        }
        .filter-bar label {
            font-size: 12px;
            font-weight: 700;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ══ Pagination ═════════════════════════════════════════════ */
        .pagination { gap: 0; margin: 0; }
        .page-link {
            border-radius: 0 !important;
            font-size: 13px;
            padding: 6px 12px;
            color: var(--text-2);
            border: 1px solid var(--border);
            margin: 0 -1px 0 0;
            background: var(--surface);
            transition: background var(--t);
        }
        .page-item.active .page-link {
            background: var(--btn-bg);
            border-color: var(--btn-bg);
            color: #fff;
        }
        .page-link:hover { background: var(--surface-2); color: var(--text); border-color: var(--border); }
        .page-item.disabled .page-link { background: var(--surface-3); color: var(--text-4); }

        /* ══ Action Buttons (table actions) ═════════════════════════ */
        .action-btns {
            display: flex;
            align-items: center;
            gap: 3px;
            flex-wrap: nowrap;
        }
        .action-btns .btn {
            min-width: 54px;
            padding: 4px 10px;
            font-size: 12.5px;
            font-weight: 600;
            text-align: center;
            white-space: nowrap;
            border-radius: 2px;
        }
        .action-btns form { margin: 0; display: inline-flex; }

        /* ══ Cards (Bootstrap compat) ═══════════════════════════════ */
        .card {
            border-radius: 2px;
            border: 1px solid var(--border);
            background: var(--surface);
        }
        .card-body { padding: 14px; }
        .card-header {
            background: var(--surface-2);
            border-bottom: 1px solid var(--border);
            padding: 9px 14px;
            font-size: 13px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-2);
        }

        /* ══ Bootstrap dropdown / modal overrides ═══════════════════ */
        .dropdown-menu {
            border-radius: 2px;
            border-color: var(--border);
            font-size: 14px;
            padding: 4px 0;
        }
        .dropdown-item { padding: 7px 16px; font-size: 14px; }
        .dropdown-item:hover { background: var(--surface-2); color: var(--text); }
        /* Modal harus di atas topbar (1100) & sidebar (1050), kalau tidak backdrop
           tidak menutup chrome dan dialog tertimpa topbar → tampak macet & tak bisa diklik. */
        .modal { z-index: 1200; }
        .modal-backdrop { z-index: 1190; }
        .modal-content { border-radius: 2px; border: 1px solid var(--border); }
        .modal-header { background: var(--surface-2); border-bottom: 1px solid var(--border); padding: 10px 14px; }
        .modal-title { font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .modal-body { padding: 16px; font-size: 15px; }
        .modal-footer { padding: 10px 14px; border-top: 1px solid var(--border); }

        /* ══ Charts (Chart.js wrapper) ═══════════════════════════════ */
        canvas { max-width: 100%; }

        /* ══ Panel blok opsional di form Input ═══════════════════════
           Kemasan blok "Ada NG Proses?" dan "Ada Dandori". Satu definisi:
           gaya sebaris yang disalin ulang sudah pernah menyimpang jadi dua
           bentuk (p-3 vs padding:12px) di dua form yang sama. */
        .input-panel {
            background: #fafafa;
            border: 1px solid #e5e7eb;
            border-radius: 4px;
            padding: 12px;
        }

        /* ══ Typeahead / Autocomplete ════════════════════════════════ */
        .ta-wrap { position: relative; }
        .ta-input { width: 100%; }
        .ta-dropdown {
            position: absolute;
            top: calc(100% - 1px);
            left: 0; right: 0;
            background: #fff;
            border: 1px solid #9ca3af;
            border-top: none;
            max-height: 220px;
            overflow-y: auto;
            z-index: 200;
        }
        .ta-item {
            padding: 8px 12px;
            font-size: 14px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
            color: #111827;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .ta-item:last-child { border-bottom: none; }
        .ta-item:hover, .ta-item.ta-active { background: #e5e7eb; }
        .ta-empty {
            padding: 8px 12px;
            font-size: 13px;
            color: #9ca3af;
        }
        .ta-hint {
            font-size: 12px;
            color: #9ca3af;
            margin-top: 3px;
        }

        /* ══ Utility ═════════════════════════════════════════════════ */
        .text-muted { color: var(--text-3) !important; }
        .text-success { color: var(--c-success) !important; }
        .text-danger  { color: var(--c-danger)  !important; }
        .text-warning { color: var(--c-warn)    !important; }
        hr { border-color: var(--border); margin: 12px 0; }
        small, .small { font-size: 13px; }

        /* ══ Sticky freeze columns (shared) ═══════════════════════════
           Dipakai tabel dengan kolom yang di-freeze horizontal.
           border-collapse:separate → tiap cell punya border sendiri,
           wajib agar border ikut menempel saat cell di-sticky-kan. */
        .tbl-freeze { border-collapse: separate !important; border-spacing: 0 !important; }
        .tbl-freeze thead tr { background: #e5e7eb; }
        .tbl-freeze thead th { border-right: 1px solid #d1d5db; border-bottom: 2px solid #9ca3af; }
        .tbl-freeze tbody td { border-right: 1px solid #ebebeb; border-bottom: 1px solid #ebebeb; }
        .tbl-freeze tbody tr:last-child td { border-bottom: none; }
        .tbl-freeze thead th:last-child, .tbl-freeze tbody td:last-child { border-right: none; }

        /* Sticky cell WAJIB background opak → menutup konten yang discroll di belakangnya */
        .tbl-freeze .sc { position: sticky; z-index: 2; background: #fff; }
        .tbl-freeze thead th.sc { z-index: 12; background: #e5e7eb; }  /* pojok freeze di atas header(10) & sticky body(2) */
        .tbl-freeze tbody tr:nth-child(even) td.sc { background: #f9fafb; }
        .tbl-freeze tbody tr:hover td.sc { background: #eef2f6; }
        .tbl-freeze tbody tr.anomaly-row td.sc { background: #fefce8; }
        .tbl-freeze tbody tr.anomaly-row:hover td.sc { background: #fef3c7; }
        .tbl-freeze tbody tr.last-row td.sc { background: #f0f9ff; }

        /* Offset kiri tiap kolom freeze diisi via JS (lebar NYATA kolom) → tidak ada gap */
        .tbl-freeze .sc-1 { left: var(--sc1, 0px); }
        .tbl-freeze .sc-2 { left: var(--sc2, 0px); }
        .tbl-freeze .sc-3 { left: var(--sc3, 0px); }
        .tbl-freeze .sc-4 { left: var(--sc4, 0px); }
        .tbl-freeze .sc-5 { left: var(--sc5, 0px); }

        /* Separator kolom freeze terakhir sebagai box-shadow → digambar oleh sticky
           cell sendiri, selalu menempel & tak pernah ter-clip / bergeser saat scroll. */
        .tbl-freeze .sc-sep { border-right: none; box-shadow: 2px 0 0 0 #9ca3af !important; }
        .tbl-freeze thead th.sc-sep { box-shadow: 2px 0 0 0 #6b7280 !important; }


        /* ══ ERP Workspace ══════════════════════════════════════════
           Pola halaman bergaya ACCURATE: strip toolbar di atas, dock
           filter tegak di kiri, grid rapat, status bar di bawah.

           Semua aturan sengaja di-scope ke .erp-ws. Halaman yang belum
           dimigrasi tidak ikut berubah sedikit pun, jadi migrasinya bisa
           satu halaman per PR tanpa halaman lain jadi korban.

           Ukuran diatur lewat token di .erp-ws, bukan angka tersebar.
           Ganti satu token = seluruh halaman ikut. Font tetap Arial dari
           aturan global — tidak ada keluarga huruf baru di sini.
        ══════════════════════════════════════════════════════════════ */
        /* margin-top #main ikut runtuh keluar dari <body>, sehingga body
           terdorong 46px dan dokumen jadi lebih tinggi dari layar — halaman
           tergulung di belakang ruang kerja yang seharusnya terkunci.
           Dipakai padding, bukan margin: padding tidak pernah runtuh. */
        #main.erp-flush {
            padding: var(--tb-h) 0 0 0 !important;
            min-height: 0;
            height: 100vh;
            overflow: hidden;
        }

        .erp-ws {
            --erp-fs:     12px;
            --erp-fs-sm:  11px;
            --erp-row-h:  24px;
            --erp-ctl-h:  26px;
            --erp-tb-h:   28px;
            --erp-st-h:   22px;
            --erp-dock-w: 210px;

            display: flex;
            flex-direction: column;
            height: calc(100vh - var(--tb-h));
            font-size: var(--erp-fs);
        }

        /* Tablet & layar sentuh: layout sama persis, target sentuh membesar.
           Dibaca dari JENIS PENUNJUK, bukan lebar layar — tablet 1280px tetap
           dapat target besar, laptop 1280px tetap rapat. */
        @media (pointer: coarse) {
            .erp-ws {
                --erp-fs:    13px;
                --erp-row-h: 40px;
                --erp-ctl-h: 40px;
                --erp-tb-h:  44px;
                --erp-st-h:  28px;
            }
        }

        /* ── Strip toolbar ── */
        .erp-tb {
            display: flex; align-items: stretch;
            height: var(--erp-tb-h); flex: 0 0 auto;
            background: var(--surface);
            border-bottom: 1px solid var(--border);
        }
        .erp-tb-btn {
            display: inline-flex; align-items: center; gap: 5px;
            padding: 0 10px; white-space: nowrap; cursor: pointer;
            font-size: var(--erp-fs); font-family: inherit; line-height: 1;
            color: var(--text-2); background: none; text-decoration: none;
            border: none; border-right: 1px solid var(--border-3);
        }
        .erp-tb-btn:hover { background: var(--surface-3); color: var(--text); }
        .erp-tb-btn[disabled] { color: var(--text-4); cursor: default; }
        .erp-tb-btn[disabled]:hover { background: none; color: var(--text-4); }
        .erp-tb-btn.is-on { background: var(--surface-3); color: var(--text); font-weight: 700; }
        .erp-tb-btn.is-new { background: #dcfce7; color: #14532d; font-weight: 700; }
        .erp-tb-btn.is-new:hover { background: #bbf7d0; color: #14532d; }
        .erp-tb-btn.is-anom { background: #fef08a; color: #713f12; font-weight: 700; }
        .erp-tb-btn.is-anom:hover { background: #fde047; color: #713f12; }
        .erp-tb-sp { flex: 1 1 auto; }

        /* ── Badan: dock + isi ── */
        .erp-ws-body { display: flex; flex: 1 1 auto; min-height: 0; }

        .erp-dock {
            width: var(--erp-dock-w); flex: 0 0 var(--erp-dock-w);
            background: var(--surface); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; min-height: 0;
        }
        .erp-dock-hd {
            display: flex; align-items: center; justify-content: space-between;
            height: var(--erp-row-h); flex: 0 0 auto; padding: 0 8px;
            background: #e5e7eb; border-bottom: 1px solid var(--border);
            font-size: var(--erp-fs-sm); font-weight: 700;
            letter-spacing: 0.08em; text-transform: uppercase;
        }
        .erp-dock-x {
            background: none; border: none; cursor: pointer; padding: 0 2px;
            color: var(--text-2); font-size: 14px; line-height: 1;
        }
        .erp-dock-body {
            flex: 1 1 auto; overflow-y: auto; min-height: 0;
            padding: 8px; display: flex; flex-direction: column; gap: 10px;
        }
        .erp-grp { display: flex; flex-direction: column; gap: 4px; }
        .erp-grp > b {
            font-size: var(--erp-fs-sm); font-weight: 700;
            letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-3);
        }
        .erp-in {
            height: var(--erp-ctl-h); width: 100%; box-sizing: border-box;
            border: 1px solid var(--border); background: var(--surface);
            color: var(--text); font-family: inherit; font-size: var(--erp-fs);
            padding: 0 6px; border-radius: 2px; outline: none;
        }
        .erp-in:focus { border-color: #6b7280; }
        .erp-in[readonly] { background: var(--surface-3); color: var(--text-3); }
        .erp-in.is-set { border-color: #334155; border-width: 1.5px; font-weight: 700; }
        .erp-in::placeholder { color: var(--text-4); }
        .erp-dock-row { display: grid; grid-template-columns: 34px 1fr; align-items: center; gap: 6px; }
        .erp-dock-row > span { font-size: var(--erp-fs-sm); color: var(--text-3); }
        .erp-chk { display: flex; align-items: center; gap: 6px; cursor: pointer; }
        /* Typeahead lama (.ta-*) dipakai halaman analitik maintenance; di dalam
           dock ia harus ikut lebar penuh, bukan lebar bawaan Bootstrap. */
        .erp-dock .ta-wrap { position: relative; width: 100%; }
        .erp-dock .ta-dropdown {
            position: absolute; top: calc(100% + 2px); left: 0; right: 0;
            background: var(--surface); border: 1px solid var(--border);
            z-index: 600; max-height: 220px; overflow-y: auto;
        }
        .erp-dock .ta-item { padding: 5px 8px; cursor: pointer; font-size: var(--erp-fs); }
        .erp-dock .ta-item:hover, .erp-dock .ta-item.ta-active { background: var(--surface-3); }
        .erp-dock .ta-empty { padding: 5px 8px; color: var(--text-4); font-size: var(--erp-fs-sm); }
        .erp-chk input { margin: 0; }
        .erp-in:disabled { background: var(--surface-3); color: var(--text-4); }
        .erp-btn {
            height: var(--erp-ctl-h); padding: 0 12px; cursor: pointer;
            border: 1px solid var(--border); background: var(--surface-2);
            color: var(--text); font-family: inherit; font-size: var(--erp-fs);
            border-radius: 2px; text-decoration: none;
            display: inline-flex; align-items: center; justify-content: center; gap: 5px;
        }
        .erp-btn:hover { background: var(--surface-3); color: var(--text); }
        .erp-btn.is-pri { background: #374151; border-color: #374151; color: #fff; font-weight: 700; }
        .erp-btn.is-pri:hover { background: #1f2937; color: #fff; }

        /* ── Wilayah grid ── */
        .erp-ws-main { flex: 1 1 auto; min-width: 0; display: flex; flex-direction: column; }
        .erp-scroll { flex: 1 1 auto; overflow: auto; min-height: 0; background: var(--surface); }

        .erp-ws .erp-table { font-size: var(--erp-fs); }
        .erp-ws .erp-table thead th {
            height: var(--erp-row-h); padding: 0 6px;
            font-size: var(--erp-fs-sm); letter-spacing: 0.03em;
        }
        /* .erp-table tbody td memasang font-size 14px sendiri, jadi mewarisi
           dari .erp-table saja tidak cukup — harus ditimpa di selector td. */
        .erp-ws .erp-table tbody td {
            height: var(--erp-row-h); padding: 0 6px; white-space: nowrap;
            font-size: var(--erp-fs);
        }
        .erp-ws .erp-table .btn-sm { padding: 0 6px; font-size: var(--erp-fs-sm); line-height: 1.6; }
        .erp-ws .erp-table .erp-meta { font-size: var(--erp-fs-sm); }

        /* ── Status bar ── */
        .erp-status {
            flex: 0 0 auto; height: var(--erp-st-h);
            display: flex; align-items: center; gap: 14px; padding: 0 10px;
            background: #dbe4f0; border-top: 1px solid var(--border);
            font-size: var(--erp-fs-sm); color: var(--text);
        }
        .erp-status .sp { flex: 1 1 auto; }
        .erp-status b { font-weight: 700; }

        /* Di layar sempit dock jadi lapisan menumpang — berdampingan tidak
           menyisakan lebar yang layak untuk grid apa pun. */
        @media (max-width: 900px) {
            .erp-dock {
                position: absolute; top: 0; bottom: var(--erp-st-h); left: 0;
                z-index: 40; border-right: 1px solid var(--border);
            }
            .erp-ws-body { position: relative; }
        }


        /* ── Dropdown ketik-cari di dalam dock ──
           Dulu disalin per halaman sebagai .fbar-*; dua salinannya sudah
           menyimpang satu sama lain. Sekarang satu definisi di sini. */
        .erp-dd {
            position: absolute; top: calc(100% + 2px); left: 0; right: 0;
            min-width: 100%; background: var(--surface);
            border: 1px solid var(--border); border-radius: 2px;
            z-index: 600; max-height: 220px; overflow-y: auto;
        }
        /* Nama part bisa jauh lebih panjang dari lebar dock. Dropdown tidak
           bisa melebar keluar karena .erp-dock-body menggulung dan ikut
           memotong — jadi teksnya dibiarkan turun baris, bukan dipotong
           elipsis. Nama panjang tetap terbaca utuh. */
        .erp-dd-item {
            padding: 5px 8px; cursor: pointer; font-size: var(--erp-fs, 12px);
            color: var(--text); line-height: 1.35;
            white-space: normal; overflow-wrap: anywhere;
            border-bottom: 1px solid var(--border-3);
        }
        .erp-dd-item:last-child { border-bottom: none; }
        .erp-dd-item:hover { background: var(--surface-3); }
        .erp-dd-item .sub {
            display: block; font-size: var(--erp-fs-sm, 11px);
            color: var(--text-4); margin-top: 1px;
        }
        .erp-dd { max-height: 260px; }
        .erp-clr {
            position: absolute; right: 5px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: var(--text-4);
            cursor: pointer; padding: 0; font-size: 14px; line-height: 1;
        }

        /* ── Pemilih mesin di dalam dock ──
           Memanjang ke bawah, bukan melayang: dock hanya 210px, panel
           melayang akan keluar dari tepi layar. */
        .erp-mesin-pick {
            border: 1px solid var(--border); background: var(--surface);
            display: flex; flex-direction: column;
            max-height: 230px; overflow-y: auto;
        }
        .erp-mesin-line {
            position: sticky; top: 0; z-index: 1;
            padding: 3px 6px; background: var(--surface-3);
            border-bottom: 1px solid var(--border-2);
            font-size: 10px; font-weight: 700; letter-spacing: 0.12em;
            text-transform: uppercase; color: var(--text-3);
        }
        /* Daftar mesin, bukan kotak-kotak: satu baris per mesin dengan kode,
           nama, dan centang tipis di kanan untuk yang terpilih. Lebih mudah
           dipindai dan muat nama mesin, yang tidak mungkin di dalam chip. */
        .erp-mesin-chips { display: flex; flex-direction: column; }
        .erp-chip {
            display: flex; align-items: center; gap: 7px; width: 100%;
            min-height: var(--erp-ctl-h); padding: 3px 6px; cursor: pointer;
            border: none; border-bottom: 1px solid var(--border-3);
            background: var(--surface); color: var(--text-2);
            font-family: inherit; font-size: var(--erp-fs); text-align: left;
        }
        .erp-chip:last-child { border-bottom: none; }
        .erp-chip:hover { background: var(--surface-3); }
        .erp-chip .kode { font-weight: 700; color: var(--text); flex: 0 0 auto; }
        .erp-chip .nama {
            flex: 1 1 auto; min-width: 0; color: var(--text-3);
            font-size: var(--erp-fs-sm);
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .erp-chip .tik { flex: 0 0 auto; color: var(--c-success); opacity: 0; font-size: 13px; }
        .erp-chip.is-on { background: #f0fdf4; }
        .erp-chip.is-on:hover { background: #dcfce7; }
        .erp-chip.is-on .tik { opacity: 1; }
        .erp-chip.is-on .kode { color: #14532d; }
        .erp-mesin-clear {
            background: none; border: none; border-top: 1px solid var(--border-2);
            cursor: pointer; color: var(--c-danger); font-family: inherit;
            font-size: var(--erp-fs-sm); padding: 5px 6px; text-align: left;
        }
        .erp-mesin-clear:hover { background: var(--surface-3); }
        @media (pointer: coarse) {
            .erp-mesin-pick { max-height: 300px; }
        }

        /* ── Baris navigasi halaman di bawah grid ── */
        .erp-pg {
            flex: 0 0 auto; padding: 3px 8px;
            border-top: 1px solid var(--border-2); background: var(--surface);
        }
        .erp-pg .pagination { margin: 0; }
        .erp-ws .erp-pg .page-link { padding: 2px 8px; font-size: var(--erp-fs-sm); }


        /* ── Strip peringatan tipis di atas grid ── */
        .erp-warnstrip {
            flex: 0 0 auto; display: flex; align-items: center; gap: 7px;
            padding: 4px 9px; background: #fefce8;
            border-bottom: 1px solid #fde047;
            font-size: var(--erp-fs-sm, 11px); color: #713f12; line-height: 1.4;
        }
        .erp-warnstrip i { color: #ca8a04; flex-shrink: 0; }


        /* ══ Laci (input & catatan) ═════════════════════════════════
           Sebelumnya tiap label, isian, dan teks bantu memasang ukurannya
           sendiri — 0.92rem, 0.95rem, 0.88rem, 0.82rem, 0.75rem berbaur di
           satu panel. Skalanya sekarang satu tempat, memakai token yang sama
           dengan grid supaya laci dan halaman terasa satu keluarga.
        ══════════════════════════════════════════════════════════════ */
        .erp-drawer {
            --erp-fs:    13px;   /* isian di laci sedikit lebih besar dari grid:
                                    di sini orang mengetik, bukan memindai */
            --erp-fs-sm: 11px;
            --erp-ctl-h: 32px;
            font-size: var(--erp-fs);
        }
        @media (pointer: coarse) {
            .erp-drawer { --erp-fs: 14px; --erp-ctl-h: 44px; }
        }

        .erp-drawer-hd {
            display: flex; align-items: center; justify-content: space-between;
            padding: 10px 16px; background: var(--tb-bg); color: #fff; flex-shrink: 0;
            font-size: 13px; font-weight: 700; letter-spacing: 0.02em;
        }
        .erp-drawer-hd button {
            background: none; border: none; color: #fff;
            font-size: 15px; line-height: 1; cursor: pointer; padding: 0;
        }

        .erp-drawer .form-label {
            font-size: var(--erp-fs-sm); font-weight: 700;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: var(--text-3); margin-bottom: 3px;
        }
        .erp-drawer .form-control,
        .erp-drawer .form-select {
            font-size: var(--erp-fs); height: var(--erp-ctl-h); padding: 0 8px;
        }
        .erp-drawer textarea.form-control { height: auto; padding: 7px 8px; }
        .erp-drawer .btn { font-size: var(--erp-fs); }
        .erp-drawer .invalid-feedback,
        .erp-drawer .form-text,
        .erp-drawer .erp-meta { font-size: var(--erp-fs-sm); }
        .erp-drawer .alert { font-size: var(--erp-fs); }
        .erp-drawer .form-check-label { font-size: var(--erp-fs); }
        .erp-drawer .erp-note-box {
            font-size: var(--erp-fs); line-height: 1.55;
            white-space: pre-wrap; color: var(--text);
        }


        /* Halaman VCB menumpuk KPI, grafik, dan tabel di dalam .erp-scroll.
           Berbeda dari grid input yang menempel tepi, isi di sini butuh
           napas — jadi variannya diberi padding. */
        .erp-scroll-pad { padding: 10px; background: var(--bg); }
        .erp-scroll-pad .erp-panel { margin-bottom: 10px; }
        .erp-scroll-pad .erp-panel:last-child { margin-bottom: 0; }

        /* Form filter di dalam dock: tegak, satu kolom. */
        .erp-dock-form { display: flex; flex-direction: column; gap: 10px; }
        .erp-dock-body > div > .erp-dock-form > .erp-grp > b {
            font-size: var(--erp-fs-sm); font-weight: 700;
            letter-spacing: 0.08em; text-transform: uppercase; color: var(--text-3);
        }


        /* ══ Toast pesan singkat ═════════════════════════════════════
           Dulu pesan sukses dicetak sebagai alert setinggi ~46px di dalam
           aliran halaman, mendorong seluruh isi ke bawah — dan di halaman
           ruang kerja yang terkunci setinggi layar, dorongan itu memotong
           bagian bawah grid. Sekarang melayang (position: fixed) sehingga
           nol pengaruh ke tata letak, dan menghilang sendiri.

           Yang TIDAK dijadikan toast: daftar baris gagal import. Isinya
           perlu dibaca satu per satu, jadi tetap panel yang menetap. */
        .toast-stack {
            position: fixed;
            top: calc(var(--tb-h) + 10px);
            right: 14px;
            z-index: 1300;
            display: flex;
            flex-direction: column;
            gap: 6px;
            pointer-events: none;
            max-width: min(420px, calc(100vw - 28px));
        }
        .toast-msg {
            pointer-events: auto;
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 8px 10px;
            font-size: 13px;
            line-height: 1.35;
            border: 1px solid var(--border);
            border-left-width: 3px;
            background: var(--surface);
            color: var(--text);
            animation: toastIn 0.16s ease both;
        }
        .toast-msg.is-ok   { border-left-color: var(--c-success); }
        .toast-msg.is-bad  { border-left-color: var(--c-danger); }
        .toast-msg .ic     { flex: 0 0 auto; font-size: 14px; line-height: 1; }
        .toast-msg.is-ok  .ic { color: var(--c-success); }
        .toast-msg.is-bad .ic { color: var(--c-danger); }
        .toast-msg .txt    { flex: 1 1 auto; min-width: 0; }
        .toast-msg .x {
            flex: 0 0 auto; background: none; border: none; cursor: pointer;
            color: var(--text-4); font-size: 15px; line-height: 1; padding: 0 2px;
        }
        .toast-msg.pergi { animation: toastOut 0.22s ease both; }

        @keyframes toastIn  { from { opacity: 0; transform: translateY(-6px); } to { opacity: 1; transform: none; } }
        @keyframes toastOut { to { opacity: 0; transform: translateY(-6px); } }

        @media (prefers-reduced-motion: reduce) {
            .toast-msg, .toast-msg.pergi { animation: none; }
        }

        /* ══ Notification Bell ══════════════════════════════════ */
        .notif-btn { background: none; border: none; color: #9ca3af; font-size: 1.05rem; position: relative; cursor: pointer; padding: 5px 7px; border-radius: 4px; display:flex;align-items:center; }
        .notif-btn:hover { color: #fff; background: rgba(255,255,255,.08); }
        .notif-badge { position: absolute; top: 2px; right: 2px; background: #ef4444; color: #fff; font-size: 0.6rem; font-weight: 700; border-radius: 999px; padding: 1px 4px; min-width: 15px; text-align: center; line-height: 1.4; }
        .notif-dropdown { position: absolute; right: 0; top: calc(100% + 6px); width: 310px; background: #fff; border: 1px solid var(--border); border-radius: 4px; box-shadow: 0 6px 20px rgba(0,0,0,.14); z-index: 1060; }
        .notif-dd-header { display: flex; justify-content: space-between; align-items: center; padding: 9px 13px; border-bottom: 1px solid var(--border); font-size: 0.82rem; font-weight: 700; color: var(--text); }
        .notif-read-all { font-size: 0.75rem; color: #3b82f6; background: none; border: none; cursor: pointer; padding: 0; }
        .notif-read-all:hover { text-decoration: underline; }
        .notif-dd-body { max-height: 360px; overflow-y: auto; }
        .notif-item { display: flex; gap: 9px; padding: 9px 13px; cursor: pointer; border-bottom: 1px solid #f3f4f6; transition: background .1s; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item:hover { background: #f9fafb; }
        .notif-item.is-unread { background: #fefce8; }
        .notif-item.is-unread:hover { background: #fef9c3; }
        .notif-item-icon { font-size: 0.95rem; flex-shrink: 0; padding-top: 2px; }
        .notif-item-text { font-size: 0.81rem; color: var(--text); line-height: 1.4; }
        .notif-item-time { font-size: 0.73rem; color: var(--text-2); margin-top: 2px; }
        .notif-empty { padding: 22px 13px; text-align: center; color: var(--text-2); font-size: 0.82rem; }
        .notif-highlight { background: #dbeafe !important; }
        .notif-highlight td { background: #dbeafe !important; }
    </style>
    @stack('styles')
</head>
<body>

{{-- Progress bar --}}
<div id="page-progress"></div>

{{-- Mobile overlay --}}
<div id="sb-overlay" onclick="sbCloseMobile()"></div>

{{-- ═══ TOPBAR ════════════════════════════════════════════════════════ --}}
<header id="topbar">
    {{-- Hamburger / Pin toggle --}}
    <button id="sb-toggle" onclick="sbToggle()" title="Toggle Sidebar (Ctrl+B)">
        <i class="bi bi-list"></i>
    </button>

    {{-- Brand --}}
    <div class="app-brand">
        <div class="brand-mark"><img src="{{ asset('logo.png') }}" alt="Logo"></div>
        <span class="brand-name">OPTIMA</span>
        <span class="brand-tag">MES</span>
    </div>

    {{-- Page title --}}
    <div class="topbar-page">
        <i class="bi bi-chevron-right"></i>
        <strong>@yield('title', 'Dashboard')</strong>
    </div>

    {{-- Right side --}}
    <div class="topbar-right">
        {{-- Notification Bell --}}
        <div x-data="notifBell()" class="position-relative" style="display:flex;align-items:center">
            <button class="notif-btn" @click="open = !open" title="Notifikasi">
                <i class="bi bi-bell-fill"></i>
                <span class="notif-badge" x-show="count > 0" x-text="count" x-cloak></span>
            </button>
            <div class="notif-dropdown" x-show="open" x-cloak @click.outside="open = false">
                <div class="notif-dd-header">
                    <span>Notifikasi</span>
                    <button class="notif-read-all" x-show="count > 0" @click.stop="readAll()">Tandai Semua Dibaca</button>
                </div>
                <div class="notif-dd-body">
                    <template x-if="items.length === 0">
                        <div class="notif-empty">Tidak ada notifikasi.</div>
                    </template>
                    <template x-for="item in items" :key="item.id">
                        <div class="notif-item" :class="{'is-unread': !item.read_at}" @click="clickItem(item.id, item.url)">
                            <div class="notif-item-icon">
                                <i :class="item.type === 'note_submitted' ? 'bi bi-chat-left-text-fill text-warning' : 'bi bi-check-circle-fill text-success'"></i>
                            </div>
                            <div>
                                <div class="notif-item-text" x-text="item.text"></div>
                                <div class="notif-item-time" x-text="item.created_at"></div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        @php
            $u = auth()->user();
            $initial = strtoupper(substr($u->name ?? 'U', 0, 1));
        @endphp

        <div class="topbar-item">
            <i class="bi bi-calendar3"></i>
            <span>{{ now()->format('d M Y') }}</span>
        </div>

        <div class="topbar-item user-block" x-data="{ buka: false }" style="position:relative;cursor:pointer">
            <div @click="buka = ! buka" style="display:flex;align-items:center;gap:8px">
                <i class="bi bi-person-circle"></i>
                <div class="user-info">
                    <span class="name">{{ $u->name }}</span>
                    <span class="role">{{ str_replace('_', ' ', $u->role) }}</span>
                </div>
                <i class="bi bi-chevron-down" style="font-size:11px"></i>
            </div>

            <div x-show="buka" x-cloak @click.outside="buka = false"
                 style="position:absolute;right:0;top:calc(100% + 6px);min-width:190px;background:#fff;border:1px solid var(--border);border-radius:4px;box-shadow:0 6px 20px rgba(0,0,0,.14);z-index:1200">
                {{-- Route profil belum terdaftar di aplikasi ini (ProfileController
                     tak punya route terpasang) — guard Route::has supaya dropdown
                     tak menabrak RouteNotFoundException di setiap halaman. --}}
                @if(\Illuminate\Support\Facades\Route::has('profile.edit'))
                <a href="{{ route('profile.edit') }}" class="dropdown-item">
                    <i class="bi bi-person me-2"></i>Profil
                </a>
                @endif
                <form method="POST" action="{{ route('logout') }}" style="margin:0">
                    @csrf
                    <button type="submit" class="dropdown-item" style="width:100%;text-align:left;border:0;background:none">
                        <i class="bi bi-box-arrow-right me-2"></i>Logout
                    </button>
                </form>
            </div>
        </div>

        @if($u->isGuest())
        <div class="topbar-item" style="border:1px solid #4b5563;padding:2px 8px;font-size:0.72rem;letter-spacing:0.04em;color:#e5e7eb">
            MODE TAMU &mdash; READ ONLY
        </div>
        @endif
    </div>
</header>

@include('partials.sidebar')

{{-- ═══ MAIN ══════════════════════════════════════════════════════════ --}}
<main id="main" class="@yield('main_mod')">
    @yield('content')
</main>

{{-- Di LUAR #main: halaman ruang kerja memakai overflow:hidden, dan toast
     di dalamnya akan terpotong. --}}
@php
    $_toasts = array_filter([
        ['ok', session('success')],
        ['ok', session('import_success')],
        ['bad', session('error')],
    ], fn ($t) => filled($t[1]));
@endphp
@if($_toasts !== [])
<div class="toast-stack" id="toastStack">
    @foreach($_toasts as [$jenis, $pesan])
        <div class="toast-msg is-{{ $jenis }}" role="status">
            <i class="ic bi {{ $jenis === 'ok' ? 'bi-check-circle-fill' : 'bi-x-octagon-fill' }}"></i>
            <span class="txt">{{ $pesan }}</span>
            <button type="button" class="x" aria-label="Tutup">&times;</button>
        </div>
    @endforeach
</div>
@endif

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js" integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js" integrity="sha384-NrKB+u6Ts6AtkIhwPixiKTzgSKNblyhlk0Sohlgar9UHUBzai/sgnNNWWd291xqt" crossorigin="anonymous"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js" integrity="sha384-y49Zu59jZHJL/PLKgZPv3k2WI9c0Yp3pWB76V8OBVCb0QBKS8l4Ff3YslzHVX76Y" crossorigin="anonymous"></script>
<script>
    /* Registered globally but OFF by default — only charts that explicitly
       set plugins.datalabels.display=true (maintenance MTBF/MTTR/Availability) show labels. */
    if (window.Chart && window.ChartDataLabels) {
        Chart.register(ChartDataLabels);
        Chart.defaults.set('plugins.datalabels', { display: false });
    }
</script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" integrity="sha384-l8f0VcPi/M1iHPv8egOnY/15TDwqgbOR1anMIJWvU6nLRgZVLTLSaNqi/TOoT5Fh" crossorigin="anonymous" defer></script>

<script>
/* ════════════════════════════════════════════════════════════════════
   OPTIMA Sidebar & Page Transition Controller
   ════════════════════════════════════════════════════════════════════ */

const SB_KEY = 'optima_sb_open';
const $body  = document.body;
const $sb    = document.getElementById('sidebar');
const $main  = document.getElementById('main');
const $prog  = document.getElementById('page-progress');
const isMobile = () => window.innerWidth < 768;

/* ─ Notification Bell ───────────────────────────────────────── */
function notifBell() {
    return {
        count: 0,
        items: [],
        open: false,
        csrfToken: document.querySelector('meta[name="csrf-token"]')?.content ?? '',
        init() {
            this.poll();
            setInterval(() => this.poll(), 30000);
        },
        async poll() {
            try {
                const r = await fetch('/notifications/poll');
                const d = await r.json();
                this.count = d.count ?? 0;
                this.items = d.items ?? [];
            } catch (e) { /* silent — network hiccup */ }
        },
        async clickItem(id, url) {
            try {
                await fetch('/notifications/' + id + '/read', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
            } catch (e) { /* ignore */ }
            window.location.href = url;
        },
        async readAll() {
            try {
                await fetch('/notifications/read-all', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrfToken, 'Accept': 'application/json' },
                });
                this.count = 0;
                this.items = this.items.map(i => ({ ...i, read_at: 'now' }));
            } catch (e) { /* ignore */ }
        },
    };
}

/* ─ Sidebar accordion (single-open + ingat via localStorage) ─
   Urutan keadaan awal: grup halaman aktif → grup terakhir dari
   localStorage → semua tertutup. Default lama 'produksi' dibuang: untuk
   staff_maintenance itu grup yang tidak dia punya. */
function navGroups(active = '') {
    return {
        open: active || localStorage.getItem('optima_nav_group') || null,
        isOpen(g) { return this.open === g; },
        toggle(g) {
            this.open = this.open === g ? null : g;
            localStorage.setItem('optima_nav_group', this.open || '');
        },
    };
}

/* ─ Restore open state on desktop ───────────────────────────── */
if (!isMobile() && localStorage.getItem(SB_KEY) === '1') {
    $body.classList.add('sb-pinned');
}

/* ─ Toggle: hidden (0px) ↔ expanded (220px) ─────────────────── */
/* Buka-tutup dock filter untuk halaman tanpa Alpine (VCB, daftar NG).
   Ditaruh di layout supaya tidak disalin lagi per halaman — salinan
   .fbar-* yang dulu menyimpang berawal persis dari kebiasaan itu. */
/* Toast hilang sendiri setelah beberapa detik. Pesan sukses tidak perlu
   ditutup manual — yang perlu dibaca lama (daftar baris gagal import)
   sengaja bukan toast. */
(function () {
    var stack = document.getElementById('toastStack');
    if (!stack) return;

    function buang(el) {
        if (!el || el.classList.contains('pergi')) return;
        el.classList.add('pergi');
        setTimeout(function () { el.remove(); }, 220);
    }

    Array.prototype.forEach.call(stack.querySelectorAll('.toast-msg'), function (el, i) {
        el.querySelector('.x').addEventListener('click', function () { buang(el); });

        /* Ditahan selama kursor di atasnya: pesan panjang tidak keburu hilang
           saat sedang dibaca. */
        var timer = setTimeout(function () { buang(el); }, 4000 + i * 400);
        el.addEventListener('mouseenter', function () { clearTimeout(timer); });
        el.addEventListener('mouseleave', function () {
            timer = setTimeout(function () { buang(el); }, 1200);
        });
    });
})();

/* ══ Komponen ketik-cari bersama ═══════════════════════════════════
   Dipakai dock filter di beberapa halaman. Ditaruh di layout, BUKAN
   disalin per halaman: salinan .fbar-* dan toggleDock() yang dulu
   dikembarkan sudah membuktikan bahwa salinan pasti menyimpang.

   filterSearchComp — satu kotak ketik-cari sederhana.
   partGroupComp    — Part + Proses + Nomor Part dalam satu ikatan: memilih
                      part mengisi nomornya dan mengosongkan proses.
                      Halaman yang memakainya WAJIB menyediakan _partsTA,
                      _prosesTA, dan _partsFullData.
══════════════════════════════════════════════════════════════════ */
/* ══ taField — ketik-cari dengan navigasi papan tik ═══════════════
   Satu definisi. Sebelumnya ada di 18 berkas view dalam lima varian teks,
   dan varian itu sudah menyimpang: tiap perbaikan bug harus dikerjakan
   lima kali, dan sejarah repo ini menunjukkan yang begitu selalu
   ketinggalan di satu tempat.

   Kelima varian ternyata hanya DUA perilaku, dan bedanya cuma closeDd():

     Kelompok input (12 berkas)
         mengosongkan val+query kalau val terisi tapi teksnya sudah
         tidak cocok lagi; teks tanpa pilihan DIBIARKAN menggantung.

     Kelompok analitik (6 berkas)
         mengosongkan query kalau tidak ada yang dipilih; val basi yang
         teksnya sudah diubah DIBIARKAN.

   Yang dipakai di sini menutup dua-duanya: kotak dikosongkan kecuali ia
   benar-benar memegang satu pilihan yang teksnya masih cocok. Aman untuk
   keduanya — val basi tidak pernah ikut terkirim, dan teks menggantung
   tidak lagi memberi kesan ada yang terpilih.

   Keenam halaman analitik memanggil taField() tanpa initText/initVal,
   jadi bagi mereka perilakunya persis sama seperti sebelumnya.
══════════════════════════════════════════════════════════════════ */
function taField(items, initText = '', initVal = null) {
    return {
        items,
        query: initText,
        val:   initVal,
        open:  false,
        idx:   -1,

        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },

        openDd()  { this.open = true; this.idx = -1; },

        closeDd() {
            setTimeout(() => {
                this.open = false;
                const terpilih = this.val !== null && this.val !== ''
                    && this.items.some(i => i.v == this.val && i.t === this.query);
                if (! terpilih) { this.val = null; this.query = ''; }
            }, 180);
        },

        onInput()  { this.val = null; this.open = true; this.idx = -1; },
        pick(item) { this.query = item.t; this.val = item.v; this.open = false; this.idx = -1; },

        onKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.open = true;
                this.idx = Math.min(this.idx + 1, this.filtered.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.idx = Math.max(this.idx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.idx >= 0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]);
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },
    };
}

function filterSearchComp(items, initVal, initLabel, onPick) {
    return {
        items,
        query: initLabel || '',
        val:   (initVal !== undefined && initVal !== null && initVal !== 0 && initVal !== '0') ? initVal : '',
        open:  false,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 40);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        pick(item) {
            this.val = item.v; this.query = item.t; this.open = false;
            if (typeof onPick === 'function') onPick(item);
        },
        clear()   { this.val = ''; this.query = ''; },
        onInput() { this.val = ''; this.open = true; },
    };
}

function partGroupComp(initPartId, initPartLabel, initProsesId, initProsesLabel) {
    return {
        partQuery:   initPartLabel   || '',
        partVal:     initPartId      || '',
        partOpen:    false,
        prosesQuery: initProsesLabel || '',
        prosesVal:   initProsesId    || '',
        prosesOpen:  false,
        get partNo() {
            const v = this.partVal;
            if (!v) return '';
            const p = _partsTA.find(x => x.v == v);
            return p ? (p.pno || '') : '';
        },
        get prosesItems() {
            const v = this.partVal;
            if (!v) return _prosesTA;
            const part = _partsFullData.find(x => x.id == v);
            if (!part || !part.proses || !part.proses.length) return _prosesTA;
            return part.proses.map(pr => ({v: pr.id, t: pr.label}));
        },
        get filteredParts() {
            const q = this.partQuery.trim().toLowerCase();
            if (!q) return _partsTA.slice(0, 40);
            return _partsTA.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        get filteredProses() {
            const items = this.prosesItems;
            const q = this.prosesQuery.trim().toLowerCase();
            if (!q) return items.slice(0, 40);
            return items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        pickPart(item) {
            this.partVal     = item.v;
            this.partQuery   = item.t;
            this.partOpen    = false;
            this.prosesVal   = '';
            this.prosesQuery = '';
        },
    };
}

function erpToggleDock(dockId, btnId) {
    const d = document.getElementById(dockId);
    if (!d) return;
    const on = d.style.display === 'none';
    d.style.display = on ? '' : 'none';
    const b = document.getElementById(btnId);
    if (b) b.classList.toggle('is-on', on);
}

/**
 * Menyaring daftar orang di sebuah <select> mengikuti tanggal mulai yang dipilih.
 *
 * Halaman menyediakan datanya: tiap <option> membawa data-nonaktif="YYYY-MM-DD"
 * (kosong = masih aktif). Kembaliannya fungsi saring(wajibIkut = null) yang
 * membangun ulang <select>-nya. Membangun ulang, BUKAN display:none pada
 * <option> — perilaku display:none di <option> tidak seragam antar peramban.
 *
 * wajibIkut (opsional): id karyawan yang harus tetap muncul di opsi walau
 * tanggal_nonaktif-nya jatuh sebelum "dari". Dipakai saat orang itu dijangkau
 * dengan mengklik barisnya sendiri di tabel ringkasan Mode 1 yang tak
 * tersaring — dia relevan dengan drill-down ini by definition, dan menjatuhkan
 * dia dari <select> akan mem-mentok-kan drill-down itu sendiri.
 *
 * Daftar lengkapnya di-snapshot sekali saat dipasang, karena pembangunan ulang
 * yang pertama akan menghapus sumbernya dari DOM.
 */
function saringOrangNonaktif(selectId, dateFromId, noteId) {
    const sel = document.getElementById(selectId);
    const semua = Array.from(sel.querySelectorAll('option'))
        .filter(o => o.value !== '')
        .map(o => ({ id: o.value, nama: o.textContent, nonaktif: o.dataset.nonaktif || '' }));
    const placeholder = sel.querySelector('option[value=""]');
    const placeholderHtml = placeholder ? placeholder.outerHTML : '';

    return function saring(wajibIkut = null) {
        const dari     = document.getElementById(dateFromId).value;
        const terpilih = sel.value;
        const note     = document.getElementById(noteId);

        // Tanggal ISO aman dibandingkan sebagai string.
        // nonaktif kosong = selalu tampil: kolom kosong tidak boleh menghilangkan orang.
        // wajibIkut: orang yang baru saja diklik dari ringkasan Mode 1 (yang
        // sengaja tak tersaring) harus tetap muncul di sini — dia relevan dengan
        // rentang ini by definition, walau tanggal_nonaktif-nya jatuh sebelum "dari".
        const lolos = semua.filter(o => !o.nonaktif || o.nonaktif >= dari || o.id === String(wajibIkut));

        sel.innerHTML = placeholderHtml;
        lolos.forEach(o => {
            const opt = document.createElement('option');
            opt.value = o.id;
            opt.textContent = o.nama;
            opt.dataset.nonaktif = o.nonaktif;
            sel.appendChild(opt);
        });

        if (terpilih && lolos.some(o => o.id === terpilih)) {
            sel.value = terpilih;
            if (note) note.textContent = '';

            return;
        }

        if (terpilih) {
            // Mengosongkan diam-diam akan membuat grafik menampilkan orang lain
            // tanpa penjelasan.
            const hilang = semua.find(o => o.id === terpilih);
            if (note) note.textContent = hilang ? `${hilang.nama} tidak aktif pada periode ini.` : '';
            sel.value = '';
        }
    };
}

/* ══════════════════════════════════════════════════════════════════
   partPicker — ketik-cari part yang sekaligus mengisi Part No, Part
   Name, dan daftar prosesnya.

   Beda dari taField: taField hanya mengembalikan satu nilai, sementara
   yang ini punya muatan turunan (partNo/partName/prosesList) yang harus
   ikut berubah tiap kali pilihan berganti — dan ikut dikosongkan saat
   pilihan dibatalkan, kalau tidak Part No lama menggantung di layar
   sementara val sudah null.

   Data masuk lewat argumen, bukan dibaca dari konstanta global halaman,
   supaya halaman mana pun bisa memakainya (lihat taField di atas).
   ══════════════════════════════════════════════════════════════════ */
function partPicker(items, partsData) {
    return {
        items, partsData,
        query: '', val: null, open: false, idx: -1,
        partNo: '', partName: '', prosesList: [],

        get tersaring() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pilih(item) {
            this.query = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part = this.partsData.find(p => p.id == item.v);
            this.partNo     = part ? part.part_no : '';
            this.partName   = part ? part.part_name : '';
            this.prosesList = part ? part.proses : [];
        },
        tutup() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = ''; this.partNo = ''; this.partName = '';
                    this.prosesList = [];
                }
            }, 180);
        },
        tombol(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx + 1, this.tersaring.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.idx = Math.max(this.idx - 1, 0); }
            else if (e.key === 'Enter') { e.preventDefault(); if (this.idx >= 0 && this.tersaring[this.idx]) this.pilih(this.tersaring[this.idx]); }
            else if (e.key === 'Escape') { this.open = false; }
        },
    };
}

/* ══════════════════════════════════════════════════════════════════
   batchDraft — kerangka halaman input batch: form satu baris di atas,
   tabel draft di bawah, satu SUBMIT mengirim semuanya.

   Yang ADA di sini adalah yang nol hubungannya dengan modul mana pun:
   penyimpanan draft, pemulihan setelah submit ditolak, pembacaan form,
   dan penjaga pengiriman ganda. Empat hal itu justru yang paling mahal
   kalau salah, dan mode gagalnya senyap — draft hilang tidak melempar
   error, cuma menyisakan tabel kosong yang operator kira memang belum
   diisi. Karena itu dia hidup satu kali di sini, bukan disalin per modul.

   Yang TIDAK ada di sini: field apa yang dikumpulkan, mana yang wajib,
   pemeriksaan antar-baris, dan bentuk payload. Itu semua masuk lewat cfg.

   Kunci localStorage (cfg.kunci, cfg.kunciBackup) sengaja disuplai UTUH oleh
   tiap modul, bukan dirakit di sini dari potongan (mis. id pengguna). Layout
   ini murni JS generik tanpa akses ke Blade/auth, dan merakit kunci di JS
   runtime membuat literalnya berhenti muncul di HTML server — dua test
   pre-existing yang menjangkar ke literal kunci itu butuh teksnya utuh di
   response mentah. Nilai kedua kunci HARUS sama persis dengan yang dihapus
   halaman daftar modul itu (lihat komentar di batal() dan pindahkanKeBackup()
   di bawah) — kunci yang tidak cocok berarti draft/backup jadi yatim,
   tersimpan di browser tapi tidak pernah lagi dibaca atau dibersihkan. */
/* ══════════════════════════════════════════════════════════════════
   pluginNamaDalamBatang — plugin Chart.js yang menggambar label kategori
   TEGAK DI DALAM batangnya sendiri, dibaca dari bawah ke atas.

   Dipakai grafik VCB yang sumbu-X-nya berisi nama orang. Dengan 30 nama,
   tick horizontal saling tabrakan lalu Chart.js memiringkannya: label
   miring memakan tinggi plot sekaligus menyisakan satu nama menggantung
   sendirian di kiri bawah. Menempelkan nama pada batangnya juga menghapus
   keharusan mata melompat ke sumbu untuk mencocokkan urutan.

   Pemakainya WAJIB mematikan tick sumbu-X (`x.ticks.display = false`),
   kalau tidak namanya tergambar dua kali.
   ══════════════════════════════════════════════════════════════════ */
function warnaSeri(ds, i) {
    const ambil = (v) => Array.isArray(v) ? v[i] : v;

    return ambil(ds.borderColor) ?? ambil(ds.backgroundColor) ?? '#374151';
}

function pluginNamaDalamBatang(opsi = {}) {
    /* Warna teks di dalam batang TIDAK bisa ditebak dari datanya: grafik VCB
       memakai isian pucat (warna + '22') sehingga teks gelap terbaca, sementara
       grafik Dandori dan MTC memakai isian gelap pekat yang menelan teks gelap.
       Pemanggil yang tahu isiannya menentukan — menebak di sini berarti satu
       halaman selalu salah. */
    const warnaDalam = opsi.warnaDalam ?? null;
    const warnaLuar = opsi.warnaLuar ?? '#6b7280';

    return {
        id: 'namaDalamBatang',
        afterDatasetsDraw(chart) {
            const { ctx, chartArea } = chart;
            const meta = chart.getDatasetMeta(0);
            const ds = chart.data.datasets[0];

            /* Lebar batang menentukan muat-tidaknya huruf. Di bawah 9px
               teksnya tidak terbaca, jadi lebih baik tidak digambar sama
               sekali daripada menumpuk jadi noda. */
            const lebarBatang = meta.data.length ? meta.data[0].width : 0;
            if (lebarBatang < 9) return;

            const ukuran = Math.min(11, Math.max(8, Math.round(lebarBatang * 0.6)));
            ctx.save();
            ctx.font = `600 ${ukuran}px Arial`;

            meta.data.forEach((bar, i) => {
                const nama = String(chart.data.labels[i] ?? '');
                if (! nama) return;

                const tinggiBatang = chartArea.bottom - bar.y;
                const lebarTeks = ctx.measureText(nama).width;
                /* 10px sisa: 6px napas di pangkal + 4px supaya huruf terakhir
                   tidak menyentuh angka persen di puncak batang. */
                const muat = lebarTeks + 10 <= tinggiBatang;

                ctx.save();
                if (muat) {
                    ctx.translate(bar.x, chartArea.bottom - 6);
                    /* borderColor bisa berupa array per-batang ATAU satu string
                       untuk seluruh dataset; sebagian dataset malah cuma punya
                       backgroundColor. Ketiganya harus dilayani, kalau tidak
                       fillStyle jadi undefined dan teksnya hitam bawaan. */
                    ctx.fillStyle = warnaDalam ?? warnaSeri(ds, i);
                } else {
                    /* Batang pendek tidak muat menampung namanya. Nama
                       digambar DI ATAS batang, tetap tegak — memaksanya masuk
                       berarti nama terpotong, dan nama orang yang terpotong
                       tidak berguna bagi siapa pun. Angka persen sudah
                       menempati ruang tepat di atas puncak, jadi nama mulai
                       di atasnya. */
                    ctx.translate(bar.x, bar.y - 20);
                    ctx.fillStyle = warnaLuar;
                }
                ctx.rotate(-Math.PI / 2);
                ctx.textAlign = 'left';
                ctx.textBaseline = 'middle';
                ctx.fillText(nama, 0, 0);
                ctx.restore();
            });

            ctx.restore();
        }
    };
}

function batchDraft(cfg) {
    /* Kunci cfg yang hilang gagal SENYAP kalau tidak dijaga di sini — mis.
       kunciBackup yang lupa dioper membuat KUNCI_BACKUP bernilai undefined,
       dan localStorage.setItem(undefined, …) tidak melempar apa-apa, ia
       menulis ke kunci literal "undefined". Lempar galat jelas SEKARANG,
       saat modul pertama kali dipasang, daripada membiarkan operator
       menemukan draft yang tidak pernah tersimpan/terhapus dengan benar. */
    /* galatServer ikut wajib walau punya default `|| []` di bawah: modul yang
       lupa mengopernya mendapat barisGagal kosong SELAMANYA, jadi setelah
       server menolak batch operator melihat daftar galatnya tapi tidak ada
       satu baris pun yang ditandai merah — dia harus mencari baris yang salah
       di antara 50 baris dengan membaca teks. Diam, dan cuma terasa persis
       saat operator paling butuh bantuan. */
    for (const k of ['kunci', 'kunciBackup', 'formId', 'indexUrl', 'kumpulkan', 'wajib', 'payload', 'galatServer']) {
        if (cfg[k] === undefined) throw new Error(`batchDraft: cfg.${k} wajib diisi.`);
    }

    /* Kunci dipisah per pengguna: komputer di lantai produksi dipakai
       bergantian, dan kunci bersama membuat draft operator A muncul di
       layar operator B. Modul pemanggil yang menyusun id pengguna ke dalam
       cfg.kunci (lihat konfigLhp() di create.blade.php). */
    const KUNCI = cfg.kunci;

    /* Kunci cadangan: SUBMIT DRAFT adalah POST form biasa yang berakhir di
       redirect server, jadi tidak ada kesempatan JS menghapus KUNCI tepat
       saat sukses. Sebaliknya, submit memindahkan isi KUNCI ke sini dulu
       (lihat pindahkanKeBackup()) lalu mengosongkan KUNCI.
       Draft di sini hanya hidup di browser, jadi hanya boleh dibuang kalau
       server sudah MEMBUKTIKAN baris-barisnya tersimpan — bukan sekadar
       ketiadaan galat validasi di halaman ini. Bukti itu adalah flash
       batch_tersimpan yang dibaca dan dieksekusi di halaman daftar:
       halaman daftar-lah yang menghapus KUNCI_BACKUP ini.
       Kalau operator kembali ke SINI dan backup masih ada — baik karena
       galat validasi, 419 sesi habis, 500 di server, maupun jaringan
       putus sebelum POST-nya mendarat — submit itu belum terbukti sukses,
       jadi mulai() memulihkannya, bukan membuangnya. */
    const KUNCI_BACKUP = cfg.kunciBackup;
    let seq = 0;

    return {
        /* Spread cfg.metode di PALING AWAL supaya kerangka selalu menang atas
           cfg.metode — baik state maupun method. Dalam object literal JS,
           kunci yang datang belakangan menang atas kunci yang sama dari
           sebaran sebelumnya. Empat modul akan memakai batchDraft(); kalau
           cfg.metode modul kebetulan mendefinisikan nama yang sama dengan
           state atau method kerangka (mis. "rows", "pesan", "mulai", atau
           "hapus"), kerangka WAJIB tetap yang menang — bukan tertimpa senyap
           oleh cfg.metode. */
        ...(cfg.metode || {}),

        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],

        mulai() {
            /* Halaman batch yang dipulihkan dari bfcache (tombol Back setelah
               submit sukses) masih menampilkan baris-baris yang SUDAH tersimpan
               di server — x-init "mulai()" tidak jalan lagi karena Alpine tidak
               di-reinit, dan tombol SUBMIT tetap aktif. Mengirim ulang baris
               yang sama menulis data dobel; tidak ada unique constraint yang
               mencegahnya. Muat ulang supaya mulai() benar-benar jalan lagi dan
               memutuskan nasib draft dari kondisi server yang sebenarnya.

               Didaftarkan DI SINI, bukan di lingkup layout: layout dimuat semua
               halaman, dan memaksa reload bfcache di halaman yang tidak punya
               draft adalah perubahan perilaku yang tidak diminta siapa pun.
               Konsekuensinya, penjaga ini baru aktif setelah Alpine boot dan
               memanggil mulai() lewat x-init — beda dari sebelum ekstraksi,
               saat listener ini didaftarkan di parse-time script halaman. */
            window.addEventListener('pageshow', (e) => {
                if (e.persisted) window.location.reload();
            });

            /* Selesaikan nasib KUNCI_BACKUP (kalau ada) SEBELUM membaca KUNCI,
               supaya restorasi normal di bawah otomatis mengambil hasilnya.
               Sampai di SINI berarti halaman daftar belum sempat membuktikan
               sukses (lihat komentar di deklarasi KUNCI_BACKUP) — jadi backup
               SELALU dipulihkan, tanpa syarat ada/tidaknya galat validasi. */
            try {
                const backupMentah = localStorage.getItem(KUNCI_BACKUP);
                if (backupMentah !== null) {
                    localStorage.setItem(KUNCI, backupMentah);
                    localStorage.removeItem(KUNCI_BACKUP);
                }
            } catch (e) {
                // localStorage tak bisa dibaca/ditulis; lewati saja.
            }

            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah) {
                    this.rows = JSON.parse(mentah).map(r => ({ ...r, uid: seq++ }));
                    if (this.rows.length) this.pesan = `${this.rows.length} baris draft dipulihkan.`;
                }
            } catch (e) {
                localStorage.removeItem(KUNCI);
            }
            this.$watch('rows', () => localStorage.setItem(KUNCI, JSON.stringify(this.rows)));

            /* Server menolak batch → tandai barisnya, draft JANGAN dibuang. */
            this.barisGagal = (cfg.galatServer || [])
                .map(k => (k.match(/^rows\.(\d+)\./) || [])[1])
                .filter(v => v !== undefined)
                .map(Number);

            /* Modul yang bentuk formnya bergantung pada isi draft menyesuaikan
               di sini. Breakdown memakainya untuk melanjutkan nomor urut:
               server merender nomor berikutnya berdasarkan DATABASE, sementara
               draft yang baru dipulihkan mungkin sudah memakai beberapa nomor
               sesudahnya. Tanpa kail ini baris berikutnya memakai nomor yang
               sudah terpakai di draft, dan seluruh batch ditolak sementara
               form di layar terlihat benar.

               Modul tidak bisa menambal sendiri: cfg.metode disebar paling
               awal, jadi mulai() tidak bisa dibungkus dari luar. */
            if (cfg.setelahPulih) cfg.setelahPulih(this);
        },

        form() { return document.getElementById(cfg.formId); },

        nilai(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            return el ? el.value : '';
        },

        teksTerpilih(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            if (!el || el.tagName !== 'SELECT') return '';
            return el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : '';
        },

        blok(kelas) {
            const el = this.form().querySelector(kelas);
            return el ? Alpine.$data(el) : null;
        },

        detik(jam) {
            const [h, m] = (jam || '0:0').split(':').map(Number);
            return (h * 3600) + (m * 60);
        },

        durasiDetik(mulai, selesai) {
            const a = this.detik(mulai), b = this.detik(selesai);
            return b >= a ? b - a : (86400 - a) + b;
        },

        simpanKeDraft() {
            this.galat = '';

            const calon = { uid: seq++, ...cfg.kumpulkan(this) };

            for (const [kunci, label] of Object.entries(cfg.wajib)) {
                if (!calon[kunci]) { this.galat = `${label} wajib diisi.`; return; }
            }

            const galat = cfg.periksa ? cfg.periksa(calon, this.rows, this) : null;
            if (galat) { this.galat = galat; return; }

            if (cfg.pratinjau) cfg.pratinjau(calon, this);

            this.rows.push(calon);
            this.pesan = '';
            this.barisGagal = [];
            this.resetSebagian();
        },

        /* Field yang TIDAK disebut cfg.resetField sengaja dipertahankan:
           biasanya tanggal, shift, dan orang — ketiganya konstan sepanjang
           satu sesi kerja, dan mengetik ulang tiap baris bikin operator
           berhenti memakai halaman ini. */
        resetSebagian() {
            const f = this.form();
            (cfg.resetField || []).forEach(n => {
                const el = f.querySelector(`[name="${n}"]`);
                if (el) el.value = '';
            });

            if (cfg.resetLain) cfg.resetLain(this);
        },

        hapus(i) { this.rows.splice(i, 1); this.barisGagal = []; },

        payloadRows() { return this.rows.map(r => cfg.payload(r)); },

        batal() {
            if (this.rows.length && !confirm(`Buang ${this.rows.length} baris draft yang belum dikirim?`)) return;
            localStorage.removeItem(KUNCI);
            window.location = cfg.indexUrl;
        },

        /* Dipasang di @submit form batch. Form itu POST biasa (bukan
           @submit.prevent) — begitu handler ini selesai, browser lanjut
           mengirim form dan halaman ini hilang sebelum JS lain sempat jalan.
           Jadi draft TIDAK bisa langsung dihapus di sini: kalau server nanti
           menolak batch (galat validasi), operator akan diarahkan balik ke
           halaman ini dan berhak mendapatkan ketikannya kembali. Sebagai
           gantinya draft dipindah ke KUNCI_BACKUP dan KUNCI dikosongkan;
           mulai() yang membereskan nasib akhirnya saat halaman dibuka lagi. */
        pindahkanKeBackup() {
            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah !== null) {
                    /* Kalau setItem(KUNCI_BACKUP) di bawah gagal (mis. kuota
                       localStorage penuh), KUNCI tetap WAJIB dikosongkan di
                       finally — kalau tidak, draft yang baru saja berhasil
                       disimpan ke server akan ditawarkan lagi ke operator dan
                       terkirim dobel. */
                    try {
                        localStorage.setItem(KUNCI_BACKUP, mentah);
                    } finally {
                        localStorage.removeItem(KUNCI);
                    }
                }
            } catch (e) {
                // localStorage tak tersedia; biarkan submit tetap jalan apa adanya.
            }
        },
    };
}

function sbToggle() {
    if (isMobile()) {
        $body.classList.toggle('sb-mobile-open');
        return;
    }
    const open = $body.classList.toggle('sb-pinned');
    localStorage.setItem(SB_KEY, open ? '1' : '0');
}

function sbCloseMobile() {
    $body.classList.remove('sb-mobile-open');
}

/* ─ Resize handler ──────────────────────────────────────────── */
window.addEventListener('resize', () => {
    if (!isMobile()) {
        $body.classList.remove('sb-mobile-open');
        if (localStorage.getItem(SB_KEY) === '1') {
            $body.classList.add('sb-pinned');
        }
    }
});

/* ─ Keyboard: Ctrl+B ────────────────────────────────────────── */
document.addEventListener('keydown', e => {
    if (e.ctrlKey && e.key.toLowerCase() === 'b') {
        e.preventDefault();
        sbToggle();
    }
});

/* ─ Page transitions ────────────────────────────────────────── */
window.addEventListener('load', () => {
    $prog.style.width = '100%';
    setTimeout(() => $prog.style.opacity = '0', 250);
});

document.addEventListener('click', e => {
    const link = e.target.closest('a[href]');
    if (!link) return;
    const href = link.getAttribute('href');
    if (!href || href.startsWith('#') || href.startsWith('javascript') ||
        href.startsWith('mailto') || link.target === '_blank' ||
        link.hasAttribute('download') || link.closest('form')) return;

    try {
        const u = new URL(href, location.origin);
        if (u.origin !== location.origin) return;
        if (u.pathname === location.pathname && u.search === location.search) return;

        e.preventDefault();
        $prog.style.opacity = '1';
        $prog.style.width = '70%';
        setTimeout(() => location.href = href, 130);
    } catch (_) {}
});

/* ─ Form submission progress ────────────────────────────────── */
document.querySelectorAll('form').forEach(form => {
    form.addEventListener('submit', () => {
        $prog.style.opacity = '1';
        $prog.style.width = '70%';
    });
});
</script>

{{-- ══ Sticky freeze: hitung offset kiri tiap kolom dari lebar NYATA header cell ══
     getBoundingClientRect().width bersifat sub-pixel & scroll-independent → offset
     selalu pas, tidak ada gap. Berlaku untuk SEMUA tabel ber-class .tbl-freeze. --}}
<script>
(function () {
    function syncFreeze(table) {
        if (!table.tHead || !table.tHead.rows.length) return;
        var cells = table.tHead.rows[0].querySelectorAll('th.sc');
        var acc = 0;
        cells.forEach(function (cell, i) {
            table.style.setProperty('--sc' + (i + 1), acc + 'px');
            acc += cell.getBoundingClientRect().width;
        });
    }
    function syncAll() {
        document.querySelectorAll('table.tbl-freeze').forEach(syncFreeze);
    }

    syncAll();
    window.addEventListener('load', syncAll);   // re-hitung setelah font/layout final

    var raf = null;
    window.addEventListener('resize', function () {
        if (raf) cancelAnimationFrame(raf);
        raf = requestAnimationFrame(syncAll);
    });

    if (window.ResizeObserver) {
        document.querySelectorAll('table.tbl-freeze').forEach(function (t) {
            new ResizeObserver(function () { syncFreeze(t); }).observe(t);
        });
    }
})();
</script>

@stack('scripts')

</body>
</html>
