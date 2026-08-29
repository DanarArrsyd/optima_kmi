@extends('layouts.app')
@section('title', $judul)

@php
    // Data typeahead. Bentuk {v,t} sama dengan filterSearchComp di /input/produksi,
    // supaya komponennya bisa dipakai apa adanya.
    $_jenisNgTA = $jenisNgOptions->map(fn ($j) => ['v' => $j->id, 't' => $j->kode.' — '.$j->nama_ng])->values();
    $_orangTA = $orangOptions->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();

    $_jenisLabel = $filter['jenis_ng_id']
        ? (($j = $jenisNgOptions->firstWhere('id', $filter['jenis_ng_id'])) ? $j->kode.' — '.$j->nama_ng : '')
        : '';
    $_orangLabel = $filter['karyawan_id']
        ? ($orangOptions->firstWhere('id', $filter['karyawan_id'])?->nama_karyawan ?? '')
        : '';

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
<div class="erp-ws">

<div class="erp-tb">
    <button type="button" id="btnDock" class="erp-tb-btn"
            onclick="erpToggleDock('nglDock','btnDock')">
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
<aside class="erp-dock" id="nglDock" style="display:none">
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" onclick="erpToggleDock('nglDock','btnDock')" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ $routeDaftar }}" class="erp-dock-body">
            

                <div class="erp-grp">
                    <b>Tanggal Mulai</b>
                    <input type="date" name="date_from" value="{{ $filter['date_from'] }}"
                           class="erp-in @error('date_from') is-invalid @enderror">
                    @error('date_from')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="erp-grp">
                    <b>Tanggal Akhir</b>
                    <input type="date" name="date_to" value="{{ $filter['date_to'] }}"
                           class="erp-in @error('date_to') is-invalid @enderror">
                    @error('date_to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Jenis NG — typeahead --}}
                <div class="erp-grp"
                     x-data="filterSearchComp(_jenisNgTA, {{ $filter['jenis_ng_id'] ?: 0 }}, '{{ addslashes($_jenisLabel) }}')"
                     @click.outside="open=false">
                    <b>Jenis NG</b>
                    <input type="hidden" name="jenis_ng_id" :value="val">
                    <div style="position:relative">
                        <input type="text" x-model="query" @focus="open=true" @input="onInput()" @keydown.escape="open=false"
                               placeholder="Semua jenis…" class="erp-in" :class="val ? 'is-set' : ''">
                        <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                        <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                            <template x-for="item in filtered" :key="item.v">
                                <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Operator / Inspector — typeahead --}}
                <div class="erp-grp"
                     x-data="filterSearchComp(_orangTA, {{ $filter['karyawan_id'] ?: 0 }}, '{{ addslashes($_orangLabel) }}')"
                     @click.outside="open=false">
                    <b>{{ $labelOrang }}</b>
                    <input type="hidden" name="karyawan_id" :value="val">
                    <div style="position:relative">
                        <input type="text" x-model="query" @focus="open=true" @input="onInput()" @keydown.escape="open=false"
                               placeholder="Semua {{ strtolower($labelOrang) }}…" class="erp-in" :class="val ? 'is-set' : ''">
                        <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                        <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                            <template x-for="item in filtered" :key="item.v">
                                <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Part / Customer SENGAJA tetap teks bebas, bukan typeahead: satu
                     input ini mencari tiga kolom sekaligus (part_no, part_name,
                     customer), jadi tidak ada satu daftar yang bisa dipilih. --}}
                <div class="erp-grp">
                    <b>Part / Customer</b>
                    <input type="text" name="q" value="{{ $filter['q'] }}" maxlength="100"
                           placeholder="Part no, nama, customer…" class="erp-in">
                    @error('q')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div class="erp-grp">
                    <button type="submit" class="erp-btn is-pri">
                        <i class="bi bi-funnel"></i> Terapkan
                    </button>
                    <a href="{{ $routeDaftar }}" class="erp-btn">Reset</a>
                </div>
    </form>
</aside>

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

@push('scripts')
<script>
const _jenisNgTA = @json($_jenisNgTA);
const _orangTA   = @json($_orangTA);

/* filterSearchComp kini di layouts/app.blade.php — tidak lagi disalin. */
</script>
@endpush
