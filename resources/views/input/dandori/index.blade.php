@extends('layouts.app')
@section('title', 'LHP Dandori')

@php
$oldKaryawanId  = old('id_karyawan');
$oldKaryawanObj = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawanObj?->nama_karyawan ?? '';

$oldMesinId  = old('id_mesin');
$oldMesinObj = $mesin->firstWhere('id', $oldMesinId);
$oldMesinTxt = $oldMesinObj ? "{$oldMesinObj->id_mesin} - {$oldMesinObj->nama_mesin} (Line {$oldMesinObj->line})" : '';
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
     style="position:fixed;top:0;right:0;height:100vh;width:700px;max-width:100vw;background:#fff;z-index:1050;display:flex;flex-direction:column;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-tools me-2"></i>Input Dandori
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

        @if($karyawan->isEmpty() || $mesin->isEmpty() || $parts->isEmpty() || $losstimes->isEmpty())
        <div class="alert alert-warning mb-3" style="font-size:0.82rem">
            <strong>Master data belum lengkap:</strong>
            @if($karyawan->isEmpty()) <a href="{{ route('master.karyawan.index') }}">Tambah Karyawan/Operator</a>. @endif
            @if($mesin->isEmpty()) <a href="{{ route('master.mesin.index') }}">Tambah Mesin</a>. @endif
            @if($parts->isEmpty()) <a href="{{ route('master.part.index') }}">Tambah Part</a>. @endif
            @if($losstimes->isEmpty()) <a href="{{ route('master.losstime.index') }}">Tambah Loss Time</a>. @endif
        </div>
        @endif

        <div x-data="dandoriMain()">
            <form method="POST" action="{{ route('input.dandori.store') }}" id="formDandori" novalidate>
                @csrf

                {{-- ── INFORMASI SESI ──────────────────────────────── --}}
                <div style="font-size:0.7rem;font-weight:700;letter-spacing:.06em;color:#6b7280;text-transform:uppercase;margin-bottom:8px">
                    Informasi Sesi
                </div>
                <div class="row g-2 mb-3 pb-3" style="border-bottom:1px solid #e5e7eb">

                    {{-- Tanggal --}}
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">Tanggal <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal"
                               class="form-control form-control-sm @error('tanggal') is-invalid @enderror"
                               value="{{ old('tanggal', date('Y-m-d')) }}">
                        @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Shift --}}
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">Shift <span class="text-danger">*</span></label>
                        <select name="id_shift" class="form-select form-select-sm @error('id_shift') is-invalid @enderror">
                            <option value="">-- Pilih Shift --</option>
                            @foreach($shifts as $shift)
                                <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                    {{ $shift->nama_shift }} ({{ $shift->jam_start }}–{{ $shift->jam_finish }})
                                </option>
                            @endforeach
                        </select>
                        @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Operator --}}
                    <div class="col-12"
                         x-data="taField(taKaryawan, '{{ $oldKaryawanTxt }}', {{ $oldKaryawanId ?: 'null' }})">
                        <label class="form-label" style="font-size:0.8rem">Operator <span class="text-danger">*</span></label>
                        <div class="ta-wrap">
                            <input type="text"
                                   class="form-control form-control-sm ta-input @error('id_karyawan') is-invalid @enderror"
                                   x-model="query" @focus="openDd()" @blur="closeDd()"
                                   @keydown="onKey($event)" @input="onInput()"
                                   placeholder="Ketik nama operator...">
                            <input type="hidden" name="id_karyawan" :value="val">
                            <div class="ta-dropdown" x-show="open" x-cloak>
                                <template x-if="filtered.length === 0"><div class="ta-empty">Tidak ditemukan</div></template>
                                <template x-for="(item, i) in filtered" :key="item.v">
                                    <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                         @mousedown.prevent="pick(item)" x-text="item.t"></div>
                                </template>
                            </div>
                        </div>
                        @error('id_karyawan')<div class="invalid-feedback d-block" style="font-size:0.78rem">{{ $message }}</div>@enderror
                    </div>

                    {{-- Mesin --}}
                    <div class="col-12"
                         x-data="taField(taMesin, '{{ $oldMesinTxt }}', {{ $oldMesinId ?: 'null' }})">
                        <label class="form-label" style="font-size:0.8rem">Mesin <span class="text-danger">*</span></label>
                        <div class="ta-wrap">
                            <input type="text"
                                   class="form-control form-control-sm ta-input @error('id_mesin') is-invalid @enderror"
                                   x-model="query" @focus="openDd()" @blur="closeDd()"
                                   @keydown="onKey($event)" @input="onInput()"
                                   placeholder="Ketik ID atau nama mesin...">
                            <input type="hidden" name="id_mesin" :value="val">
                            <div class="ta-dropdown" x-show="open" x-cloak>
                                <template x-if="filtered.length === 0"><div class="ta-empty">Tidak ditemukan</div></template>
                                <template x-for="(item, i) in filtered" :key="item.v">
                                    <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                         @mousedown.prevent="pick(item)" x-text="item.t"></div>
                                </template>
                            </div>
                        </div>
                        @error('id_mesin')<div class="invalid-feedback d-block" style="font-size:0.78rem">{{ $message }}</div>@enderror
                    </div>

                    {{-- Part --}}
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">Part <span class="text-danger">*</span></label>
                        <div class="ta-wrap" id="partTaWrapDandori">
                            <input type="text"
                                   class="form-control form-control-sm ta-input"
                                   x-model="partQuery"
                                   @focus="partOpen = true; partIdx = -1"
                                   @blur="partCloseDd()"
                                   @keydown="partKey($event)"
                                   @input="partVal = null; partOpen = true; partIdx = -1"
                                   placeholder="Ketik part no atau nama...">
                            <div class="ta-dropdown" x-show="partOpen" x-cloak>
                                <template x-if="partFiltered.length === 0"><div class="ta-empty">Tidak ditemukan</div></template>
                                <template x-for="(item, i) in partFiltered" :key="item.v">
                                    <div class="ta-item" :class="{ 'ta-active': i === partIdx }"
                                         @mousedown.prevent="partPick(item)" x-text="item.t"></div>
                                </template>
                            </div>
                        </div>
                    </div>

                    {{-- Proses --}}
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">Proses <span class="text-danger">*</span></label>
                        <select name="id_part_proses"
                                class="form-select form-select-sm @error('id_part_proses') is-invalid @enderror"
                                :disabled="prosesList.length === 0"
                                @change="onProsesChange($event.target.value)">
                            <option value="">-- Pilih Part dulu --</option>
                            <template x-for="p in prosesList" :key="p.id">
                                <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                            </template>
                        </select>
                        @error('id_part_proses')<div class="invalid-feedback d-block" style="font-size:0.78rem">{{ $message }}</div>@enderror
                    </div>

                    {{-- Progressive badge --}}
                    <div class="col-12" x-show="isProgressive" x-cloak>
                        <div class="alert alert-info py-2 mb-0 d-flex align-items-center gap-2" style="font-size:0.8rem">
                            <i class="bi bi-info-circle-fill"></i>
                            <span>Proses <strong>PROGRESSIVE</strong> — standar waktu pakai <code>std_menit_progressive</code>.</span>
                        </div>
                    </div>

                </div>{{-- /sesi fields --}}

                {{-- ── RINCIAN LOSS TIME ───────────────────────────── --}}
                <div style="font-size:0.7rem;font-weight:700;letter-spacing:.06em;color:#6b7280;text-transform:uppercase;margin-bottom:8px">
                    Rincian Loss Time
                </div>

                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px">
                    <span style="font-size:0.78rem;color:#6b7280">Tambahkan setiap aktivitas loss time dalam sesi ini.</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRow()" style="font-size:0.78rem">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                    </button>
                </div>

                @error('rincian')<div class="text-danger mb-2" style="font-size:0.8rem">{{ $message }}</div>@enderror

                <div class="table-responsive mb-2">
                    <table class="table table-bordered table-sm align-middle mb-0" style="font-size:0.8rem">
                        <thead class="table-light">
                            <tr>
                                <th>Jenis Loss Time</th>
                                <th style="width:110px">Aktual (mnt) <span class="text-danger">*</span></th>
                                <th style="width:85px;text-align:center">Std (mnt)</th>
                                <th style="width:85px;text-align:center">Over (mnt)</th>
                                <th style="width:36px"></th>
                            </tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in rows" :key="index">
                                <tr>
                                    <td>
                                        <div class="ta-wrap">
                                            <input type="text"
                                                   class="form-control form-control-sm ta-input"
                                                   x-model="row.ltQuery"
                                                   @focus="row.ltOpen = true; row.ltIdx = -1"
                                                   @blur="ltClose(row)"
                                                   @keydown="ltKey(row, $event)"
                                                   @input="row.id_losstime = ''; row.ltOpen = true; row.ltIdx = -1; onLosstimeChange(row)"
                                                   placeholder="Ketik kode atau jenis...">
                                            <input type="hidden"
                                                   :name="`rincian[${index}][id_losstime]`"
                                                   :value="row.id_losstime">
                                            <div class="ta-dropdown" x-show="row.ltOpen" x-cloak>
                                                <template x-if="ltFiltered(row).length === 0">
                                                    <div class="ta-empty">Tidak ditemukan</div>
                                                </template>
                                                <template x-for="(item, i) in ltFiltered(row)" :key="item.v">
                                                    <div class="ta-item"
                                                         :class="{ 'ta-active': i === row.ltIdx }"
                                                         @mousedown.prevent="ltPick(row, item)"
                                                         x-text="item.t"></div>
                                                </template>
                                            </div>
                                        </div>
                                    </td>
                                    <td>
                                        <input type="number"
                                               :name="`rincian[${index}][actual_menit]`"
                                               class="form-control form-control-sm"
                                               x-model.number="row.actual_menit"
                                               @input="onActualChange(row)"
                                               step="0.1" min="0.1"
                                               placeholder="0.0" required>
                                    </td>
                                    <td class="text-center text-muted"
                                        x-text="row.std_menit > 0 ? row.std_menit.toFixed(1) : '—'">
                                    </td>
                                    <td class="text-center fw-semibold"
                                        :class="row.over_menit > 0 ? 'text-danger' : (row.std_menit > 0 ? 'text-success' : 'text-muted')"
                                        x-text="row.std_menit > 0
                                            ? (row.over_menit > 0 ? '+' + row.over_menit.toFixed(1) : 'OK')
                                            : '—'">
                                    </td>
                                    <td class="text-center">
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger py-0 px-1"
                                                @click="removeRow(index)"
                                                :disabled="rows.length === 1"
                                                title="Hapus baris">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                        <tfoot class="table-light">
                            <tr class="fw-semibold" style="font-size:0.82rem">
                                <td class="text-end pe-2" style="color:#374151">Total</td>
                                <td x-text="totalActual.toFixed(1) + ' mnt'" style="color:#374151"></td>
                                <td></td>
                                <td class="text-center"
                                    :class="totalOver > 0 ? 'text-danger' : 'text-success'"
                                    x-text="totalOver > 0 ? '+' + totalOver.toFixed(1) + ' mnt' : 'OK'">
                                </td>
                                <td></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <p style="font-size:0.75rem;color:#6b7280;margin-bottom:16px">
                    <i class="bi bi-info-circle me-1"></i>
                    Kolom <strong>Std</strong> dan <strong>Over</strong> otomatis terisi setelah memilih jenis loss time dan memasukkan waktu aktual.
                </p>

                <div style="padding-top:14px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                    <button type="submit" class="btn btn-primary btn-sm" style="flex:1">
                        <i class="bi bi-save me-1"></i>Simpan Data Dandori
                    </button>
                    <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary btn-sm">
                        Batal
                    </button>
                </div>

            </form>
        </div>{{-- /dandoriMain --}}

    </div>{{-- /drawer body --}}
