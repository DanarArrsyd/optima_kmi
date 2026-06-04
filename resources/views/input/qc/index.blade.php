@extends('layouts.app')
@section('title', 'LHP Quality')

@php
$oldKaryawanId  = old('id_karyawan_qc');
$oldKaryawan    = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawan?->nama_karyawan ?? '';

$oldJenisNgId  = old('id_jenis_ng');
$oldJenisNgObj = $jenisNg->firstWhere('id', $oldJenisNgId);
$oldJenisNgTxt = $oldJenisNgObj ? "{$oldJenisNgObj->kode} — {$oldJenisNgObj->nama_ng}" : '';
@endphp

@section('content')

{{-- ══ SATU ALPINE SCOPE — mengontrol drawer sekaligus konten halaman ══ --}}
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
     style="position:fixed;top:0;right:0;height:100vh;width:500px;max-width:100vw;background:#fff;z-index:1050;display:flex;flex-direction:column;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-check2-square me-2"></i>Input LHP Quality
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

        @if($karyawan->isEmpty() || $parts->isEmpty())
        <div class="alert alert-warning mb-3" style="font-size:0.82rem">
            <strong>Master data belum lengkap:</strong>
            @if($karyawan->isEmpty()) <a href="{{ route('master.karyawan.index') }}">Tambah QC Inspector (departemen: QC)</a>. @endif
            @if($parts->isEmpty()) <a href="{{ route('master.part.index') }}">Tambah Part / Dies</a>. @endif
        </div>
        @endif

        <form method="POST" action="{{ route('input.qc.store') }}" id="formQc" novalidate>
            @csrf
            <div class="row g-3">

                {{-- Tanggal Pengecekan + Tanggal Produksi Part --}}
                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">Tanggal Pengecekan <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_pengecekan"
                           class="form-control form-control-sm @error('tgl_pengecekan') is-invalid @enderror"
                           value="{{ old('tgl_pengecekan', date('Y-m-d')) }}">
                    @error('tgl_pengecekan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">Tanggal Produksi Part <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_produksi_part"
                           class="form-control form-control-sm @error('tgl_produksi_part') is-invalid @enderror"
                           value="{{ old('tgl_produksi_part', date('Y-m-d')) }}">
                    @error('tgl_produksi_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Shift --}}
                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select form-select-sm @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih Shift --</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }} ({{ $shift->jam_start }} – {{ $shift->jam_finish }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- QC Inspector — typeahead, full width --}}
                <div class="col-12"
                     x-data="taField(taKaryawan, '{{ $oldKaryawanTxt }}', {{ $oldKaryawanId ?: 'null' }})">
                    <label class="form-label" style="font-size:0.8rem">QC Inspector <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control form-control-sm ta-input @error('id_karyawan_qc') is-invalid @enderror"
                               x-model="query"
                               @focus="openDd()"
                               @blur="closeDd()"
                               @keydown="onKey($event)"
                               @input="onInput()"
                               placeholder="Ketik nama inspector...">
                        <input type="hidden" name="id_karyawan_qc" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_karyawan_qc')<div class="invalid-feedback d-block" style="font-size:0.78rem">{{ $message }}</div>@enderror
                </div>

                {{-- Part — typeahead, auto-select finish-good proses --}}
                <div class="col-12" x-data="qcPartSelector()">
                    <label class="form-label" style="font-size:0.8rem">Part (Finish Good) <span class="text-danger">*</span></label>
                    <div class="ta-wrap" style="margin-bottom:8px">
                        <input type="text"
                               class="form-control form-control-sm ta-input"
                               x-model="query"
                               @focus="openDd()"
                               @blur="closeDd()"
                               @keydown="onKey($event)"
                               @input="onInput()"
                               placeholder="Ketik part no atau nama part...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>

                    {{-- Proses info (auto-selected finish good) --}}
                    <div x-show="proses !== null" x-cloak class="mb-2 p-2"
                         style="background:#f0f4f8;border:1px solid #d4dbe3;border-radius:2px;font-size:0.78rem">
                        <i class="bi bi-check2-circle text-success me-1"></i>
                        <strong>Proses Finish Good:</strong>
                        <span x-text="proses ? `${proses.id_proses} – ${proses.nama_proses}` : ''"></span>
                        <span style="color:#5d7283"
                              x-text="proses ? ` (SPH Std QC: ${proses.sph_standar_qc})` : ''"></span>
                    </div>
                    <input type="hidden" name="id_part_proses" :value="proses ? proses.id : ''">
                    @error('id_part_proses')<div class="text-danger mt-1" style="font-size:0.78rem">{{ $message }}</div>@enderror
                </div>

                {{-- Jam Start + Jam Finish --}}
                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start"
                           class="form-control form-control-sm @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish"
                           class="form-control form-control-sm @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Qty OK + Qty NG + Jenis NG (shared scope) --}}
                <div class="col-12"
                     x-data="Object.assign(taField(taJenisNg, '{{ addslashes($oldJenisNgTxt) }}', {{ $oldJenisNgId ?: 'null' }}), { qtyNg: {{ (int) old('qty_ng', 0) }} })">
                    <div class="row g-2">
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.8rem">Qty OK <span class="text-danger">*</span></label>
                            <input type="number" name="qty_ok"
                                   class="form-control form-control-sm @error('qty_ok') is-invalid @enderror"
                                   value="{{ old('qty_ok', 0) }}" min="0">
                            @error('qty_ok')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-6">
                            <label class="form-label" style="font-size:0.8rem">Qty NG <span class="text-danger">*</span></label>
                            <input type="number" name="qty_ng"
                                   class="form-control form-control-sm @error('qty_ng') is-invalid @enderror"
                                   value="{{ old('qty_ng', 0) }}" min="0"
                                   x-model.number="qtyNg">
                            @error('qty_ng')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>

                    {{-- Jenis NG — muncul kalau qty_ng > 0 --}}
                    <div x-show="qtyNg > 0" x-cloak
                         class="mt-2 p-3" style="background:#fff8f0;border:1px solid #fde8c8;border-radius:4px">
                        <label class="form-label mb-1" style="font-size:0.8rem">
                            <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Jenis NG
                            <span style="color:#6b7280;font-weight:400">(opsional)</span>
                        </label>
                        <div class="ta-wrap">
                            <input type="text"
                                   class="form-control form-control-sm ta-input @error('id_jenis_ng') is-invalid @enderror"
                                   x-model="query"
                                   @focus="openDd()"
                                   @blur="closeDd()"
                                   @keydown="onKey($event)"
                                   @input="onInput()"
                                   placeholder="Ketik kode atau nama jenis NG...">
                            <input type="hidden" name="id_jenis_ng" :value="val">
                            <div class="ta-dropdown" x-show="open" x-cloak>
                                <template x-if="filtered.length === 0">
                                    <div class="ta-empty">Tidak ditemukan</div>
                                </template>
                                <template x-for="(item, i) in filtered" :key="item.v">
                                    <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                         @mousedown.prevent="pick(item)" x-text="item.t"></div>
                                </template>
                            </div>
                        </div>
                        @error('id_jenis_ng')<div class="text-danger mt-1" style="font-size:0.78rem">{{ $message }}</div>@enderror
                    </div>
                </div>

            </div>{{-- /row --}}

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan LHP
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
    <h1 class="page-title"><i class="bi bi-check2-square"></i>Input LHP Quality</h1>
    <div class="action-btns">
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Input Baru
        </button>
        <button class="btn btn-outline-success btn-sm"
                onclick="document.getElementById('import-qc-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
        <button class="btn btn-outline-secondary btn-sm"
                onclick="document.getElementById('exportQcPanel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </button>
    </div>
