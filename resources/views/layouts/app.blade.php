<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>OPTIMA &mdash; @yield('title', 'Dashboard')</title>
    <link rel="icon" type="image/png" href="{{ asset('logo.png') }}">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
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
            font-size: 13px;
            line-height: 1.45;
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
            font-size: 13px;
            font-weight: 700;
            color: #f3f4f6;
            letter-spacing: 0.5px;
        }
        .brand-tag {
            font-size: 9.5px;
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
            font-size: 12px;
            font-weight: 500;
        }
        .topbar-page i { font-size: 11px; color: #6b7280; }
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
            font-size: 12px;
        }
        .topbar-item i { font-size: 13px; color: #9ca3af; }
        .topbar-item.user-block {
            gap: 9px;
        }
        .user-info {
            display: flex; flex-direction: column;
            line-height: 1.25;
            text-align: left;
        }
        .user-info .name {
            font-size: 12px;
            font-weight: 600;
            color: #f3f4f6;
        }
        .user-info .role {
            font-size: 10px;
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
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            display: flex; align-items: center; gap: 6px;
            transition: background var(--t), color var(--t);
        }
        .topbar-logout:hover { background: #374151; color: #fff; }
        .topbar-logout i { font-size: 13px; }

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
        #sidebar::-webkit-scrollbar { width: 0; }

        /* Expanded: pinned only */
        body.sb-pinned #sidebar { width: var(--sb-exp-w); }

        /* Main shifts when pinned */
        body.sb-pinned #main { margin-left: var(--sb-exp-w); }

        /* Sidebar sections */
        .nav-section { padding: 10px 0 4px; }
        .nav-section + .nav-section {
            border-top: 1px solid #374151;
            margin-top: 2px;
        }
        .nav-section-label {
            font-size: 9.5px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #6b7280;
            padding: 8px 16px 4px;
            display: block;
            white-space: nowrap;
        }

        .nav-item-link {
            display: flex;
            align-items: center;
            padding: 0 0 0 24px;
            height: 36px;
            color: var(--sb-text);
            text-decoration: none;
            font-size: 12.5px;
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
            font-size: 15px;
            width: 24px;
            text-align: center;
            margin-left: -8px;
            margin-right: 12px;
            transition: margin var(--t);
        }
        .nav-text {
            white-space: nowrap;
        }

        /* ══ Mobile ═════════════════════════════════════════════════ */
        #sb-overlay {
            display: none;
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.4);
            z-index: 1040;
        }
        @media (max-width: 767px) {
            body.sb-mobile-open #sb-overlay { display: block; }
            body.sb-mobile-open #sidebar    { width: var(--sb-exp-w); }
            body:not(.sb-mobile-open) #sidebar { width: 0; }
            #main { margin-left: 0 !important; }
        }

        /* ══ Main ═══════════════════════════════════════════════════ */
        #main {
            margin-left: 0;
            margin-top: var(--tb-h);
            padding: 18px 22px;
            min-height: calc(100vh - var(--tb-h));
            transition: margin-left var(--t);
        }

        @keyframes pageIn {
            from { opacity: 0.4; }
            to   { opacity: 1; }
        }
        #main { animation: pageIn 0.12s ease forwards; }

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
            font-size: 15px;
            font-weight: 700;
            color: var(--text);
            margin: 0;
            display: flex; align-items: center; gap: 8px;
        }
        .page-title i {
            color: var(--text-2);
            font-size: 14px;
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
            padding: 8px 12px;
            font-size: 11px;
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
            font-size: 12px;
            background: var(--surface);
        }
        .erp-table thead tr {
            background: #e5e7eb;
            border-bottom: 1px solid #9ca3af;
        }
        .erp-table thead th {
            padding: 7px 10px;
            font-weight: 700;
            color: var(--text);
            text-transform: uppercase;
            font-size: 10.5px;
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
            padding: 6px 10px;
            color: var(--text);
            border-right: 1px solid var(--border-3);
            vertical-align: middle;
            font-size: 12px;
        }
        .erp-table tbody td:last-child { border-right: none; }

        /* Code/monospace cells */
        code {
            font-family: 'Courier New', monospace !important;
            font-size: 11.5px;
            background: var(--surface-3);
            color: var(--text);
            padding: 1px 5px;
            border: 1px solid var(--border-2);
            border-radius: 2px;
        }

        /* ══ Buttons ════════════════════════════════════════════════ */
        .btn {
            border-radius: 2px;
            font-size: 12px;
            font-weight: 600;
            padding: 6px 12px;
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
            padding: 4px 10px;
            font-size: 11.5px;
        }

        /* ══ Forms ══════════════════════════════════════════════════ */
        .form-control, .form-select {
            border-radius: 2px;
            border: 1px solid var(--border);
            font-size: 12.5px;
            padding: 5px 9px;
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
            font-size: 11px;
            font-weight: 700;
            color: var(--text-2);
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.4px;
        }
        .form-control-sm, .form-select-sm {
            font-size: 11.5px;
            padding: 4px 7px;
        }
        .invalid-feedback {
            font-size: 11px;
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
            font-size: 12.5px;
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
            font-size: 10px;
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
            font-size: 10px;
            font-weight: 700;
            color: var(--text-3);
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* ══ Pagination ═════════════════════════════════════════════ */
        .pagination { gap: 0; margin: 0; }
        .page-link {
            border-radius: 0 !important;
            font-size: 11.5px;
            padding: 5px 10px;
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
            min-width: 50px;
            padding: 3px 9px;
            font-size: 11px;
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
            padding: 8px 12px;
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.6px;
            color: var(--text-2);
        }

        /* ══ Bootstrap dropdown / modal overrides ═══════════════════ */
        .dropdown-menu {
            border-radius: 2px;
            border-color: var(--border);
            font-size: 12.5px;
            padding: 4px 0;
        }
        .dropdown-item { padding: 6px 14px; font-size: 12.5px; }
        .dropdown-item:hover { background: var(--surface-2); color: var(--text); }
        .modal-content { border-radius: 2px; border: 1px solid var(--border); }
        .modal-header { background: var(--surface-2); border-bottom: 1px solid var(--border); padding: 10px 14px; }
        .modal-title { font-size: 13px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .modal-body { padding: 14px; font-size: 13px; }
        .modal-footer { padding: 10px 14px; border-top: 1px solid var(--border); }

        /* ══ Charts (Chart.js wrapper) ═══════════════════════════════ */
        canvas { max-width: 100%; }

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
            padding: 6px 10px;
            font-size: 12px;
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
            padding: 8px 10px;
            font-size: 12px;
            color: #9ca3af;
        }
        .ta-hint {
            font-size: 10.5px;
            color: #9ca3af;
            margin-top: 3px;
        }

        /* ══ Utility ═════════════════════════════════════════════════ */
        .text-muted { color: var(--text-3) !important; }
        .text-success { color: var(--c-success) !important; }
        .text-danger  { color: var(--c-danger)  !important; }
        .text-warning { color: var(--c-warn)    !important; }
        hr { border-color: var(--border); margin: 12px 0; }
        small, .small { font-size: 11.5px; }
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
        @php
            $u = auth()->user();
            $initial = strtoupper(substr($u->name ?? 'U', 0, 1));
        @endphp

        <div class="topbar-item">
            <i class="bi bi-calendar3"></i>
            <span>{{ now()->format('d M Y') }}</span>
        </div>

        <div class="topbar-item user-block">
            <i class="bi bi-person-circle"></i>
            <div class="user-info">
                <span class="name">{{ $u->name }}</span>
                <span class="role">{{ str_replace('_', ' ', $u->role) }}</span>
            </div>
        </div>

        <form method="POST" action="{{ route('logout') }}" style="margin:0">
            @csrf
            <button type="submit" class="topbar-logout" title="Logout">
                <i class="bi bi-box-arrow-right"></i>
                <span>Logout</span>
            </button>
        </form>
    </div>
</header>

{{-- ═══ SIDEBAR ═══════════════════════════════════════════════════════ --}}
<nav id="sidebar">
    @php
        $role = $u->role;
        $uri  = request()->path();
    @endphp

    @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc']))
    <div class="nav-section">
        <span class="nav-section-label">Input Data</span>
        @if(in_array($role, ['admin', 'staff_produksi']))
        <a href="{{ route('input.produksi.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'input/produksi') ? 'active' : '' }}"
           data-tip="LHP Produksi">
            <i class="bi bi-clipboard-data"></i>
            <span class="nav-text">LHP Produksi</span>
        </a>
        <a href="{{ route('input.dandori.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'input/dandori') ? 'active' : '' }}"
           data-tip="Dandori">
            <i class="bi bi-wrench-adjustable"></i>
            <span class="nav-text">Dandori</span>
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_qc']))
        <a href="{{ route('input.qc.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'input/qc') ? 'active' : '' }}"
           data-tip="LHP Quality">
            <i class="bi bi-clipboard-check"></i>
            <span class="nav-text">LHP Quality</span>
        </a>
        @endif
    </div>
    @endif

    @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc', 'manager']))
    <div class="nav-section">
        <span class="nav-section-label">Dashboard VCB</span>
        @if(in_array($role, ['admin', 'staff_produksi', 'manager']))
        <a href="{{ route('vcb.produksi') }}"
           class="nav-item-link {{ str_starts_with($uri, 'vcb/produksi') ? 'active' : '' }}"
           data-tip="VCB Produksi">
            <i class="bi bi-bar-chart"></i>
            <span class="nav-text">VCB Produksi</span>
        </a>
        <a href="{{ route('vcb.mesin') }}"
           class="nav-item-link {{ str_starts_with($uri, 'vcb/mesin') ? 'active' : '' }}"
           data-tip="VCB Mesin">
            <i class="bi bi-gear"></i>
            <span class="nav-text">VCB Mesin</span>
        </a>
        <a href="{{ route('vcb.dandori') }}"
           class="nav-item-link {{ str_starts_with($uri, 'vcb/dandori') ? 'active' : '' }}"
           data-tip="VCB Dandori">
            <i class="bi bi-clock-history"></i>
            <span class="nav-text">VCB Dandori</span>
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_qc', 'manager']))
        <a href="{{ route('vcb.quality') }}"
           class="nav-item-link {{ str_starts_with($uri, 'vcb/quality') ? 'active' : '' }}"
           data-tip="VCB Quality">
            <i class="bi bi-shield-check"></i>
            <span class="nav-text">VCB Quality</span>
        </a>
        @endif
    </div>
    @endif


    @if($role === 'admin')
    <div class="nav-section">
        <span class="nav-section-label">Master Data</span>
        <a href="{{ route('master.karyawan.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/karyawan') ? 'active' : '' }}"
           data-tip="Karyawan">
            <i class="bi bi-people"></i>
            <span class="nav-text">Karyawan</span>
        </a>
        <a href="{{ route('master.mesin.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/mesin') ? 'active' : '' }}"
           data-tip="Mesin">
            <i class="bi bi-cpu"></i>
            <span class="nav-text">Mesin</span>
        </a>
        <a href="{{ route('master.part.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/part') ? 'active' : '' }}"
           data-tip="Part / Dies">
            <i class="bi bi-box-seam"></i>
            <span class="nav-text">Part / Dies</span>
        </a>
        <a href="{{ route('master.losstime.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/losstime') ? 'active' : '' }}"
           data-tip="Loss Time">
            <i class="bi bi-stopwatch"></i>
            <span class="nav-text">Loss Time</span>
        </a>
        <a href="{{ route('master.shift.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/shift') ? 'active' : '' }}"
           data-tip="Shift">
            <i class="bi bi-calendar-week"></i>
            <span class="nav-text">Shift</span>
        </a>
        <a href="{{ route('master.jenis-ng.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/jenis-ng') ? 'active' : '' }}"
           data-tip="Jenis NG">
            <i class="bi bi-exclamation-octagon"></i>
            <span class="nav-text">Jenis NG</span>
        </a>
        <a href="{{ route('master.users.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'master/users') ? 'active' : '' }}"
           data-tip="Users">
            <i class="bi bi-person-gear"></i>
            <span class="nav-text">Users</span>
        </a>
    </div>
    @endif
</nav>

{{-- ═══ MAIN ══════════════════════════════════════════════════════════ --}}
<main id="main">
    @if(session('success'))
        <div class="alert alert-success alert-dismissible" role="alert">
            <i class="bi bi-check-circle-fill"></i>
            <span>{{ session('success') }}</span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger alert-dismissible" role="alert">
            <i class="bi bi-x-octagon-fill"></i>
            <span>{{ session('error') }}</span>
            <button type="button" class="btn-close ms-auto" data-bs-dismiss="alert"></button>
        </div>
    @endif
    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>

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

/* ─ Restore open state on desktop ───────────────────────────── */
if (!isMobile() && localStorage.getItem(SB_KEY) === '1') {
    $body.classList.add('sb-pinned');
}

/* ─ Toggle: hidden (0px) ↔ expanded (220px) ─────────────────── */
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
@stack('scripts')
</body>
</html>
