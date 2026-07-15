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

        /* Sidebar sections */
        .nav-section { padding: 10px 0 4px; }
        .nav-section + .nav-section {
            border-top: 1px solid #374151;
            margin-top: 2px;
        }
        .nav-section-label {
            font-size: 11px;
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

        /* ── Nav accordion group (Dashboard) ── */
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
        .modal-content { border-radius: 2px; border: 1px solid var(--border); }
        .modal-header { background: var(--surface-2); border-bottom: 1px solid var(--border); padding: 10px 14px; }
        .modal-title { font-size: 15px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.5px; }
        .modal-body { padding: 16px; font-size: 15px; }
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
        .tbl-freeze .sc-sep { border-right: none; box-shadow: 2px 0 0 0 #9ca3af; }
        .tbl-freeze thead th.sc-sep { box-shadow: 2px 0 0 0 #6b7280; }

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

    @if(in_array($role, ['admin', 'manager']))
    <div class="nav-section">
        <span class="nav-section-label">AI Assistant</span>
        <a href="{{ route('chatbot.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'chatbot') ? 'active' : '' }}"
           data-tip="AI Assistant">
            <i class="bi bi-robot"></i>
            <span class="nav-text">AI Assistant</span>
        </a>
    </div>
    @endif

    @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'manager']))
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
        @if($role === 'admin')
        <a href="{{ route('admin.anomali') }}"
           class="nav-item-link {{ str_starts_with($uri, 'admin/anomali') ? 'active' : '' }}"
           data-tip="Anomali LHP"
           style="{{ str_starts_with($uri, 'admin/anomali') ? '' : 'color:#ca8a04' }}">
            <i class="bi bi-exclamation-triangle-fill" style="color:#ca8a04"></i>
            <span class="nav-text">Anomali LHP</span>
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_maintenance', 'manager']))
        <a href="{{ route('maintenance.breakdown.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'maintenance/breakdown') && !str_starts_with($uri, 'maintenance/breakdown-dies') ? 'active' : '' }}"
           data-tip="Breakdown Mesin">
            <i class="bi bi-tools"></i>
            <span class="nav-text">Breakdown Mesin</span>
        </a>
        <a href="{{ route('maintenance.breakdown-dies.index') }}"
           class="nav-item-link {{ str_starts_with($uri, 'maintenance/breakdown-dies') ? 'active' : '' }}"
           data-tip="Breakdown Dies">
            <i class="bi bi-hammer"></i>
            <span class="nav-text">Breakdown Dies</span>
        </a>
        @endif
    </div>
    @endif

    @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'manager']))
    @php
        $vcbActive = match(true) {
            str_starts_with($uri, 'vcb/produksi'),
            str_starts_with($uri, 'vcb/mesin'),
            str_starts_with($uri, 'vcb/dandori')          => 'produksi',
            str_starts_with($uri, 'vcb/quality')           => 'quality',
            str_starts_with($uri, 'maintenance/mttr-dies'),
            str_starts_with($uri, 'maintenance/mtbf-dies'),
            str_starts_with($uri, 'maintenance/availability-dies'),
            str_starts_with($uri, 'maintenance/availability/control-stroke-dies') => 'mtcdies',
            str_starts_with($uri, 'maintenance/mttr'),
            str_starts_with($uri, 'maintenance/mtbf'),
            str_starts_with($uri, 'maintenance/availability') => 'mtcmesin',
            default => '',
        };
    @endphp
    <div class="nav-section" x-data="vcbGroups('{{ $vcbActive }}')">
        <span class="nav-section-label">Dashboard</span>

        {{-- ── PRODUKSI ── --}}
        @if(in_array($role, ['admin', 'staff_produksi', 'manager']))
        <button type="button" class="nav-group-header {{ $vcbActive==='produksi' ? 'has-active' : '' }}"
                :class="{ 'open': isOpen('produksi') }" @click="toggle('produksi')">
            <i class="bi bi-box-seam"></i><span class="nav-text">Produksi</span>
            <i class="bi bi-chevron-down nav-caret"></i>
        </button>
        <div class="nav-group-body" x-show="isOpen('produksi')" x-cloak>
            <a href="{{ route('vcb.produksi') }}" class="nav-item-link {{ str_starts_with($uri,'vcb/produksi')?'active':'' }}">
                <i class="bi bi-bar-chart"></i><span class="nav-text">VCB Produksi</span>
            </a>
            <a href="{{ route('vcb.mesin') }}" class="nav-item-link {{ str_starts_with($uri,'vcb/mesin')?'active':'' }}">
                <i class="bi bi-gear"></i><span class="nav-text">VCB Mesin</span>
            </a>
            <a href="{{ route('vcb.dandori') }}" class="nav-item-link {{ str_starts_with($uri,'vcb/dandori')?'active':'' }}">
                <i class="bi bi-clock-history"></i><span class="nav-text">VCB Dandori</span>
            </a>
        </div>
        @endif

        {{-- ── QUALITY ── --}}
        @if(in_array($role, ['admin', 'staff_qc', 'manager']))
        <button type="button" class="nav-group-header {{ $vcbActive==='quality' ? 'has-active' : '' }}"
                :class="{ 'open': isOpen('quality') }" @click="toggle('quality')">
            <i class="bi bi-shield-check"></i><span class="nav-text">Quality</span>
            <i class="bi bi-chevron-down nav-caret"></i>
        </button>
        <div class="nav-group-body" x-show="isOpen('quality')" x-cloak>
            <a href="{{ route('vcb.quality') }}" class="nav-item-link {{ str_starts_with($uri,'vcb/quality')?'active':'' }}">
                <i class="bi bi-shield-check"></i><span class="nav-text">VCB Quality</span>
            </a>
        </div>
        @endif

        {{-- ── MTC MESIN ── --}}
        @if(in_array($role, ['admin', 'staff_maintenance', 'manager']))
        <button type="button" class="nav-group-header {{ $vcbActive==='mtcmesin' ? 'has-active' : '' }}"
                :class="{ 'open': isOpen('mtcmesin') }" @click="toggle('mtcmesin')">
            <i class="bi bi-tools"></i><span class="nav-text">MTC Mesin</span>
            <i class="bi bi-chevron-down nav-caret"></i>
        </button>
        <div class="nav-group-body" x-show="isOpen('mtcmesin')" x-cloak>
            <a href="{{ route('maintenance.mttr') }}" class="nav-item-link {{ $vcbActive==='mtcmesin' && str_starts_with($uri,'maintenance/mttr')?'active':'' }}">
                <i class="bi bi-clock-history"></i><span class="nav-text">MTTR</span>
            </a>
            <a href="{{ route('maintenance.mtbf') }}" class="nav-item-link {{ $vcbActive==='mtcmesin' && str_starts_with($uri,'maintenance/mtbf')?'active':'' }}">
                <i class="bi bi-arrow-repeat"></i><span class="nav-text">MTBF</span>
            </a>
            <a href="{{ route('maintenance.availability') }}" class="nav-item-link {{ $vcbActive==='mtcmesin' && str_starts_with($uri,'maintenance/availability') && !str_starts_with($uri,'maintenance/availability/control-stroke')?'active':'' }}">
                <i class="bi bi-graph-up"></i><span class="nav-text">Availability</span>
            </a>
            <a href="{{ route('maintenance.control-stroke-mesin') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/availability/control-stroke-mesin')?'active':'' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-text">Control Stroke</span>
            </a>
        </div>
        @endif

        {{-- ── MTC DIES ── --}}
        @if(in_array($role, ['admin', 'staff_maintenance', 'manager']))
        <button type="button" class="nav-group-header {{ $vcbActive==='mtcdies' ? 'has-active' : '' }}"
                :class="{ 'open': isOpen('mtcdies') }" @click="toggle('mtcdies')">
            <i class="bi bi-hammer"></i><span class="nav-text">MTC Dies</span>
            <i class="bi bi-chevron-down nav-caret"></i>
        </button>
        <div class="nav-group-body" x-show="isOpen('mtcdies')" x-cloak>
            <a href="{{ route('maintenance.mttr-dies') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/mttr-dies')?'active':'' }}">
                <i class="bi bi-clock-history"></i><span class="nav-text">MTTR</span>
            </a>
            <a href="{{ route('maintenance.mtbf-dies') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/mtbf-dies')?'active':'' }}">
                <i class="bi bi-arrow-repeat"></i><span class="nav-text">MTBF</span>
            </a>
            <a href="{{ route('maintenance.availability-dies') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/availability-dies')?'active':'' }}">
                <i class="bi bi-graph-up"></i><span class="nav-text">Availability</span>
            </a>
            <a href="{{ route('maintenance.control-stroke-dies') }}" class="nav-item-link {{ str_starts_with($uri,'maintenance/availability/control-stroke-dies')?'active':'' }}">
                <i class="bi bi-speedometer2"></i><span class="nav-text">Control Stroke</span>
            </a>
        </div>
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
    @unless(View::hasSection('suppress_layout_flash'))
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
    @endunless
    @yield('content')
</main>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
    /* Registered globally but OFF by default — only charts that explicitly
       set plugins.datalabels.display=true (maintenance MTBF/MTTR/Availability) show labels. */
    if (window.Chart && window.ChartDataLabels) {
        Chart.register(ChartDataLabels);
        Chart.defaults.set('plugins.datalabels', { display: false });
    }
</script>
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

/* ─ Dashboard accordion (single-open + ingat via localStorage) ─ */
function vcbGroups(active = '') {
    return {
        open: active || localStorage.getItem('optima_vcb_group') || 'produksi',
        isOpen(g) { return this.open === g; },
        toggle(g) {
            this.open = this.open === g ? null : g;
            localStorage.setItem('optima_vcb_group', this.open || '');
        },
    };
}

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