</div>{{-- /drawer panel --}}

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-tools"></i>Input LHP Dandori</h1>
    <div class="d-flex gap-2">
        @if(auth()->user()->role === 'admin')
        <button type="button" class="btn btn-outline-secondary btn-sm"
                onclick="document.getElementById('importPanel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
        @endif
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Input Baru
        </button>
        <button class="btn btn-outline-secondary btn-sm"
                onclick="document.getElementById('exportDandoriPanel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </button>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('import_success'))
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('import_success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="alert alert-warning alert-dismissible fade show mb-3">
        <div class="fw-semibold mb-1"><i class="bi bi-exclamation-triangle-fill me-1"></i>Beberapa baris gagal diimport:</div>
        <ul class="mb-0 ps-3" style="font-size:0.85rem">
            @foreach(session('import_errors') as $err)
                <li>{{ $err }}</li>
            @endforeach
        </ul>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Export Panel --}}
<x-export-modal
    panelId="exportDandoriPanel"
    :exportUrl="route('input.dandori.export')"
    title="Export Dandori"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
/>

@if(auth()->user()->role === 'admin')
<div id="importPanel" class="{{ session('import_success') || session('import_errors') ? '' : 'd-none' }} erp-panel mb-3" style="max-width:680px">
    <div class="erp-panel-header d-flex justify-content-between align-items-center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Data Dandori dari Excel</span>
        <a href="{{ route('input.dandori.import-template') }}"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <p class="text-muted mb-3" style="font-size:0.85rem">
            <i class="bi bi-info-circle me-1"></i>
            Setiap baris Excel = satu entri loss time. Ulangi kolom sesi untuk setiap jenis loss time dalam satu sesi dandori.
        </p>
        <form method="POST" action="{{ route('input.dandori.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="d-flex align-items-end gap-3">
                <div class="flex-grow-1">
                    <label class="form-label mb-1" style="font-size:0.85rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control form-control-sm @error('file') is-invalid @enderror"
                           accept=".xlsx,.xls,.csv" required>
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <button type="submit" class="btn btn-primary btn-sm" style="white-space:nowrap">
                    <i class="bi bi-upload me-1"></i>Upload & Import
                </button>
            </div>
        </form>
    </div>
