@extends('layouts.app')
@section('title', 'Master Part / Dies')
@section('content')

{{-- ══ ALPINE SCOPE ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }} }">

{{-- ── DRAWER BACKDROP ──────────────────────────────────────────── --}}
<div x-show="drawerOpen"
     x-cloak
     @click="drawerOpen = false"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040">
</div>

{{-- ── DRAWER PANEL ─────────────────────────────────────────────── --}}
<div x-show="drawerOpen"
     x-cloak
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="transform translate-x-full"
     x-transition:enter-end="transform translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-x-0"
     x-transition:leave-end="transform translate-x-full"
     style="position:fixed;top:0;right:0;height:100vh;width:440px;max-width:100vw;background:#fff;z-index:1050;display:flex;flex-direction:column;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-box-seam me-2"></i>Tambah Part
        </div>
        <button type="button" @click="drawerOpen = false"
                style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;padding:0;line-height:1">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Body (scrollable) --}}
    <div style="flex:1;overflow-y:auto;padding:20px">

        @if($errors->any())
        <div class="alert alert-danger mb-3" style="font-size:0.82rem">
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <div style="font-size:0.8rem;color:#6b7280;margin-bottom:16px">
            <i class="bi bi-info-circle me-1"></i>
            Setelah menyimpan, Anda akan diarahkan ke halaman edit untuk menambahkan proses.
        </div>

        <form method="POST" action="{{ route('master.part.store') }}" novalidate>
            @csrf
            <div class="row g-3">

                {{-- Customer --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Customer <span class="text-muted">(opsional)</span></label>
                    <input type="text" name="customer"
                           class="form-control form-control-sm @error('customer') is-invalid @enderror"
                           value="{{ old('customer') }}"
                           placeholder="Nama customer">
                    @error('customer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Part No --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Part No <span class="text-danger">*</span></label>
                    <input type="text" name="part_no"
                           class="form-control form-control-sm @error('part_no') is-invalid @enderror"
                           value="{{ old('part_no') }}"
                           placeholder="Nomor part">
                    @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Part Name --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Part Name <span class="text-danger">*</span></label>
                    <input type="text" name="part_name"
                           class="form-control form-control-sm @error('part_name') is-invalid @enderror"
                           value="{{ old('part_name') }}"
                           placeholder="Nama part">
                    @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan &amp; Lanjut ke Proses
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary btn-sm">
                    Batal
                </button>
            </div>
        </form>
    </div>{{-- /drawer body --}}
</div>{{-- /drawer panel --}}

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-box-seam"></i>Master Part / Dies</h1>
    <div class="action-btns">
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Tambah Part
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="document.getElementById('import-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- ── REKOMENDASI UPGRADE SPH (admin only) ─────────────────── --}}
@if(auth()->user()->role === 'admin' && $recommendations->isNotEmpty())
<div class="erp-panel mb-3" style="border-color:#d4a017">
    <div class="erp-panel-header"
         style="background:#fff8e1;border-color:#d4a017;color:#7a5c00;display:flex;justify-content:space-between;align-items:center;cursor:pointer"
         onclick="document.getElementById('sph-recs-panel').classList.toggle('d-none')">
        <span>
            <i class="bi bi-graph-up-arrow me-2"></i>
            <strong>Rekomendasi Upgrade SPH Standard</strong>
            <span class="badge ms-2" style="background:#d4a017;color:#fff;font-size:0.72rem">
                {{ $recommendations->count() }} item
            </span>
        </span>
        <span style="font-size:0.8rem;color:#9a7a20">
            <i class="bi bi-chevron-down"></i> klik untuk toggle
        </span>
    </div>
    <div id="sph-recs-panel">
        <div style="padding:10px 16px;background:#fffbee;border-bottom:1px solid #ede0a0;font-size:0.78rem;color:#7a5c00">
            <i class="bi bi-info-circle me-1"></i>
            Kombinasi Part/Proses berikut secara konsisten menghasilkan SPH aktual di atas standar.
            Klik <strong>Lihat &amp; Terapkan</strong> untuk review dan approve upgrade di halaman edit part.
        </div>
        <div style="overflow-x:auto">
            <table class="erp-table" style="margin:0">
                <thead>
                    <tr style="background:#fff8e1">
                        <th>Part No</th>
                        <th>Part Name</th>
                        <th>Proses</th>
                        <th style="text-align:right;width:110px">SPH Std</th>
                        <th style="text-align:right;width:110px">SPH Terbaik</th>
                        <th style="text-align:center;width:130px">Dilampaui</th>
                        <th style="text-align:right;width:130px">Rekomendasi</th>
                        <th style="width:130px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($recommendations as $rec)
                    <tr style="background:#fffdf5">
                        <td style="font-weight:600">{{ $rec->part_no }}</td>
                        <td>{{ $rec->part_name }}</td>
                        <td>
                            <span style="font-size:0.78rem;color:#6b7280">{{ $rec->id_proses }}</span>
                            {{ $rec->nama_proses }}
                        </td>
                        <td style="text-align:right;color:#374151">
                            {{ number_format($rec->sph_standar_produksi) }}
                        </td>
                        <td style="text-align:right;color:#1a6b7c;font-weight:600">
                            {{ number_format($rec->max_sph) }}
                        </td>
                        <td style="text-align:center">
                            <span title="{{ $rec->exceed_count }} sesi dari {{ $rec->total_sessions }} sesi total">
                                {{ $rec->exceed_count }}x / {{ $rec->total_sessions }}x
                                <span style="color:#9a7a20">({{ $rec->exceed_pct }}%)</span>
                            </span>
                        </td>
                        <td style="text-align:right">
                            <strong style="color:#2d7a2d;font-size:1rem">
                                {{ number_format($rec->recommended_sph) }}
                            </strong>
                            <span style="color:#6b7280;font-size:0.75rem">
                                (+{{ $rec->increase_pct }}%)
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('master.part.edit', $rec->id_part) }}#proses-rec-{{ $rec->id_part_proses }}"
                               class="btn btn-sm"
                               style="background:#d4a017;color:#fff;border:none;font-size:0.8rem">
                                <i class="bi bi-arrow-up-circle me-1"></i>Lihat &amp; Terapkan
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

{{-- ── REKOMENDASI UPGRADE SPH QUALITY (admin only) ───────────── --}}
@if(auth()->user()->role === 'admin' && $recommendationsQc->isNotEmpty())
<div class="erp-panel mb-3" style="border-color:#1a6b7c">
    <div class="erp-panel-header"
         style="background:#e8f4f7;border-color:#1a6b7c;color:#0d3d4a;display:flex;justify-content:space-between;align-items:center;cursor:pointer"
         onclick="document.getElementById('sph-qc-recs-panel').classList.toggle('d-none')">
        <span>
            <i class="bi bi-graph-up-arrow me-2"></i>
            <strong>Rekomendasi Upgrade SPH Standard Quality</strong>
            <span class="badge ms-2" style="background:#1a6b7c;color:#fff;font-size:0.72rem">
                {{ $recommendationsQc->count() }} item
            </span>
        </span>
        <span style="font-size:0.8rem;color:#2a8a9c">
            <i class="bi bi-chevron-down"></i> klik untuk toggle
        </span>
    </div>
    <div id="sph-qc-recs-panel">
        <div style="padding:10px 16px;background:#f0f8fa;border-bottom:1px solid #b2d8e0;font-size:0.78rem;color:#0d3d4a">
            <i class="bi bi-info-circle me-1"></i>
            Kombinasi Part/Proses berikut konsisten menghasilkan SPH aktual QC di atas standar.
            Klik <strong>Lihat &amp; Terapkan</strong> untuk review dan approve upgrade di halaman edit part.
        </div>
        <div style="overflow-x:auto">
            <table class="erp-table" style="margin:0">
                <thead>
                    <tr style="background:#e8f4f7">
                        <th>Part No</th>
                        <th>Part Name</th>
                        <th>Proses</th>
                        <th style="text-align:right;width:110px">SPH QC Std</th>
                        <th style="text-align:right;width:110px">SPH Terbaik</th>
                        <th style="text-align:center;width:130px">Dilampaui</th>
                        <th style="text-align:right;width:130px">Rekomendasi</th>
                        <th style="width:130px">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($recommendationsQc as $rec)
                    <tr style="background:#f5fbfd">
                        <td style="font-weight:600">{{ $rec->part_no }}</td>
                        <td>{{ $rec->part_name }}</td>
                        <td>
                            <span style="font-size:0.78rem;color:#6b7280">{{ $rec->id_proses }}</span>
                            {{ $rec->nama_proses }}
                        </td>
                        <td style="text-align:right;color:#374151">
                            {{ number_format($rec->sph_standar) }}
                        </td>
                        <td style="text-align:right;color:#1a6b7c;font-weight:600">
                            {{ number_format($rec->max_sph) }}
                        </td>
                        <td style="text-align:center">
                            <span title="{{ $rec->exceed_count }} sesi dari {{ $rec->total_sessions }} sesi total">
                                {{ $rec->exceed_count }}x / {{ $rec->total_sessions }}x
                                <span style="color:#2a8a9c">({{ $rec->exceed_pct }}%)</span>
                            </span>
                        </td>
                        <td style="text-align:right">
                            <strong style="color:#2d7a2d;font-size:1rem">
                                {{ number_format($rec->recommended_sph) }}
                            </strong>
                            <span style="color:#6b7280;font-size:0.75rem">
                                (+{{ $rec->increase_pct }}%)
                            </span>
                        </td>
                        <td>
                            <a href="{{ route('master.part.edit', $rec->id_part) }}#proses-rec-{{ $rec->id_part_proses }}"
                               class="btn btn-sm"
                               style="background:#1a6b7c;color:#fff;border:none;font-size:0.8rem">
                                <i class="bi bi-arrow-up-circle me-1"></i>Lihat &amp; Terapkan
                            </a>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
@endif

<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Part &amp; Proses dari Excel</span>
        <a href="{{ route('master.part.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.part.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label mb-1" style="font-size:0.8rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control form-control-sm @error('file') is-invalid @enderror"
                           accept=".xlsx,.xls,.csv">
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
            <div class="mt-2" style="font-size:0.75rem;color:#6c757d">
                Kolom wajib: <strong>Part No</strong>, <strong>Part Name</strong>, <strong>ID Proses</strong>, <strong>Nama Proses</strong>.
                Satu baris = satu kombinasi Part + Proses. Part yang sama pada baris berbeda akan digabung otomatis.
                Data existing di-update berdasarkan Part No + ID Proses.
            </div>
        </form>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span>Daftar Part &amp; Proses</span>
        <span style="font-size:0.7rem;font-weight:400;color:#5d7283">{{ $rows->total() }} baris &mdash; klik <strong>Edit</strong> untuk kelola proses</span>
    </div>
    <table class="erp-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Part No</th>
                <th>Part Name</th>
                <th>ID Proses</th>
                <th>Nama Proses</th>
                <th>SPH Produksi<br><span style="font-weight:400;font-size:0.72rem">+ tgl upgrade</span></th>
                <th>SPH Quality<br><span style="font-weight:400;font-size:0.72rem">+ tgl upgrade</span></th>
                <th style="width:160px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($rows as $proses)
            @php $part = $proses->part; @endphp
            <tr>
                <td>{{ $part->customer ?? '—' }}</td>
                <td>{{ $part->part_no }}</td>
                <td>{{ $part->part_name }}</td>
                <td style="font-weight:600">{{ $proses->id_proses }}</td>
                <td>{{ $proses->nama_proses }}</td>
                <td>
                    {{ number_format($proses->sph_standar_produksi, 0) }}
                    @if($proses->sph_last_upgraded_at)
                        <br>
                        <span style="font-size:0.7rem;color:#2d7a2d;white-space:nowrap"
                              title="Diupgrade pada {{ $proses->sph_last_upgraded_at->format('d M Y H:i') }}">
                            <i class="bi bi-arrow-up-circle-fill"></i>
                            {{ $proses->sph_last_upgraded_at->format('d M Y') }}
                        </span>
                    @endif
                </td>
                <td>
                    {{ number_format($proses->sph_standar_qc, 0) }}
                    @if($proses->sph_qc_last_upgraded_at)
                        <br>
                        <span style="font-size:0.7rem;color:#1a6b7c;white-space:nowrap"
                              title="SPH QC diupgrade pada {{ $proses->sph_qc_last_upgraded_at->format('d M Y H:i') }}">
                            <i class="bi bi-arrow-up-circle-fill"></i>
                            {{ $proses->sph_qc_last_upgraded_at->format('d M Y') }}
                        </span>
                    @endif
                </td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('master.part.edit', $part) }}" class="btn btn-warning btn-sm">Edit Part</a>
                        <form method="POST" action="{{ route('master.part.proses.destroy', [$part, $proses]) }}"
                              onsubmit="return confirm('Hapus proses {{ $proses->nama_proses }} dari part {{ $part->part_no }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus Proses</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="8" style="text-align:center;color:#888;padding:24px">
                    Belum ada data part &amp; proses.
                    <a href="{{ route('master.part.create') }}">Tambah Part Baru</a>
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $rows->links() }}</div>