</div>

{{-- Export Panel --}}
<x-export-modal
    panelId="exportQcPanel"
    :exportUrl="route('input.qc.export')"
    title="Export LHP Quality"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
/>

{{-- Import Panel --}}
<div id="import-qc-panel" class="erp-panel mb-3 {{ session('import_errors') !== null || session('import_success') ? '' : 'd-none' }}">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import LHP Quality dari Excel</span>
        <a href="{{ route('input.qc.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.qc.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label mb-1" style="font-size:0.8rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
            <div class="mt-2" style="font-size:0.75rem;color:#6c757d">
                Mulai dari baris ke-4 (baris 1 = header, baris 2 = keterangan, baris 3 = contoh).
                Proses <em>finish good</em> dipilih otomatis dari data master.
                Download template untuk melihat referensi data master.
            </div>
        </form>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('import_success'))
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('import_success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
            <div style="width:100%">
                <strong>{{ count(session('import_errors')) }} baris gagal diimport:</strong>
                <div style="max-height:180px;overflow-y:auto;margin-top:6px">
                    <ul class="mb-0" style="font-size:0.82rem">
                        @foreach(session('import_errors') as $err)
                            <li>{{ $err }}</li>
                        @endforeach
                    </ul>
                </div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Filter Bar --}}
<div class="filter-bar mb-3">
    <form method="GET" action="{{ route('input.qc.index') }}" style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;width:100%">
        <div class="filter-group">
            <label>Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
        </div>
        <div class="filter-group">
            <label>Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
        </div>
        <div class="filter-group" style="flex-direction:row;gap:6px;align-items:flex-end">
            <button type="submit" class="btn btn-secondary btn-sm">
                <i class="bi bi-funnel me-1"></i>Tampilkan
            </button>
            <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
        <div style="margin-left:auto;display:flex;align-items:flex-end">
            <span style="font-size:0.75rem;color:#5d7283;background:#f0f2f5;border:1px solid #d4dbe3;padding:4px 10px;">
                {{ $transaksi->total() }} record
            </span>
        </div>
    </form>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Daftar LHP Quality</div>
    <div style="overflow-x:auto">
        <table class="erp-table" style="min-width:1350px">
            <thead>
                <tr>
                    <th style="width:85px">Tgl Cek</th>
                    <th style="width:85px">Tgl Prod</th>
                    <th style="width:70px">Shift</th>
                    <th style="width:130px">Operator</th>
                    <th style="width:75px">Customer</th>
                    <th style="width:90px">Part No.</th>
                    <th style="width:130px">Part Name</th>
                    <th style="width:110px">Jam</th>
                    <th style="width:65px;text-align:right">Qty OK</th>
                    <th style="width:65px;text-align:right">Qty NG</th>
                    <th style="width:100px">Jenis NG</th>
                    <th style="width:65px;text-align:right">SPH Std</th>
                    <th style="width:65px;text-align:right">SPH Act</th>
                    <th style="width:70px;text-align:right">W. Kerja</th>
                    <th style="width:65px;text-align:right">CT Act</th>
                    <th style="width:60px;text-align:right">%</th>
                    <th style="width:70px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transaksi as $t)
                @php
                    $pp       = $t->partProses;
                    $part     = $pp?->part;
                    $totalQty = ($t->qty_ok ?? 0) + ($t->qty_ng ?? 0);
                    $sphStd   = $pp?->sph_standar_qc ?? 0;
                    $sphAct   = $t->sph_aktual;
                    $pct      = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($t->tgl_pengecekan)->format('d/m/Y') }}</td>
                    <td style="color:#5d7283">{{ \Carbon\Carbon::parse($t->tgl_produksi_part)->format('d/m/Y') }}</td>
                    <td>{{ $t->shift?->nama_shift ?? '—' }}</td>
                    <td>{{ $t->karyawanQc?->nama_karyawan ?? '—' }}</td>
                    <td style="font-size:0.75rem;color:#5d7283">{{ $part?->customer ?? '—' }}</td>
                    <td>{{ $part?->part_no ?? '—' }}</td>
                    <td style="font-size:0.75rem">{{ $part?->part_name ?? '—' }}</td>
                    <td style="white-space:nowrap;font-size:0.75rem">{{ $t->jam_start }} – {{ $t->jam_finish }}</td>
                    <td style="text-align:right;font-weight:600;color:#27ae60">{{ number_format($t->qty_ok) }}</td>
                    <td style="text-align:right;font-weight:600;color:#c0392b">
                        {{ number_format($t->qty_ng) }}
                        @if($totalQty > 0 && $t->qty_ng > 0)
                            <span style="font-size:0.65rem;font-weight:400;color:#888">
                                ({{ round($t->qty_ng / $totalQty * 100, 1) }}%)
                            </span>
                        @endif
                    </td>
                    <td style="font-size:0.72rem;color:#5d7283">
                        {{ $t->jenisNg ? $t->jenisNg->kode : '—' }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($sphStd) }}</td>
                    <td style="text-align:right;font-weight:600;color:{{ $sphAct >= $sphStd ? '#27ae60' : '#c0392b' }}">
                        {{ number_format($sphAct) }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($t->waktu_kerja_jam, 1) }}j</td>
                    <td style="text-align:right">{{ number_format($t->actual_cycle_time, 1) }}s</td>
                    <td style="text-align:right;font-weight:700;color:{{ $pct === null ? '#9ca3af' : ($pct >= 100 ? '#15803d' : '#b91c1c') }}">
                        {{ $pct !== null ? $pct . '%' : '—' }}
                    </td>
                    <td>
                        @if(auth()->user()->role === 'admin')
                        <div class="action-btns">
                            <a href="{{ route('input.qc.edit', $t) }}"
                               class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.qc.destroy', $t) }}"
                                  onsubmit="return confirm('Hapus data ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                        @else
                        <span style="font-size:0.72rem;color:#9ca3af">—</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="17" style="text-align:center;color:#888;padding:20px">Belum ada data LHP Quality pada rentang tanggal ini.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $transaksi->links() }}</div>

</div>{{-- /alpine outer scope --}}
@endsection

@php
$_taKaryawan = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taParts    = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_taJenisNg  = $jenisNg->map(fn($j) => ['v' => $j->id, 't' => "{$j->kode} — {$j->nama_ng}"])->values();
$_partsData  = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'             => $pr->id,
        'id_proses'      => $pr->id_proses,
        'nama_proses'    => $pr->nama_proses,
        'sph_standar_qc' => $pr->sph_standar_qc,
    ])->last(), // finish-good = last proses
])->values();
@endphp

@push('scripts')
<script>
/* ── Data from PHP ─────────────────────────────────────── */
const taKaryawan = @json($_taKaryawan);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);
const taJenisNg  = @json($_taJenisNg);

/* ── Generic typeahead component ───────────────────────── */
function taField(items, initText = '', initVal = null) {
    return {
        items, query: initText, val: initVal, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = '';
                }
            }, 180);
        },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) { this.query = item.t; this.val = item.v; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}

/* ── QC Part + auto finish-good proses ─────────────────── */
function qcPartSelector() {
    return {
        ...taField(taParts),
        proses: null,
        pick(item) {
            this.query = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part = partsData.find(p => p.id == item.v);
            this.proses = part ? part.proses : null;
        },
    };
}

/* ── Client-side validation ─────────────────────────────── */
(function () {
    const form = document.getElementById('formQc');
    if (!form) return;

    function clearErrs() {
        form.querySelectorAll('.cve').forEach(e => e.remove());
        form.querySelectorAll('.cvi').forEach(el => el.classList.remove('cvi', 'is-invalid'));
    }
    function mkErr(el, msg) {
        el.classList.add('is-invalid', 'cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve'; d.textContent = msg;
        el.insertAdjacentElement('afterend', d);
        ['input', 'change'].forEach(ev => el.addEventListener(ev, () => { el.classList.remove('is-invalid','cvi'); if(d.parentNode) d.remove(); }, {once:true}));
    }
    function mkTa(hiddenInput, msg) {
        const wrap = hiddenInput.closest('.ta-wrap');
        const vis  = wrap.querySelector('.ta-input');
        vis.classList.add('is-invalid', 'cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve'; d.textContent = msg;
        wrap.insertAdjacentElement('afterend', d);
        vis.addEventListener('input', () => { vis.classList.remove('is-invalid','cvi'); if(d.parentNode) d.remove(); }, {once:true});
    }

    form.addEventListener('submit', function (e) {
        clearErrs(); const bad = []; const f = n => form.querySelector(`[name="${n}"]`);

        const tgl1 = f('tgl_pengecekan');
        if (!tgl1.value) { mkErr(tgl1, 'Tanggal pengecekan wajib diisi.'); bad.push(tgl1); }

        const tgl2 = f('tgl_produksi_part');
        if (!tgl2.value) { mkErr(tgl2, 'Tanggal produksi part wajib diisi.'); bad.push(tgl2); }

        const shift = f('id_shift');
        if (!shift.value) { mkErr(shift, 'Shift wajib dipilih.'); bad.push(shift); }

        const kar = f('id_karyawan_qc');
        if (!kar.value) { mkTa(kar, 'QC Inspector wajib dipilih.'); bad.push(kar.closest('.ta-wrap').querySelector('.ta-input')); }

        const partProses = f('id_part_proses');
        if (!partProses.value) {
            const col  = partProses.closest('.col-12');
            const wrap = col.querySelector('.ta-wrap');
            const vis  = wrap.querySelector('.ta-input');
            vis.classList.add('is-invalid', 'cvi');
            const d = document.createElement('div');
            d.className = 'invalid-feedback d-block cve';
            d.textContent = 'Part (Finish Good) wajib dipilih.';
            wrap.insertAdjacentElement('afterend', d);
            vis.addEventListener('input', () => { vis.classList.remove('is-invalid','cvi'); if(d.parentNode) d.remove(); }, {once:true});
            bad.push(vis);
        }

        const jamStart = f('jam_start');
        if (!jamStart.value) { mkErr(jamStart, 'Jam start wajib diisi.'); bad.push(jamStart); }

        const jamFinish = f('jam_finish');
        if (!jamFinish.value) { mkErr(jamFinish, 'Jam finish wajib diisi.'); bad.push(jamFinish); }

        if (bad.length) { e.preventDefault(); bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    });
})();
</script>
@endpush