</div>
@endif

<div class="filter-bar mb-3">
    <form method="GET" action="{{ route('input.dandori.index') }}"
          style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;width:100%">
        <div class="filter-group">
            <label>Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
        </div>
        <div class="filter-group">
            <label>Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
        </div>
        <div class="filter-group" style="flex-direction:row;gap:6px;align-items:flex-end">
            <button type="submit" class="btn btn-secondary btn-sm"><i class="bi bi-funnel me-1"></i>Tampilkan</button>
            <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
        <div style="margin-left:auto;display:flex;align-items:flex-end">
            <span style="font-size:0.75rem;color:#5d7283;background:#f0f2f5;border:1px solid #d4dbe3;padding:4px 10px;">
                {{ $rincian->total() }} baris
            </span>
        </div>
    </form>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Daftar LHP Dandori</div>
    <div style="overflow-x:auto">
        <table class="erp-table" style="min-width:1350px">
            <thead>
                <tr>
                    <th style="width:85px">Tanggal</th>
                    <th style="width:65px">Shift</th>
                    <th style="width:140px">Operator</th>
                    <th style="width:55px">Mesin</th>
                    <th style="width:110px">Nama Mesin</th>
                    <th style="width:85px">Part No</th>
                    <th style="width:120px">Part Name</th>
                    <th style="width:40px;text-align:center">ID<br>Proses</th>
                    <th style="width:110px">Nama Proses</th>
                    <th style="width:65px">Kode LT</th>
                    <th style="width:140px">Jenis Loss Time</th>
                    <th style="width:70px;text-align:right">Actual<br>(mnt)</th>
                    <th style="width:60px;text-align:right">Std<br>(mnt)</th>
                    <th style="width:65px;text-align:right">Over<br>(mnt)</th>
                    @if(auth()->user()->role === 'admin')
                    <th style="width:70px">Aksi</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @php $prevSession = ''; @endphp
            @forelse($rincian as $r)
                @php
                    $pp  = $r->partProses;
                    $part = $pp?->part;
                    $sessionKey   = $r->tanggal->format('Ymd').'_'.$r->id_shift.'_'.$r->id_karyawan.'_'.$r->id_mesin.'_'.$r->id_part_proses;
                    $isNewSession = $sessionKey !== $prevSession;
                    $prevSession  = $sessionKey;
                @endphp
                <tr style="{{ $isNewSession && !$loop->first ? 'border-top:2px solid #d1d5db' : '' }}">
                    <td style="{{ $isNewSession ? 'font-weight:600' : 'color:#c4c9d4;font-size:0.78rem' }}">
                        {{ $r->tanggal->format('d/m/Y') }}
                    </td>
                    <td style="{{ !$isNewSession ? 'color:#c4c9d4;font-size:0.78rem' : '' }}">{{ $r->shift?->nama_shift ?? '—' }}</td>
                    <td style="{{ !$isNewSession ? 'color:#c4c9d4;font-size:0.78rem' : '' }}">{{ $r->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td style="{{ !$isNewSession ? 'color:#c4c9d4' : 'font-weight:600' }}">{{ $r->mesin?->id_mesin ?? '—' }}</td>
                    <td style="font-size:0.75rem;{{ !$isNewSession ? 'color:#c4c9d4' : '' }}">{{ $r->mesin?->nama_mesin ?? '—' }}</td>
                    <td style="{{ !$isNewSession ? 'color:#c4c9d4;font-size:0.78rem' : '' }}">{{ $part?->part_no ?? '—' }}</td>
                    <td style="font-size:0.75rem;{{ !$isNewSession ? 'color:#c4c9d4' : '' }}">{{ $part?->part_name ?? '—' }}</td>
                    <td style="text-align:center;{{ !$isNewSession ? 'color:#c4c9d4' : 'font-weight:600' }}">{{ $pp?->id_proses ?? '—' }}</td>
                    <td style="font-size:0.75rem;{{ !$isNewSession ? 'color:#c4c9d4' : '' }}">{{ $pp?->nama_proses ?? '—' }}</td>
                    <td style="font-weight:600;letter-spacing:0.3px">{{ $r->losstime?->kode ?? '—' }}</td>
                    <td style="font-size:0.78rem">{{ $r->losstime?->jenis_losstime ?? '—' }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($r->actual_menit, 1) }}</td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($r->std_menit, 1) }}</td>
                    <td style="text-align:right;font-weight:700">
                        @if($r->over_menit > 0)
                            <span style="color:#c0392b">+{{ number_format($r->over_menit, 1) }}</span>
                        @else
                            <span style="color:#27ae60;font-weight:400">OK</span>
                        @endif
                    </td>
                    @if(auth()->user()->role === 'admin')
                    <td>
                        <form method="POST" action="{{ route('input.dandori.destroy', $r) }}"
                              onsubmit="return confirm('Hapus baris loss time ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                    @endif
                </tr>
            @empty
                <tr>
                    <td colspan="{{ auth()->user()->role === 'admin' ? 15 : 14 }}"
                        style="text-align:center;color:#888;padding:20px">
                        Belum ada data Dandori pada rentang tanggal ini.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $rincian->links() }}</div>

