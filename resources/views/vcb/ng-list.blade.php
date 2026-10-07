@extends('layouts.app')
@section('title', $judul)

@php
    $filterAktif = $filter['jenis_ng_id'] || $filter['karyawan_id'] || $filter['q'];
@endphp

@push('styles')
<style>
    /* Isian dan dropdown ketik-cari kini memakai kelas layout (.erp-in,
     * .erp-dd, .erp-clr). Salinan .fbar-* lokal dibuang: halaman input
     * sudah pindah ke kelas layout, jadi alasan menyalinnya hilang — dan
     * salinan itulah yang dulu menyimpang diam-diam dari aslinya.
     */
    /* Kolom memanjang mengikuti isi teks: nol pembungkusan, lebar dari konten. */
    #ngListTable td, #ngListTable th { white-space: nowrap; }
    .ngl-num { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
    .ngl-mut { color:#5d7283; }
    .ngl-kosong { padding:28px 14px; text-align:center; color:#5d7283; font-size:13px; white-space:normal; }
    .ngl-ringkas { font-size:12px; color:#5d7283; font-variant-numeric:tabular-nums; font-weight:400; }
</style>
@endpush

{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
<div class="erp-ws" x-data="{ filterOpen: false }">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($filterAktif)<span aria-hidden="true">&bull;</span>@endif
    </button>
    @if(! auth()->user()->isGuest())
    {{-- Guest sengaja tidak punya tombol export: route-nya juga menolak. --}}
    <a href="{{ $routeExport }}?{{ http_build_query(array_filter($filter, fn ($v) => $v !== null && $v !== '')) }}"
       class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <a href="{{ $routeDaftar }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- Dock mulai tertutup; cakupan data yang sedang dilihat tetap
     terbaca di status bar, jadi tidak ada yang tersembunyi diam-diam. --}}
<x-filter.dock :action="$routeDaftar" tombol="Terapkan">
    <x-filter.grup judul="Find">
        <x-filter.cari name="karyawan_id" :placeholder="$labelOrang" :value="$filter['karyawan_id']"
                       :items="$orangOptions->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])" />
        <x-filter.cari name="jenis_ng_id" placeholder="Jenis NG" :value="$filter['jenis_ng_id']"
                       :items="$jenisNgOptions->map(fn ($j) => ['v' => $j->id, 't' => $j->kode.' — '.$j->nama_ng])" />
    </x-filter.grup>

    {{-- SENGAJA teks bebas, bukan typeahead: satu isian ini mencari tiga kolom
         sekaligus (part_no, part_name, customer). --}}
    <x-filter.grup judul="Part & Customer">
        <x-filter.teks :value="$filter['q']" placeholder="Part no, nama, customer…" maxlength="100" />
    </x-filter.grup>

    <x-filter.tanggal :from="$filter['date_from']" :to="$filter['date_to']" />
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll">
@if($rows->isEmpty())
    <div class="ngl-kosong">{{ $catatanKosong }}</div>
@else

        <table id="ngListTable" class="erp-table">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th>Tanggal</th>
                    <th>Shift</th>
                    @if($sumber === 'produksi')<th>Mesin</th>@endif
                    <th>Customer</th>
                    <th>Part No</th>
                    <th>Part Name</th>
                    <th>Proses</th>
                    <th>{{ $labelOrang }}</th>
                    <th style="text-align:center">Kode NG</th>
                    <th>Jenis NG</th>
                    <th style="text-align:right">Qty NG</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                    <td class="ngl-mut">{{ $r->shift ?? '—' }}</td>
                    @if($sumber === 'produksi')<td>{{ $r->mesin ?? '—' }}</td>@endif
                    <td class="ngl-mut">{{ $r->customer ?? '—' }}</td>
                    <td>{{ $r->part_no ?? '—' }}</td>
                    <td class="ngl-mut">{{ $r->part_name ?? '—' }}</td>
                    <td class="ngl-mut">{{ $r->proses ?? '—' }}</td>
                    <td>{{ $r->orang ?? '—' }}</td>
                    <td style="text-align:center;font-weight:600">{{ $r->kode_ng }}</td>
                    <td>{{ $r->nama_ng }}</td>
                    <td class="ngl-num" style="color:#c0392b">{{ number_format($r->qty, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
@endif
</div>{{-- /erp-scroll --}}

@if($rows->hasPages())
{{-- Paginasi mempertahankan filter aktif lewat withQueryString() di service. --}}
<div class="erp-pg">{{ $rows->links() }}</div>
@endif

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>{{ $judul }}</span>
    <span><b>{{ number_format($rows->total(), 0, ',', '.') }}</b> baris</span>
    @if($filterAktif)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection

