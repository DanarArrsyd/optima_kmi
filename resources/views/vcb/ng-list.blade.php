@extends('layouts.app')
@section('title', $judul)

@push('styles')
<style>
    /* ══ Daftar NG — memakai bahasa visual ERP yang sudah ada ═══════════
     * Nol font baru, nol bayangan, nol warna di luar token layout.
     * Kelas panel yang benar adalah .erp-panel-header; .erp-panel-head TIDAK
     * ada di layout dan membuat header dirender tanpa gaya sama sekali.
     */
    .ngl-filter label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--text-3);
        margin-bottom: 3px;
    }
    .ngl-filter .form-control,
    .ngl-filter .form-select { font-size: 13px; }
    .ngl-qty { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
    .ngl-kode {
        font-variant-numeric: tabular-nums;
        white-space: nowrap;
        color: var(--text-3);
    }
    .ngl-kosong {
        padding: 28px 14px;
        text-align: center;
        color: var(--text-3);
        font-size: 13px;
    }
    .ngl-ringkas {
        font-size: 12px;
        color: var(--text-3);
        font-variant-numeric: tabular-nums;
    }
</style>
@endpush

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ $judul }}</h1>
    <p class="page-sub">Satu baris = satu jenis NG dalam satu sesi. Satu LHP dengan tiga jenis NG tampil sebagai tiga baris.</p>
</div>

{{-- ── FILTER ─────────────────────────────────────────────────────── --}}
<div class="erp-panel mb-3">
    <div class="erp-panel-header">Filter</div>
    <div class="erp-panel-body">
        <form method="GET" action="{{ $routeDaftar }}" class="ngl-filter">
            <div class="row g-2 align-items-end">
                <div class="col-6 col-md-2">
                    <label for="date_from">Tanggal Mulai</label>
                    <input type="date" id="date_from" name="date_from" class="form-control @error('date_from') is-invalid @enderror"
                           value="{{ $filter['date_from'] }}">
                    @error('date_from')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-2">
                    <label for="date_to">Tanggal Akhir</label>
                    <input type="date" id="date_to" name="date_to" class="form-control @error('date_to') is-invalid @enderror"
                           value="{{ $filter['date_to'] }}">
                    @error('date_to')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-2">
                    <label for="jenis_ng_id">Jenis NG</label>
                    <select id="jenis_ng_id" name="jenis_ng_id" class="form-select @error('jenis_ng_id') is-invalid @enderror">
                        <option value="">Semua jenis</option>
                        @foreach($jenisNgOptions as $j)
                            <option value="{{ $j->id }}" @selected($filter['jenis_ng_id'] === $j->id)>
                                {{ $j->kode }} — {{ $j->nama_ng }}
                            </option>
                        @endforeach
                    </select>
                    @error('jenis_ng_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6 col-md-2">
                    <label for="karyawan_id">{{ $labelOrang }}</label>
                    <select id="karyawan_id" name="karyawan_id" class="form-select @error('karyawan_id') is-invalid @enderror">
                        <option value="">Semua {{ strtolower($labelOrang) }}</option>
                        @foreach($orangOptions as $k)
                            <option value="{{ $k->id }}" @selected($filter['karyawan_id'] === $k->id)>{{ $k->nama_karyawan }}</option>
                        @endforeach
                    </select>
                    @error('karyawan_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-2">
                    <label for="q">Part / Customer</label>
                    <input type="text" id="q" name="q" class="form-control @error('q') is-invalid @enderror"
                           value="{{ $filter['q'] }}" placeholder="Part no, nama, customer" maxlength="100">
                    @error('q')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12 col-md-2 d-flex gap-2">
                    <button type="submit" class="btn btn-dark btn-sm flex-fill">
                        <i class="bi bi-funnel"></i> Terapkan
                    </button>
                    <a href="{{ $routeDaftar }}" class="btn btn-outline-secondary btn-sm">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── DAFTAR ─────────────────────────────────────────────────────── --}}
<div class="erp-panel">
    <div class="erp-panel-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
            Daftar Baris NG
            <span class="ngl-ringkas">&mdash; {{ number_format($rows->total(), 0, ',', '.') }} baris</span>
        </span>
        {{-- Guest sengaja tidak punya tombol export: route-nya juga menolak. --}}
        @if(auth()->user()->role !== 'guest')
        <a href="{{ $routeExport }}{{ http_build_query(array_filter($filter)) ? '?'.http_build_query(array_filter($filter)) : '' }}"
           class="btn btn-outline-dark btn-sm">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </a>
        @endif
    </div>

    @if($rows->isEmpty())
        <div class="ngl-kosong">{{ $catatanKosong }}</div>
    @else
    <div style="overflow-x:auto">
        <table class="erp-table">
            <thead>
                <tr>
                    <th>Tanggal</th>
                    <th>Shift</th>
                    @if($sumber === 'produksi')<th>Mesin</th>@endif
                    <th>Customer</th>
                    <th>Part No</th>
                    <th>Part Name</th>
                    <th>Proses</th>
                    <th>{{ $labelOrang }}</th>
                    <th>Kode NG</th>
                    <th>Jenis NG</th>
                    <th style="text-align:right">Qty NG</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                <tr>
                    <td class="ngl-kode">{{ $r->tanggal }}</td>
                    <td>{{ $r->shift ?? '—' }}</td>
                    @if($sumber === 'produksi')<td>{{ $r->mesin ?? '—' }}</td>@endif
                    <td>{{ $r->customer }}</td>
                    <td class="ngl-kode">{{ $r->part_no }}</td>
                    <td>{{ $r->part_name }}</td>
                    <td>{{ $r->proses }}</td>
                    <td>{{ $r->orang ?? '—' }}</td>
                    <td class="ngl-kode">{{ $r->kode_ng }}</td>
                    <td>{{ $r->nama_ng }}</td>
                    <td class="ngl-qty">{{ number_format($r->qty, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- Paginasi mempertahankan filter aktif lewat withQueryString() di service. --}}
@if($rows->hasPages())
<div class="mt-3">{{ $rows->links() }}</div>
@endif
@endsection
