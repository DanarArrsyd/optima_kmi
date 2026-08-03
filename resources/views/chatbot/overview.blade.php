@extends('layouts.app')
@section('title', 'Overview')

{{--
    Rangka halaman Overview — grafik global belum dipasang, desainnya masih
    digambar. Yang sudah final di sini cuma dua hal: judul topbar (via
    @section('title')) dan page-header.

    Sengaja TIDAK memuat css/chatbot.css. Berkas itu menyetel #main jadi
    kanvas penuh tanpa padding untuk halaman chat; kalau ikut dimuat di sini,
    halaman ini kehilangan padding layout standarnya dan tiap panel grafik
    harus melawan gaya itu satu-satu.
--}}

@section('content')

{{-- ── PAGE HEADER ──────────────────────────────────────────── --}}
<div class="page-header">
    <h1 class="page-title">
        <i class="bi bi-graph-up-arrow"></i>Overview
    </h1>
</div>

{{-- ── ISI: menyusul desain grafik ──────────────────────────── --}}
<div class="erp-panel">
    <div class="erp-panel-body text-muted" style="padding:34px 14px;text-align:center">
        Grafik overview belum dipasang.
    </div>
</div>

@endsection