</div>{{-- /alpine outer scope --}}
@endsection

@php
$_taKaryawan   = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taMesin      = $mesin->map(fn($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
$_taParts      = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData    = $parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses])->values()])->values();
$_losstimeData = $losstimes->map(fn($lt) => ['id' => $lt->id, 'kode' => $lt->kode, 'jenis_losstime' => $lt->jenis_losstime, 'std_menit_default' => (float)($lt->std_menit_default ?? 0), 'std_menit_progressive' => (float)($lt->std_menit_progressive ?? 0)])->values();
@endphp

@push('scripts')
<script>
/* ── Static data from PHP ────────────────────────────────── */
const taKaryawan   = @json($_taKaryawan);
const taMesin      = @json($_taMesin);
const taParts      = @json($_taParts);
const partsData    = @json($_partsData);
const losstimeData = @json($_losstimeData);

/* ── Generic typeahead component (shared) ───────────────── */
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
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = '';
                }
            }, 180);
        },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) {
            this.query = item.t; this.val = item.v;
            this.open = false;  this.idx = -1;
        },
        onKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault(); this.open = true;
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

/* ── Main Dandori form component ────────────────────────── */
function dandoriMain() {
    return {
        /* Part typeahead state */
        partQuery: '',
        partVal: null,
        partOpen: false,
        partIdx: -1,

        get partFiltered() {
            if (!this.partQuery.trim()) return taParts.slice(0, 60);
            const q = this.partQuery.toLowerCase();
            return taParts.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },

        partCloseDd() {
            setTimeout(() => {
                this.partOpen = false;
                if (this.partVal && !taParts.find(i => i.v == this.partVal && i.t === this.partQuery)) {
                    this.partVal = null; this.partQuery = '';
                    this.prosesList = [];
                    this.isProgressive = false;
                }
            }, 180);
        },

        partPick(item) {
            this.partQuery = item.t;
            this.partVal   = item.v;
            this.partOpen  = false;
            this.partIdx   = -1;
            const part = partsData.find(p => p.id == item.v);
            this.prosesList    = part ? part.proses : [];
            this.isProgressive = false;
            this.rows.forEach(r => this.recompute(r));
        },

        partKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault(); this.partOpen = true;
                this.partIdx = Math.min(this.partIdx + 1, this.partFiltered.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.partIdx = Math.max(this.partIdx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.partIdx >= 0 && this.partFiltered[this.partIdx]) this.partPick(this.partFiltered[this.partIdx]);
            } else if (e.key === 'Escape') {
                this.partOpen = false;
            }
        },

        /* Proses */
        prosesList: [],
        isProgressive: false,

        onProsesChange(prosesId) {
            const p = this.prosesList.find(p => p.id == prosesId);
            this.isProgressive = p
                ? p.nama_proses.trim().toUpperCase() === 'PROGRESSIVE'
                : false;
            this.rows.forEach(r => this.recompute(r));
        },

        /* Losstime typeahead (per-row state in row objects) */
        ltItems: losstimeData.map(lt => ({ v: lt.id, t: `${lt.kode} — ${lt.jenis_losstime}` })),

        ltFiltered(row) {
            if (!row.ltQuery.trim()) return this.ltItems.slice(0, 60);
            const q = row.ltQuery.toLowerCase();
            return this.ltItems.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },

        ltPick(row, item) {
            row.ltQuery      = item.t;
            row.id_losstime  = item.v;
            row.ltOpen       = false;
            row.ltIdx        = -1;
            this.recompute(row);
        },

        ltClose(row) {
            setTimeout(() => {
                row.ltOpen = false;
                if (row.id_losstime && !this.ltItems.find(i => i.v == row.id_losstime && i.t === row.ltQuery)) {
                    row.id_losstime = '';
                    row.ltQuery     = '';
                    this.recompute(row);
                }
            }, 180);
        },

        ltKey(row, e) {
            const f = this.ltFiltered(row);
            if (e.key === 'ArrowDown') {
                e.preventDefault(); row.ltOpen = true;
                row.ltIdx = Math.min(row.ltIdx + 1, f.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                row.ltIdx = Math.max(row.ltIdx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (row.ltIdx >= 0 && f[row.ltIdx]) this.ltPick(row, f[row.ltIdx]);
            } else if (e.key === 'Escape') {
                row.ltOpen = false;
            }
        },

        /* Rows */
        rows: [{ id_losstime: '', ltQuery: '', ltOpen: false, ltIdx: -1, actual_menit: '', std_menit: 0, over_menit: 0 }],

        addRow() {
            this.rows.push({ id_losstime: '', ltQuery: '', ltOpen: false, ltIdx: -1, actual_menit: '', std_menit: 0, over_menit: 0 });
        },

        removeRow(index) {
            if (this.rows.length > 1) this.rows.splice(index, 1);
        },

        onLosstimeChange(row) { this.recompute(row); },
        onActualChange(row)   { this.recompute(row); },

        recompute(row) {
            const lt = losstimeData.find(l => l.id == row.id_losstime);
            if (!lt) { row.std_menit = 0; row.over_menit = 0; return; }

            row.std_menit = this.isProgressive
                ? (lt.std_menit_progressive || lt.std_menit_default || 0)
                : (lt.std_menit_default || 0);

            const actual = parseFloat(row.actual_menit) || 0;
            row.over_menit = Math.max(0, actual - row.std_menit);
        },

        get totalActual() {
            return this.rows.reduce((s, r) => s + (parseFloat(r.actual_menit) || 0), 0);
        },
        get totalOver() {
            return this.rows.reduce((s, r) => s + (r.over_menit || 0), 0);
        },
    };
}

/* ── Client-side validation ─────────────────────────────── */
(function () {
    const form = document.getElementById('formDandori');
    if (!form) return;

    function clearErrs() {
        form.querySelectorAll('.cve').forEach(e => e.remove());
        form.querySelectorAll('.cvi').forEach(el => el.classList.remove('cvi', 'is-invalid'));
    }

    function mkErr(el, msg) {
        el.classList.add('is-invalid', 'cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve';
        d.textContent = msg;
        el.insertAdjacentElement('afterend', d);
        ['input', 'change'].forEach(ev =>
            el.addEventListener(ev, () => {
                el.classList.remove('is-invalid', 'cvi');
                if (d.parentNode) d.remove();
            }, { once: true })
        );
    }

    function mkTa(hiddenInput, msg) {
        const wrap = hiddenInput.closest('.ta-wrap');
        const vis  = wrap.querySelector('.ta-input');
        vis.classList.add('is-invalid', 'cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve';
        d.textContent = msg;
        wrap.insertAdjacentElement('afterend', d);
        vis.addEventListener('input', () => {
            vis.classList.remove('is-invalid', 'cvi');
            if (d.parentNode) d.remove();
        }, { once: true });
    }

    form.addEventListener('submit', function (e) {
        clearErrs();
        const bad = [];
        const f   = n => form.querySelector(`[name="${n}"]`);

        const tanggal = f('tanggal');
        if (!tanggal.value) { mkErr(tanggal, 'Tanggal wajib diisi.'); bad.push(tanggal); }

        const shift = f('id_shift');
        if (!shift.value) { mkErr(shift, 'Shift wajib dipilih.'); bad.push(shift); }

        const kar = f('id_karyawan');
        if (!kar.value) { mkTa(kar, 'Operator wajib dipilih.'); bad.push(kar.closest('.ta-wrap').querySelector('.ta-input')); }

        const mes = f('id_mesin');
        if (!mes.value) { mkTa(mes, 'Mesin wajib dipilih.'); bad.push(mes.closest('.ta-wrap').querySelector('.ta-input')); }

        /* Part + Proses */
        const pro = f('id_part_proses');
        if (!pro.value) {
            if (pro.disabled) {
                const partWrap = document.getElementById('partTaWrapDandori');
                if (partWrap) {
                    const vis = partWrap.querySelector('.ta-input');
                    vis.classList.add('is-invalid', 'cvi');
                    const d = document.createElement('div');
                    d.className = 'invalid-feedback d-block cve';
                    d.textContent = 'Part wajib dipilih terlebih dahulu.';
                    partWrap.insertAdjacentElement('afterend', d);
                    vis.addEventListener('input', () => {
                        vis.classList.remove('is-invalid', 'cvi');
                        if (d.parentNode) d.remove();
                    }, { once: true });
                    bad.push(vis);
                }
            } else {
                mkErr(pro, 'Proses wajib dipilih.');
                bad.push(pro);
            }
        }

        /* Rincian rows */
        let firstRowErr = null;
        form.querySelectorAll('input[type="hidden"][name*="[id_losstime]"]').forEach(hidden => {
            if (!hidden.value) {
                const wrap = hidden.closest('.ta-wrap');
                const vis  = wrap.querySelector('.ta-input');
                vis.classList.add('is-invalid', 'cvi');
                const d = document.createElement('div');
                d.className = 'invalid-feedback d-block cve';
                d.textContent = 'Pilih jenis loss time.';
                wrap.insertAdjacentElement('afterend', d);
                vis.addEventListener('input', () => {
                    vis.classList.remove('is-invalid', 'cvi');
                    if (d.parentNode) d.remove();
                }, { once: true });
                if (!firstRowErr) firstRowErr = vis;
            }
        });
        form.querySelectorAll('input[name*="[actual_menit]"]').forEach(inp => {
            if (!inp.value || parseFloat(inp.value) <= 0) {
                mkErr(inp, 'Aktual menit wajib diisi (min 0.1).');
                if (!firstRowErr) firstRowErr = inp;
            }
        });
        if (firstRowErr) bad.push(firstRowErr);

        if (bad.length) {
            e.preventDefault();
            bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();
</script>
@endpush
