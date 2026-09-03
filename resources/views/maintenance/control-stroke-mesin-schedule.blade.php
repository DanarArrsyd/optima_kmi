@extends('layouts.app')
@section('title', 'Edit Jadwal Preventive Mesin')
{{-- erp-flush + erp-ws — WAJIB buat halaman yang punya tabel lebar (banyak kolom
     tanggal). Tanpa ini, tabel lebar bikin SELURUH HALAMAN ikut geser horizontal
     (topbar ikutan), bukan cuma tabelnya — #main.erp-flush ngunci overflow:hidden,
     cuma .erp-scroll di dalamnya yang boleh scroll. Lihat memory
     feedback_sticky_header_scroll_wrapper. --}}
@section('main_mod', 'erp-flush')

@section('content')
<div class="erp-ws">
    <div class="erp-tb">
        <span style="font-weight:600"><i class="bi bi-calendar-week me-1"></i>Edit Jadwal Preventive Mesin</span>
        <span class="erp-tb-sp"></span>
        <a href="{{ route('maintenance.control-stroke-mesin.schedule-pdf') }}" target="_blank" class="erp-tb-btn">
            <i class="bi bi-file-earmark-pdf"></i>Schedule PDF
        </a>
        <a href="{{ route('maintenance.control-stroke-mesin') }}" class="erp-tb-btn">
            <i class="bi bi-arrow-left"></i>Kembali
        </a>
    </div>
    <div class="erp-ws-body">
        <div class="erp-ws-main">
            <div class="erp-scroll erp-scroll-pad">
                @include('maintenance.partials.preventive-schedule-grid', ['jenis' => 'mesin', 'schedule' => $schedule, 'bulan' => $bulan, 'bolehEdit' => $bolehEdit])
            </div>
        </div>
    </div>
</div>
@endsection