{{-- Part tanpa proses (part sudah ada tapi prosesnya belum) --}}
@php
    $partsTanpaProses = \App\Models\MasterPart::doesntHave('proses')->orderBy('part_name')->get();
@endphp
@if($partsTanpaProses->isNotEmpty())
<div class="erp-panel mt-3">
    <div class="erp-panel-header" style="background:#fef3cd;border-color:#ffc107;color:#856404">
        <i class="bi bi-exclamation-triangle me-1"></i>
        Part Belum Punya Proses ({{ $partsTanpaProses->count() }})
    </div>
    <table class="erp-table">
        <thead>
            <tr>
                <th>Customer</th>
                <th>Part No</th>
                <th>Part Name</th>
                <th style="width:200px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @foreach($partsTanpaProses as $p)
            <tr style="background:#fffbf0">
                <td>{{ $p->customer ?? '—' }}</td>
                <td>{{ $p->part_no }}</td>
                <td>{{ $p->part_name }}</td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('master.part.edit', $p) }}" class="btn btn-warning btn-sm">Tambah Proses</a>
                        <form method="POST" action="{{ route('master.part.destroy', $p) }}"
                              onsubmit="return confirm('Hapus part {{ $p->part_no }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus Part</button>
                        </form>
                    </div>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endif

</div>{{-- /alpine outer scope --}}
@endsection
