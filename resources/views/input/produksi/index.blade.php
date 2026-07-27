@extends('layouts.app')
@section('title', 'LHP Produksi')
@section('suppress_layout_flash', 'yes')

@php
$oldKaryawanId  = old('id_karyawan');
$oldKaryawan    = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawan?->nama_karyawan ?? '';

$oldMesinId  = old('id_mesin');
$oldMesinObj = $mesin->firstWhere('id', $oldMesinId);
$oldMesinTxt = $oldMesinObj ? "{$oldMesinObj->id_mesin} - {$oldMesinObj->nama_mesin} (Line {$oldMesinObj->line})" : '';

$oldAdaNg      = old('ada_ng', false);
$oldJenisNgId  = old('id_jenis_ng');
$oldJenisNgObj = $jenisNg->firstWhere('id', $oldJenisNgId);
$oldJenisNgTxt = $oldJenisNgObj ? "{$oldJenisNgObj->kode} — {$oldJenisNgObj->nama_ng}" : '';
@endphp

@section('content')

{{-- ══ SATU ALPINE SCOPE — mengontrol drawer sekaligus konten halaman ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }}, filterOpen: {{ $filterActive ? 'true' : 'false' }} }">

@if(! auth()->user()->isGuest())
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
     style="position:fixed;top:0;right:0;height:100dvh;width:640px;max-width:100vw;z-index:1050;">
    <div style="display:flex;flex-direction:column;height:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-clipboard-data me-2"></i>Input LHP Produksi
        </div>
        <button type="button" @click="drawerOpen = false"
                style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;padding:0;line-height:1">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Body (scrollable) --}}
    <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:24px;overscroll-behavior:contain">

        @if($errors->any())
        <div class="alert alert-danger mb-3" style="font-size:0.92rem">
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        @if($karyawan->isEmpty() || $mesin->isEmpty() || $parts->isEmpty())
        <div class="alert alert-warning mb-3" style="font-size:0.92rem">
            <strong>Master data belum lengkap:</strong>
            @if($karyawan->isEmpty()) <a href="{{ route('master.karyawan.index') }}">Tambah Karyawan/Operator</a>. @endif
            @if($mesin->isEmpty()) <a href="{{ route('master.mesin.index') }}">Tambah Mesin</a>. @endif
            @if($parts->isEmpty()) <a href="{{ route('master.part.index') }}">Tambah Part</a>. @endif
        </div>
        @endif

        <form method="POST" action="{{ route('input.produksi.store') }}" id="formProduksi" novalidate>
            @csrf
            <div class="row g-3">

                {{-- Tanggal + Shift --}}
                <div class="col-6">
                    <label class="form-label" style="font-size:0.92rem">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi"
                           class="form-control @error('tanggal_produksi') is-invalid @enderror"
                           value="{{ old('tanggal_produksi', date('Y-m-d')) }}">
                    @error('tanggal_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <label class="form-label" style="font-size:0.92rem">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
                        @foreach($shifts as $s)
                            <option value="{{ $s->id }}" {{ old('id_shift') == $s->id ? 'selected' : '' }}>
                                {{ $s->nama_shift }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Operator --}}
                <div class="col-12"
                     x-data="taField(taKaryawan, '{{ $oldKaryawanTxt }}', {{ $oldKaryawanId ?: 'null' }})">
                    <label class="form-label" style="font-size:0.92rem">Operator <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input @error('id_karyawan') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik nama operator...">
                        <input type="hidden" name="id_karyawan" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_karyawan')<div class="invalid-feedback d-block" style="font-size:0.88rem">{{ $message }}</div>@enderror
                </div>

                {{-- Mesin --}}
                <div class="col-12"
                     x-data="taField(taMesin, '{{ $oldMesinTxt }}', {{ $oldMesinId ?: 'null' }})">
                    <label class="form-label" style="font-size:0.92rem">Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input @error('id_mesin') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik ID atau nama mesin...">
                        <input type="hidden" name="id_mesin" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_mesin')<div class="invalid-feedback d-block" style="font-size:0.88rem">{{ $message }}</div>@enderror
                </div>

                {{-- Part + Proses --}}
                <div class="col-12" x-data="produksiPartSelector()">
                    <label class="form-label" style="font-size:0.92rem">Part <span class="text-danger">*</span></label>
                    <div class="ta-wrap" style="margin-bottom:8px">
                        <input type="text" class="form-control ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik part no atau nama...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    <label class="form-label" style="font-size:0.92rem">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses"
                            class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length===0">
                        <option value="">-- Pilih Part dulu --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id"
                                    x-text="`${p.id_proses} – ${p.nama_proses} (SPH: ${p.sph_standar_produksi})`">
                            </option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback d-block" style="font-size:0.88rem">{{ $message }}</div>@enderror
                </div>

                {{-- Jam Start / Finish / Qty --}}
                <div class="col-4">
                    <label class="form-label" style="font-size:0.92rem">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start"
                           class="form-control @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-4">
                    <label class="form-label" style="font-size:0.92rem">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish"
                           class="form-control @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-4">
                    <label class="form-label" style="font-size:0.92rem">Qty Aktual <span class="text-danger">*</span></label>
                    <input type="number" name="qty_aktual"
                           class="form-control @error('qty_aktual') is-invalid @enderror"
                           value="{{ old('qty_aktual') }}" min="1">
                    @error('qty_aktual')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Ada NG? --}}
                <div class="col-12" x-data="{ adaNg: {{ $oldAdaNg ? 'true' : 'false' }} }">
                    <div style="background:#fafafa;border:1px solid #e5e7eb;border-radius:4px;padding:12px">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_ng"
                                   id="ada_ng_drawer" value="1" x-model="adaNg"
                                   {{-- Un-tick harus benar-benar mengosongkan rows Alpine di partial
                                        ng-rows (bukan cuma menyembunyikannya lewat x-show), supaya
                                        ng_count ikut jadi 0 dan konsisten dengan ng=[] yang dikirim
                                        prepareForValidation(). Tanpa ini ng_count bisa membawa sisa
                                        hitungan baris lama saat checkbox dilepas. --}}
                                   @change="if (!adaNg) { const w = $el.closest('[x-data]').querySelector('.ng-rows'); if (w) Alpine.$data(w).rows = []; }"
                                   {{ $oldAdaNg ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="ada_ng_drawer"
                                   style="font-size:0.92rem;color:#374151">
                                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Ada NG Proses?
                            </label>
                        </div>
                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', [
                                'jenisNg' => $jenisNg,
                                'initial' => old('ng', []),
                            ])
                        </div>
                    </div>
                </div>

            </div>{{-- /row --}}

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary" style="flex:1;font-size:0.95rem">
                    <i class="bi bi-save me-1"></i>Simpan LHP
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary" style="font-size:0.95rem">
                    Batal
                </button>
            </div>
        </form>
    </div>{{-- /drawer body --}}
    </div>{{-- /inner flex container --}}
</div>{{-- /drawer panel --}}
@endif

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-clipboard-data"></i>Input LHP Produksi</h1>
    <div class="action-btns">
        @if(! auth()->user()->isGuest())
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Input Baru
        </button>
        @endif
        <button type="button" @click="filterOpen = !filterOpen"
                :class="filterOpen ? 'btn-secondary' : 'btn-outline-secondary'"
                class="btn btn-sm" style="position:relative">
            <i class="bi bi-funnel me-1"></i>Filter
            @if($filterActive)
            <span style="position:absolute;top:-5px;right:-5px;width:8px;height:8px;background:#ef4444;border-radius:50%;border:1.5px solid #fff"></span>
            @endif
        </button>
        @if(! auth()->user()->isGuest())
        <button class="btn btn-outline-success btn-sm"
                onclick="document.getElementById('import-produksi-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
        <button class="btn btn-outline-secondary btn-sm"
                onclick="document.getElementById('exportProduksiPanel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </button>
        @endif
    </div>
</div>

@if(! auth()->user()->isGuest())
{{-- Export Panel --}}
<x-export-modal
    panelId="exportProduksiPanel"
    :exportUrl="route('input.produksi.export')"
    title="Export LHP Produksi"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
/>

{{-- Import Panel --}}
<div id="import-produksi-panel" class="erp-panel mb-3 {{ session('import_errors') !== null || session('import_success') ? '' : 'd-none' }}">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import LHP Produksi dari Excel</span>
        <a href="{{ route('input.produksi.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.produksi.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label mb-1" style="font-size:0.92rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
            <div class="mt-2" style="font-size:0.88rem;color:#6c757d">
                Mulai dari baris ke-4 (baris 1 = header, baris 2 = keterangan, baris 3 = contoh).
            </div>
        </form>
    </div>
</div>
@endif

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
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
            <div style="width:100%">
                <strong>{{ count(session('import_errors')) }} baris gagal diimport:</strong>
                <div style="max-height:180px;overflow-y:auto;margin-top:6px">
                    <ul class="mb-0" style="font-size:0.92rem">
                        @foreach(session('import_errors') as $err)<li>{{ $err }}</li>@endforeach
                    </ul>
                </div>
            </div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Filter --}}
@php
$mesinByLine  = $mesin->groupBy('line');
$_mesinForJs  = $mesin->map(fn($m) => ['id' => $m->id, 'code' => $m->id_mesin, 'name' => $m->nama_mesin, 'line' => $m->line])->values();
$_partsForFilter = $parts->map(fn($p) => ['id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values()])->values();
$_karForFilter = $karyawan->map(fn($k) => ['id' => $k->id, 'nama' => $k->nama_karyawan])->values();
$_allProsesForFilter = $parts->flatMap(fn($p) => $p->proses)->unique('id')->map(fn($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values();
$_customersTA = $customers->map(fn($c) => ['v' => $c, 't' => $c])->values();
$_partsTA     = $_partsForFilter->map(fn($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']])->values();
$_prosesTA    = $_allProsesForFilter->map(fn($pr) => ['v' => $pr['id'], 't' => $pr['label']])->values();
$_operatorTA  = $_karForFilter->map(fn($k) => ['v' => $k['id'], 't' => $k['nama']])->values();
$hasAdvFilter = $filterCustomer || $filterPartId || $filterKarId || $filterProsesId;
$filterActive = !empty($mesinIds) || ($search ?? '') !== '' || $hasAdvFilter;
$advCount = array_sum([$filterCustomer?1:0, $filterPartId?1:0, $filterKarId?1:0, $filterProsesId?1:0]);

// Label aktif untuk display
$filterPartLabel = $filterPartId ? ($parts->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$filterKarLabel  = $filterKarId  ? ($karyawan->firstWhere('id', $filterKarId)?->nama_karyawan ?? '') : '';
$_fpObj = $filterProsesId ? $parts->flatMap(fn($p) => $p->proses)->firstWhere('id', $filterProsesId) : null;
$filterProsesLabel = $_fpObj ? ($_fpObj->id_proses.' – '.$_fpObj->nama_proses) : '';
@endphp

{{-- ── Filter bar ── --}}
<style>
/* Sticky freeze columns → CSS bersama di layouts/app.blade.php */
/* ── Filter bar ── */
.fbar-lbl{font-size:.67rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:4px}
.fbar-inp{height:34px;width:100%;border-radius:3px;padding:0 26px 0 8px;font-size:.83rem;background:#fff;outline:none;transition:border-color 100ms;box-sizing:border-box}
.fbar-inp:focus{border-color:#64748b !important}
.fbar-dd{position:absolute;top:calc(100% + 2px);left:0;min-width:210px;background:#fff;border:1px solid #d1d5db;border-radius:3px;z-index:600;max-height:220px;overflow-y:auto;filter:drop-shadow(0 4px 12px rgba(0,0,0,.08))}
.fbar-dd-item{padding:7px 10px;cursor:pointer;font-size:.82rem;white-space:nowrap;color:#374151;transition:background 60ms}
.fbar-dd-item:hover{background:#f1f5f9}
.fbar-clr{position:absolute;right:6px;top:50%;transform:translateY(-50%);background:none;border:none;color:#94a3b8;cursor:pointer;padding:0;font-size:.95rem;line-height:1}
@keyframes fbarFade{from{opacity:0;transform:translateY(-4px)}to{opacity:1;transform:translateY(0)}}
</style>
<div x-show="filterOpen" x-cloak
     x-transition:enter="transition ease-out duration-150"
     x-transition:enter-start="opacity-0 -translate-y-1"
     x-transition:enter-end="opacity-100 translate-y-0"
     x-transition:leave="transition ease-in duration-100"
     x-transition:leave-start="opacity-100 translate-y-0"
     x-transition:leave-end="opacity-0 -translate-y-1">
<div class="filter-bar mb-3">
<form method="GET" action="{{ route('input.produksi.index') }}" id="filterForm" x-data="mesinFilterComp()" style="width:100%">

    <template x-for="id in selected" :key="id">
        <input type="hidden" name="mesin_ids[]" :value="id">
    </template>

    {{-- ══ Row 1: Tanggal · Mesin · Aksi · Record ══ --}}
    <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap;margin-bottom:8px">

        {{-- Dari Tanggal --}}
        <div style="flex:1;min-width:110px;max-width:160px">
            <div class="fbar-lbl">Dari Tanggal</div>
            <input type="date" name="date_from" value="{{ $dateFrom }}"
                   style="height:34px;border:1px solid #e2e8f0;border-radius:3px;padding:0 8px;font-size:.83rem;color:#1e293b;width:100%;outline:none;transition:border-color 100ms;box-sizing:border-box"
                   onfocus="this.style.borderColor='#64748b'" onblur="this.style.borderColor='#e2e8f0'">
        </div>

        {{-- Sampai Tanggal --}}
        <div style="flex:1;min-width:110px;max-width:160px">
            <div class="fbar-lbl">Sampai Tanggal</div>
            <input type="date" name="date_to" value="{{ $dateTo }}"
                   style="height:34px;border:1px solid #e2e8f0;border-radius:3px;padding:0 8px;font-size:.83rem;color:#1e293b;width:100%;outline:none;transition:border-color 100ms;box-sizing:border-box"
                   onfocus="this.style.borderColor='#64748b'" onblur="this.style.borderColor='#e2e8f0'">
        </div>

        {{-- Mesin --}}
        <div style="flex:1;min-width:120px">
            <div class="fbar-lbl">Mesin</div>
            <button type="button" @click="mesinOpen=!mesinOpen"
                    style="height:34px;display:flex;align-items:center;gap:6px;padding:0 10px;border-radius:3px;font-size:.83rem;white-space:nowrap;cursor:pointer;transition:all 100ms;width:100%"
                    :style="selected.length > 0 ? 'border:1.5px solid #334155;background:#f8fafc;color:#1e293b;font-weight:600' : 'border:1px solid #e2e8f0;background:#fff;color:#64748b'">
                <i class="bi bi-cpu" style="font-size:.72rem;opacity:.55;flex-shrink:0"></i>
                <span style="flex:1;text-align:left;overflow:hidden;text-overflow:ellipsis">
                    <span x-show="selected.length === 0">Semua Mesin</span>
                    <span x-show="selected.length === 1" x-text="labelSingle"></span>
                    <span x-show="selected.length > 1"><span x-text="selected.length"></span> mesin</span>
                </span>
                <i class="bi" :class="mesinOpen?'bi-chevron-up':'bi-chevron-down'" style="font-size:.62rem;opacity:.45;flex-shrink:0"></i>
            </button>
        </div>

        <div style="width:1px;height:34px;background:#e2e8f0;align-self:flex-end;flex-shrink:0"></div>

        {{-- Tampilkan + Reset + Record --}}
        <div style="display:flex;gap:6px;align-items:flex-end;flex-shrink:0">
            <button type="submit"
                    style="height:34px;padding:0 16px;border-radius:3px;border:none;background:#1e293b;color:#fff;font-size:.82rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;white-space:nowrap;transition:background 100ms"
                    onmouseenter="this.style.background='#334155'"
                    onmouseleave="this.style.background='#1e293b'">
                <i class="bi bi-funnel" style="font-size:.72rem"></i>Tampilkan
            </button>
            <a href="{{ route('input.produksi.index') }}"
               style="height:34px;padding:0 14px;border-radius:3px;border:1px solid #d1d5db;background:#fff;color:#64748b;font-size:.82rem;font-weight:500;cursor:pointer;display:flex;align-items:center;text-decoration:none;white-space:nowrap;transition:all 100ms"
               onmouseenter="this.style.background='#f8fafc'"
               onmouseleave="this.style.background='#fff'">
                Reset
            </a>
            <span style="display:inline-flex;align-items:center;height:34px;padding:0 12px;border-radius:3px;background:#f8fafc;border:1px solid #e2e8f0;font-size:.78rem;color:#64748b;white-space:nowrap;font-weight:500">
                {{ number_format($transaksi->total()) }} record{{ $filterActive ? ' · filter aktif' : '' }}
            </span>
        </div>
    </div>{{-- end row 1 --}}

    {{-- ══ Row 2: Entity filters ══ --}}
    <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap">

        {{-- Customer --}}
        <div style="flex:1;min-width:120px" x-data="filterSearchComp(_customersTA,'{{ $filterCustomer }}','{{ $filterCustomer }}')" @click.outside="open=false">
            <div class="fbar-lbl">Customer</div>
            <input type="hidden" name="filter_customer" :value="val">
            <div style="position:relative">
                <input type="text" x-model="query" @focus="open=true" @input="open=true" @keydown.escape="open=false"
                       placeholder="Semua customer…" class="fbar-inp"
                       :style="val?'border:1.5px solid #334155;font-weight:500;color:#1e293b':'border:1px solid #e2e8f0;color:#64748b'">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="fbar-clr">×</button>
                <div x-show="open && filtered.length > 0" x-cloak class="fbar-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="fbar-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Part Name + Part No + Proses (shared scope) --}}
        <div style="flex:4;min-width:0;display:flex;gap:8px;align-items:flex-end"
             x-data="partGroupComp({{ $filterPartId ?: 0 }},'{{ addslashes($filterPartLabel) }}',{{ $filterProsesId ?: 0 }},'{{ addslashes($filterProsesLabel) }}')">

            <div style="flex:2;min-width:140px" @click.outside="partOpen=false">
                <div class="fbar-lbl">Part Name</div>
                <input type="hidden" name="filter_part_id" :value="partVal">
                <div style="position:relative">
                    <input type="text" x-model="partQuery"
                           @focus="partOpen=true" @input="partOpen=true;partVal='';prosesVal='';prosesQuery=''"
                           @keydown.escape="partOpen=false" placeholder="Semua part…" class="fbar-inp"
                           :style="partVal?'border:1.5px solid #334155;font-weight:500;color:#1e293b':'border:1px solid #e2e8f0;color:#64748b'">
                    <button type="button" x-show="partVal" @mousedown.prevent="partVal='';partQuery='';prosesVal='';prosesQuery=''" class="fbar-clr">×</button>
                    <div x-show="partOpen && filteredParts.length > 0" x-cloak class="fbar-dd">
                        <template x-for="item in filteredParts" :key="item.v">
                            <div @mousedown.prevent="pickPart(item)" class="fbar-dd-item">
                                <div x-text="item.t" style="font-weight:500;color:#1e293b"></div>
                                <div x-text="item.sub" style="font-size:.73rem;color:#94a3b8;margin-top:1px"></div>
                            </div>
                        </template>
                    </div>
                </div>
            </div>

            <div style="width:94px">
                <div class="fbar-lbl">Part No</div>
                <div :title="partNo" x-text="partNo || '—'"
                     style="height:34px;border:1px solid #e2e8f0;border-radius:3px;padding:0 8px;font-size:.82rem;color:#64748b;background:#f8fafc;display:flex;align-items:center;overflow:hidden;white-space:nowrap;text-overflow:ellipsis"></div>
            </div>

            <div style="flex:2;min-width:140px" @click.outside="prosesOpen=false">
                <div class="fbar-lbl">Proses</div>
                <input type="hidden" name="filter_proses_id" :value="prosesVal">
                <div style="position:relative">
                    <input type="text" x-model="prosesQuery"
                           @focus="prosesOpen=true" @input="prosesOpen=true;prosesVal=''"
                           @keydown.escape="prosesOpen=false" placeholder="Semua proses…" class="fbar-inp"
                           :style="prosesVal?'border:1.5px solid #334155;font-weight:500;color:#1e293b':'border:1px solid #e2e8f0;color:#64748b'">
                    <button type="button" x-show="prosesVal" @mousedown.prevent="prosesVal='';prosesQuery=''" class="fbar-clr">×</button>
                    <div x-show="prosesOpen && filteredProses.length > 0" x-cloak class="fbar-dd">
                        <template x-for="item in filteredProses" :key="item.v">
                            <div @mousedown.prevent="prosesVal=item.v;prosesQuery=item.t;prosesOpen=false" class="fbar-dd-item" x-text="item.t"></div>
                        </template>
                    </div>
                </div>
            </div>
        </div>

        {{-- Operator --}}
        <div style="flex:1.5;min-width:130px" x-data="filterSearchComp(_operatorTA,{{ $filterKarId ?: 0 }},'{{ addslashes($filterKarLabel) }}')" @click.outside="open=false">
            <div class="fbar-lbl">Operator</div>
            <input type="hidden" name="filter_karyawan_id" :value="val">
            <div style="position:relative">
                <input type="text" x-model="query" @focus="open=true" @input="open=true" @keydown.escape="open=false"
                       placeholder="Semua operator…" class="fbar-inp"
                       :style="val?'border:1.5px solid #334155;font-weight:500;color:#1e293b':'border:1px solid #e2e8f0;color:#64748b'">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="fbar-clr">×</button>
                <div x-show="open && filtered.length > 0" x-cloak class="fbar-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="fbar-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
        </div>

    </div>{{-- end row 2 --}}

    {{-- ══ Mesin panel (below rows) ══ --}}
    <div x-show="mesinOpen" x-cloak
         style="margin-top:8px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:3px;padding:12px 14px;animation:fbarFade 140ms cubic-bezier(.4,0,.2,1)">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">
            <span class="fbar-lbl" style="margin:0">
                <i class="bi bi-cpu me-1"></i>Pilih Mesin
                <span x-show="selected.length > 0"
                      style="background:#1e293b;color:#fff;border-radius:10px;padding:1px 7px;margin-left:4px;font-size:.7rem"
                      x-text="selected.length"></span>
            </span>
            <div style="display:flex;gap:12px;align-items:center">
                <button type="button" x-show="selected.length > 0" @click="clearAll()"
                        style="font-size:.75rem;color:#ef4444;background:none;border:none;padding:0;cursor:pointer;font-weight:500">
                    × Hapus semua
                </button>
                <button type="button" @click="mesinOpen=false"
                        style="background:none;border:none;color:#94a3b8;cursor:pointer;padding:2px 6px;border-radius:2px;font-size:.78rem;transition:background 100ms"
                        onmouseenter="this.style.background='#e2e8f0'" onmouseleave="this.style.background='transparent'">
                    <i class="bi bi-chevron-up"></i>
                </button>
            </div>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">
            @foreach($mesinByLine as $line => $mesinGroup)
            <span style="font-size:.63rem;font-weight:800;color:#cbd5e1;letter-spacing:.12em;text-transform:uppercase;padding:0 2px">{{ $line }}</span>
            @foreach($mesinGroup as $m)
            <button type="button" @click="toggle({{ $m->id }})"
                    :style="isSelected({{ $m->id }}) ? 'background:#1e293b;color:#fff;border-color:#1e293b' : 'background:#fff;color:#475569;border-color:#e2e8f0'"
                    style="height:28px;padding:0 10px;border-radius:3px;border:1px solid;font-size:.75rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:all 100ms"
                    title="{{ $m->nama_mesin }}">{{ $m->id_mesin }}</button>
            @endforeach
            <span style="width:1px;height:18px;background:#e2e8f0;margin:0 2px"></span>
            @endforeach
        </div>
        <div style="margin-top:12px;padding-top:10px;border-top:1px solid #e2e8f0;display:flex;justify-content:flex-end">
            <button type="submit"
                    style="height:30px;padding:0 14px;border-radius:3px;border:none;background:#1e293b;color:#fff;font-size:.78rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:5px;transition:background 100ms"
                    onmouseenter="this.style.background='#334155'" onmouseleave="this.style.background='#1e293b'">
                <i class="bi bi-funnel" style="font-size:.7rem"></i>Terapkan
            </button>
        </div>
    </div>

</form>
</div>
</div>{{-- /filterOpen wrapper --}}

@php
/* ── Hitung anomali per halaman untuk banner ── */
$anomalyCount = 0;
foreach ($transaksi as $_t) {
    $_pp      = $_t->partProses;
    $_sphStd  = $_pp?->sph_standar_produksi ?? 0;
    $_sphAct  = $_t->sph_aktual;
    $_pct     = $_sphStd > 0 ? round($_sphAct / $_sphStd * 100, 1) : null;
    $_wkj     = $_t->waktu_kerja_jam;
    if ($_pct > 150 || $_wkj < 0.083 || ($_sphStd == 0 && $_sphAct > 0)) $anomalyCount++;
}
@endphp

@if($anomalyCount > 0)
<div style="background:#fefce8;border:1px solid #fde047;border-radius:2px;padding:10px 14px;margin-bottom:10px;display:flex;align-items:flex-start;gap:10px">
    <i class="bi bi-exclamation-triangle-fill" style="color:#ca8a04;font-size:1.1rem;flex-shrink:0;margin-top:1px"></i>
    <div>
        <div style="font-weight:700;font-size:0.88rem;color:#713f12">
            {{ $anomalyCount }} baris perlu diperiksa pada halaman ini
        </div>
        <div style="font-size:0.82rem;color:#92400e;margin-top:2px">
            Baris yang disorot kuning menunjukkan data yang mungkin salah input —
            achievement &gt;150%, waktu kerja &lt;5 menit, atau SPH standar belum diset.
            Harap cek kembali qty aktual dan jam start/finish.
        </div>
    </div>
</div>
@endif

@if($myLastRecord)
@php
    $lastUrl = route('input.produksi.index', [
        'date_from' => $myLastRecord->tanggal_produksi,
        'date_to'   => $myLastRecord->tanggal_produksi,
    ]) . '#last-input';
@endphp
<div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:2px;padding:9px 14px;margin-bottom:10px;display:flex;align-items:center;gap:10px">
    <i class="bi bi-clock-history" style="color:#0284c7;font-size:1rem;flex-shrink:0"></i>
    <div style="flex:1;font-size:0.83rem;min-width:0;color:#0c4a6e">
        <strong style="color:#075985">{{ auth()->user()->name }}</strong>
        <span style="color:#94a3b8;margin:0 3px">·</span>
        <span style="color:#94a3b8;font-size:0.75rem">{{ auth()->user()->email }}</span>
        <span style="color:#cbd5e1;margin:0 6px">|</span>
        <span style="color:#64748b;font-size:0.78rem">{{ \Carbon\Carbon::parse($myLastRecord->tanggal_produksi)->format('d/m/Y') }}</span>
        <span style="color:#94a3b8;margin:0 4px">·</span>
        <span style="color:#374151">{{ $myLastRecord->karyawan?->nama_karyawan ?? '—' }}</span>
        <span style="color:#94a3b8"> — </span>
        <span style="color:#374151">{{ $myLastRecord->partProses?->part?->part_name ?? '—' }}</span>
        <span style="color:#94a3b8;margin:0 4px">·</span>
        <span style="color:#374151;font-weight:600">{{ number_format($myLastRecord->qty_aktual) }} pcs</span>
        <span style="color:#94a3b8;margin:0 5px">·</span>
        <span style="color:#64748b;font-size:0.78rem">{{ $myLastRecord->created_at->diffForHumans() }}</span>
    </div>
    @if($myLastOnPage)
    <a href="#last-input"
       style="display:inline-flex;align-items:center;gap:4px;height:26px;padding:0 10px;border-radius:2px;background:#0284c7;color:#fff;font-size:0.78rem;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0;transition:background 100ms"
       onmouseenter="this.style.background='#0369a1'" onmouseleave="this.style.background='#0284c7'">
        <i class="bi bi-arrow-down" style="font-size:0.7rem"></i>Lihat
    </a>
    @else
    <a href="{{ $lastUrl }}"
       style="display:inline-flex;align-items:center;gap:4px;height:26px;padding:0 10px;border-radius:2px;border:1px solid #bae6fd;background:#fff;color:#0284c7;font-size:0.78rem;font-weight:600;text-decoration:none;white-space:nowrap;flex-shrink:0;transition:all 100ms"
       onmouseenter="this.style.background='#e0f2fe'" onmouseleave="this.style.background='#fff'">
        <i class="bi bi-box-arrow-up-right" style="font-size:0.7rem"></i>Buka
    </a>
    @endif
</div>
@endif

<div class="erp-panel">
    <div class="erp-panel-header">
        Daftar LHP Produksi
        @if($anomalyCount > 0)
        <span style="margin-left:8px;background:#fde047;color:#713f12;font-size:10px;font-weight:700;
                     padding:2px 8px;border-radius:999px;letter-spacing:0.3px">
            {{ $anomalyCount }} anomali
        </span>
        @endif
    </div>
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 210px);min-height:200px">
        <table id="produksiTable" class="erp-table tbl-freeze" style="min-width:1520px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th class="sc sc-1" style="width:85px">Tanggal</th>
                    <th class="sc sc-2" style="width:70px">Shift</th>
                    <th class="sc sc-3" style="width:130px">Operator</th>
                    <th class="sc sc-4" style="width:65px">Mesin</th>
                    <th class="sc sc-5 sc-sep" style="width:120px">Nama Mesin</th>
                    <th style="width:75px">Customer</th>
                    <th style="width:130px">Part No.</th>
                    <th>Part Name</th>
                    <th style="width:45px;text-align:center">ID<br>Proses</th>
                    <th>Proses</th>
                    <th style="width:110px">Jam</th>
                    <th style="width:65px;text-align:right">Qty Act</th>
                    <th style="width:55px;text-align:right">Qty NG</th>
                    <th style="width:100px">Jenis NG</th>
                    <th style="width:65px;text-align:right">SPH Std</th>
                    <th style="width:65px;text-align:right">SPH Act</th>
                    <th style="width:70px;text-align:right">W. Kerja</th>
                    <th style="width:65px;text-align:right">CT Act</th>
                    <th style="width:90px;text-align:right;white-space:nowrap">%</th>
                    <th style="width:70px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transaksi as $t)
                @php
                    $pp     = $t->partProses;
                    $part   = $pp?->part;
                    $sphStd = $pp?->sph_standar_produksi ?? 0;
                    $sphAct = $t->sph_aktual;
                    $pct    = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
                    $wkj    = $t->waktu_kerja_jam;

                    /* ── Deteksi anomali ── */
                    $warnings = [];
                    if ($pct !== null && $pct > 150)
                        $warnings[] = "Achievement {$pct}% — terlalu tinggi, periksa qty atau jam input";
                    if ($wkj < 0.083 && $wkj > 0)
                        $warnings[] = 'Waktu kerja kurang dari 5 menit — jam start/finish mungkin salah';
                    if ($sphStd == 0 && $sphAct > 0)
                        $warnings[] = 'SPH standar belum diset — achievement tidak dapat dihitung';
                    $isAnomaly  = count($warnings) > 0;
                    $tooltipTxt = implode(' | ', $warnings);
                    $isMyLast   = $myLastRecord && $t->id === $myLastRecord->id;
                @endphp
                <tr id="{{ $isMyLast ? 'last-input' : '' }}"
                    data-row-id="{{ $t->id }}"
                    class="{{ $isAnomaly ? 'anomaly-row' : '' }} {{ $isMyLast ? 'last-row' : '' }} {{ request('highlight_id') == $t->id ? 'notif-highlight' : '' }}">
                    <td class="sc sc-1" style="{{ $isMyLast ? 'box-shadow:inset 3px 0 0 #0284c7;' : '' }}">
                        {{ \Carbon\Carbon::parse($t->tanggal_produksi)->format('d/m/Y') }}
                        @if($isMyLast)
                        <div style="margin-top:2px;font-size:0.6rem;font-weight:700;color:#0284c7;letter-spacing:.04em;line-height:1;white-space:nowrap">
                            <i class="bi bi-arrow-left-short"></i>Terakhirmu
                        </div>
                        @endif
                    </td>
                    <td class="sc sc-2" style="white-space:nowrap">{{ $t->shift?->nama_shift ?? '—' }}</td>
                    <td class="sc sc-3" style="white-space:nowrap">{{ $t->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td class="sc sc-4">{{ $t->mesin?->id_mesin ?? '—' }}</td>
                    <td class="sc sc-5 sc-sep" style="font-size:0.88rem;white-space:nowrap">{{ $t->mesin?->nama_mesin ?? '—' }}</td>
                    <td style="font-size:0.88rem;color:#5d7283">{{ $part?->customer ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_no ?? '—' }}</td>
                    <td style="font-size:0.88rem;white-space:nowrap">{{ $part?->part_name ?? '—' }}</td>
                    <td style="text-align:center;font-weight:600">{{ $pp?->id_proses ?? '—' }}</td>
                    <td style="font-size:0.88rem;white-space:nowrap">{{ $pp?->nama_proses ?? '—' }}</td>
                    <td style="white-space:nowrap;font-size:0.88rem">{{ $t->jam_start }} – {{ $t->jam_finish }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($t->qty_aktual) }}</td>
                    <td style="text-align:right;font-weight:600;color:{{ $t->qty_ng ? '#c0392b' : '#9ca3af' }}">
                        {{ $t->qty_ng ? number_format($t->qty_ng) : '—' }}
                    </td>
                    <td style="font-size:0.72rem;color:#5d7283">{{ $t->ng_ringkas ?: '—' }}</td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($sphStd) }}</td>
                    <td style="text-align:right;font-weight:600;color:{{ $sphAct >= $sphStd ? '#27ae60' : '#c0392b' }}">
                        {{ number_format($sphAct) }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($t->waktu_kerja_jam, 1) }}j</td>
                    <td style="text-align:right">{{ number_format($t->actual_cycle_time, 1) }}s</td>
                    <td style="text-align:right;font-weight:700;color:{{ $pct===null?'#9ca3af':($pct>=100?'#15803d':'#b91c1c') }}">
                        @if($isAnomaly)
                        <span data-bs-toggle="tooltip" data-bs-placement="left"
                              title="{{ $tooltipTxt }}"
                              style="cursor:help;white-space:nowrap">
                            {{ $pct !== null ? $pct.'%' : '—' }} <i class="bi bi-exclamation-triangle-fill" style="color:#ca8a04;font-size:0.75rem"></i>
                        </span>
                        @else
                        <span style="white-space:nowrap">{{ $pct !== null ? $pct.'%' : '—' }}</span>
                        @endif
                    </td>
                    <td>
                        @php $note = $t->staff_note ?? null; @endphp
                        @if(auth()->user()->role === 'admin')
                        <div class="action-btns">
                            <a href="{{ route('input.produksi.edit', $t) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.produksi.destroy', $t) }}"
                                  onsubmit="return confirm('Hapus data ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                            @if($note)
                            @php
                                $resolved   = $t->staff_note_resolved_at;
                                $resolvedBy = $t->noteResolvedBy?->name ?? '';
                                $noteAt     = $t->staff_note_at?->toIso8601String() ?? '';
                                $updatedAt  = $t->updated_at?->toIso8601String() ?? '';
                                $resolvedAt = $resolved?->toIso8601String() ?? '';
                            @endphp
                            <button type="button"
                                    onclick="openNoteDrawer('produksi',{{ $t->id }},{{ \Illuminate\Support\Js::from($note) }},true,'{{ $updatedAt }}','{{ $noteAt }}','{{ $resolvedAt }}',{{ \Illuminate\Support\Js::from($resolvedBy) }})"
                                    class="btn btn-sm"
                                    style="background:{{ $resolved ? '#f0fdf4' : '#fef9c3' }};border:1px solid {{ $resolved ? '#86efac' : '#fde047' }};color:{{ $resolved ? '#166534' : '#713f12' }};white-space:nowrap">
                                <i class="bi bi-{{ $resolved ? 'check2-circle' : 'chat-left-text-fill' }}"></i> Note
                            </button>
                            @endif
                        </div>
                        @elseif(! auth()->user()->isGuest())
                        @php $staffResolvedAt = $t->staff_note_resolved_at?->toIso8601String() ?? ''; @endphp
                        <button type="button"
                                onclick="openNoteDrawer('produksi',{{ $t->id }},{{ \Illuminate\Support\Js::from($note ?? '') }},false,'','','{{ $staffResolvedAt }}','')"
                                class="btn btn-sm {{ $staffResolvedAt ? 'btn-success' : ($note ? 'btn-warning' : 'btn-outline-secondary') }}"
                                style="font-size:0.78rem;white-space:nowrap">
                            <i class="bi bi-{{ $staffResolvedAt ? 'check2-circle' : ('chat-left-text' . ($note ? '-fill' : '')) }} me-1"></i>
                            {{ $staffResolvedAt ? 'Selesai' : ($note ? 'Edit Note' : 'Note') }}
                        </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="20" style="text-align:center;color:#888;padding:20px">
                        Belum ada data LHP Produksi pada rentang tanggal ini.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $transaksi->links() }}</div>

{{-- ── Note Drawer (Alpine, no blocking backdrop) ───────────────── --}}
<div x-data="{
        open:false, action:'', text:'', hasNote:false, recordId:0,
        isAdmin:false, noteAt:'', updatedAt:'', resolvedAt:'', resolvedBy:'', resolveAction:'',
        get dataChanged() {
            if (!this.updatedAt || !this.noteAt) return null;
            return new Date(this.updatedAt) > new Date(this.noteAt);
        }
     }"
     x-on:open-note.window="
        open=true; action=$event.detail.action; text=$event.detail.text;
        hasNote=!!$event.detail.text; recordId=$event.detail.recordId;
        isAdmin=$event.detail.isAdmin||false; noteAt=$event.detail.noteAt||'';
        updatedAt=$event.detail.updatedAt||''; resolvedAt=$event.detail.resolvedAt||'';
        resolvedBy=$event.detail.resolvedBy||''; resolveAction=$event.detail.resolveAction||'';
     "
     x-on:keydown.escape.window="open=false">

    <div x-show="open" x-cloak @click="open=false"
         style="position:fixed;inset:0;z-index:1049"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="transform translate-x-full"
         x-transition:enter-end="transform translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="transform translate-x-0"
         x-transition:leave-end="transform translate-x-full"
         style="position:fixed;top:0;right:0;height:100dvh;width:420px;max-width:100vw;
                z-index:1050;display:flex;flex-direction:column;
                background:#fff;box-shadow:-6px 0 32px rgba(0,0,0,.15)">

        {{-- Header --}}
        <div style="display:flex;align-items:center;justify-content:space-between;
                    padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
            <div style="font-weight:700;font-size:0.95rem">
                <i class="bi bi-chat-left-text-fill me-2"></i>
                <span x-show="isAdmin">Note dari Staff</span>
                <span x-show="!isAdmin">Note untuk Admin</span>
            </div>
            <button type="button" @click="open=false"
                    style="background:none;border:none;color:#fff;font-size:1.2rem;cursor:pointer;line-height:1;padding:0">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        {{-- Body --}}
        <div style="flex:1;overflow-y:auto;padding:24px">

            {{-- ADMIN MODE: baca note, lihat status --}}
            <div x-show="isAdmin">
                <p style="font-size:0.82rem;color:#6b7280;margin-bottom:8px">Catatan dari staff:</p>
                <div x-text="text"
                     style="background:#fafafa;border:1px solid #e5e7eb;border-radius:4px;
                            padding:12px;font-size:0.88rem;min-height:80px;white-space:pre-wrap;color:#111827"></div>
                <div x-show="noteAt" style="font-size:0.75rem;color:#9ca3af;margin-top:6px">
                    Dikirim: <span x-text="noteAt ? new Date(noteAt).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}) : ''"></span>
                </div>

                {{-- Status perubahan data --}}
                <div style="margin-top:16px">
                    <div x-show="dataChanged === true"
                         style="padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;font-size:0.82rem;color:#166534">
                        <i class="bi bi-check-circle-fill me-1"></i>Data sudah diperbarui setelah note dikirim
                    </div>
                    <div x-show="dataChanged === false"
                         style="padding:10px 14px;background:#fef9c3;border:1px solid #fde047;border-radius:4px;font-size:0.82rem;color:#713f12">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Data <strong>belum</strong> diperbarui sejak note dikirim
                    </div>
                </div>

                {{-- Status resolved --}}
                <div x-show="resolvedAt"
                     style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;font-size:0.82rem;color:#166534">
                    <i class="bi bi-check2-circle me-1"></i>
                    Sudah ditandai selesai oleh <strong x-text="resolvedBy"></strong>
                    pada <span x-text="resolvedAt ? new Date(resolvedAt).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}) : ''"></span>
                </div>
            </div>

            {{-- STAFF MODE: tulis note --}}
            <div x-show="!isAdmin">
                <template x-if="resolvedAt">
                    <div>
                        <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;font-size:0.82rem;color:#166534;margin-bottom:12px">
                            <i class="bi bi-lock-fill me-1"></i>
                            Note sudah di-feedback admin. Tidak dapat diubah lagi.
                        </div>
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:12px 14px;font-size:0.88rem;color:#374151;white-space:pre-wrap" x-text="text || '(kosong)'"></div>
                    </div>
                </template>
                <template x-if="!resolvedAt">
                    <div>
                        <p style="font-size:0.82rem;color:#6b7280;margin-bottom:14px;line-height:1.6">
                            Tulis catatan untuk admin mengenai kesalahan pada data ini.
                            Admin akan melihat catatan ini dan dapat memperbaiki data.
                        </p>
                        <textarea x-model="text" rows="6"
                                  class="form-control" style="font-size:0.88rem;resize:vertical"
                                  placeholder="Contoh: Qty aktual seharusnya 500, bukan 400. Mohon dikoreksi."
                                  maxlength="1000"></textarea>
                        <div style="font-size:0.75rem;color:#9ca3af;text-align:right;margin-top:4px">
                            <span x-text="text.length">0</span>/1000
                        </div>
                        <div x-show="hasNote" style="margin-top:16px;padding:10px 14px;
                             background:#fefce8;border:1px solid #fde047;border-radius:4px;font-size:0.8rem;color:#713f12">
                            <i class="bi bi-info-circle me-1"></i>
                            Note sebelumnya sudah dikirim. Kamu bisa ubah atau hapus di bawah.
                        </div>
                    </div>
                </template>
            </div>
        </div>

        {{-- Footer --}}
        <div style="flex-shrink:0;padding:16px 20px;border-top:1px solid #e5e7eb;display:flex;gap:8px;justify-content:flex-end">

            {{-- ADMIN FOOTER --}}
            <template x-if="isAdmin">
                <div style="display:flex;gap:8px;width:100%;justify-content:flex-end">
                    <button type="button" @click="open=false" class="btn btn-outline-secondary btn-sm">Tutup</button>
                    <form :action="action" method="POST" style="display:inline">
                        @csrf
                        <input type="hidden" name="record_id" :value="recordId">
                        <input type="hidden" name="staff_note" value="">
                        <button type="submit" class="btn btn-outline-danger btn-sm"
                                onclick="return confirm('Hapus note ini?')">
                            <i class="bi bi-trash me-1"></i>Hapus Note
                        </button>
                    </form>
                    <template x-if="!resolvedAt">
                        <form :action="resolveAction" method="POST" style="display:inline">
                            @csrf
                            <input type="hidden" name="record_id" :value="recordId">
                            <button type="submit" class="btn btn-success btn-sm">
                                <i class="bi bi-check2-circle me-1"></i>Tandai Selesai
                            </button>
                        </form>
                    </template>
                    <template x-if="resolvedAt">
                        <span class="btn btn-success btn-sm disabled" style="opacity:0.6">
                            <i class="bi bi-check2-circle me-1"></i>Sudah Selesai
                        </span>
                    </template>
                </div>
            </template>

            {{-- STAFF FOOTER --}}
            <template x-if="!isAdmin">
                <div style="display:flex;gap:8px;width:100%;justify-content:flex-end">
                    <button type="button" @click="open=false" class="btn btn-outline-secondary btn-sm">Batal</button>
                    <template x-if="!resolvedAt">
                        <div style="display:contents">
                            <form x-show="hasNote" :action="action" method="POST" style="display:inline">
                                @csrf
                                <input type="hidden" name="record_id" :value="recordId">
                                <input type="hidden" name="staff_note" value="">
                                <button type="submit" class="btn btn-outline-danger btn-sm"
                                        onclick="return confirm('Hapus note ini?')">
                                    <i class="bi bi-trash me-1"></i>Hapus Note
                                </button>
                            </form>
                            <form :action="action" method="POST" style="display:inline">
                                @csrf
                                <input type="hidden" name="record_id" :value="recordId">
                                <input type="hidden" name="staff_note" :value="text">
                                <button type="submit" class="btn btn-primary btn-sm" :disabled="!text.trim()">
                                    <i class="bi bi-send me-1"></i>Kirim Note
                                </button>
                            </form>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>

<script>
function openNoteDrawer(type, id, existingNote, isAdmin, updatedAt, noteAt, resolvedAt, resolvedBy) {
    const noteRoutes    = { produksi: '/input/produksi-note',    qc: '/input/qc-note',    dandori: '/input/dandori-note' };
    const resolveRoutes = { produksi: '/input/produksi-note-resolve', qc: '/input/qc-note-resolve', dandori: '/input/dandori-note-resolve' };
    window.dispatchEvent(new CustomEvent('open-note', {
        detail: {
            action: noteRoutes[type], resolveAction: resolveRoutes[type],
            recordId: id, text: existingNote || '',
            isAdmin: !!isAdmin, noteAt: noteAt || '',
            updatedAt: updatedAt || '', resolvedAt: resolvedAt || '', resolvedBy: resolvedBy || ''
        }
    }));
}

(function() {
    const row = document.querySelector('.notif-highlight');
    if (!row) return;
    row.scrollIntoView({ behavior: 'smooth', block: 'center' });
    setTimeout(() => {
        row.style.transition = 'background 1.5s ease';
        row.classList.remove('notif-highlight');
        row.querySelectorAll('td').forEach(td => { td.style.transition = 'background 1.5s ease'; td.style.background = ''; });
    }, 2000);
})();
</script>

</div>{{-- /alpine outer scope --}}
@endsection

@php
$_taKaryawan = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taMesin    = $mesin->map(fn($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
$_taParts    = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData  = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'                   => $pr->id,
        'id_proses'            => $pr->id_proses,
        'nama_proses'          => $pr->nama_proses,
        'sph_standar_produksi' => $pr->sph_standar_produksi,
    ])->values(),
])->values();
$_taJenisNg = $jenisNg->map(fn($j) => ['v' => $j->id, 't' => "{$j->kode} — {$j->nama_ng}"])->values();
@endphp

@push('scripts')
<script>
const taKaryawan = @json($_taKaryawan);
const taMesin    = @json($_taMesin);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);
const taJenisNg  = @json($_taJenisNg);

/* ── Filter Mesin multi-select ── */
const _mesinList     = @json($_mesinForJs);
const _mesinSelected = @json(array_values($mesinIds));
function mesinFilterComp() {
    return {
        mesinOpen: false,
        selected:  [..._mesinSelected],
        mesinList: _mesinList,
        get labelSingle() {
            if (this.selected.length !== 1) return '';
            const m = this.mesinList.find(m => m.id == this.selected[0]);
            return m ? m.code : '1 mesin';
        },
        toggle(id)     { const i = this.selected.indexOf(id); i === -1 ? this.selected.push(id) : this.selected.splice(i, 1); },
        isSelected(id) { return this.selected.includes(id); },
        clearAll()     { this.selected = []; },
    };
}

/* ── Filter Lanjutan typeahead ── */
const _customersTA   = @json($_customersTA);
const _partsTA       = @json($_partsTA);
const _partsFullData = @json($_partsForFilter);
const _prosesTA      = @json($_prosesTA);
const _operatorTA    = @json($_operatorTA);

function partGroupComp(initPartId, initPartLabel, initProsesId, initProsesLabel) {
    return {
        partQuery:   initPartLabel   || '',
        partVal:     initPartId      || '',
        partOpen:    false,
        prosesQuery: initProsesLabel || '',
        prosesVal:   initProsesId    || '',
        prosesOpen:  false,
        get partNo() {
            const v = this.partVal;
            if (!v) return '';
            const p = _partsTA.find(x => x.v == v);
            return p ? (p.pno || '') : '';
        },
        get prosesItems() {
            const v = this.partVal;
            if (!v) return _prosesTA;
            const part = _partsFullData.find(x => x.id == v);
            if (!part || !part.proses || !part.proses.length) return _prosesTA;
            return part.proses.map(pr => ({v: pr.id, t: pr.label}));
        },
        get filteredParts() {
            const q = this.partQuery.trim().toLowerCase();
            if (!q) return _partsTA.slice(0, 40);
            return _partsTA.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        get filteredProses() {
            const items = this.prosesItems;
            const q = this.prosesQuery.trim().toLowerCase();
            if (!q) return items.slice(0, 40);
            return items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        pickPart(item) {
            this.partVal     = item.v;
            this.partQuery   = item.t;
            this.partOpen    = false;
            this.prosesVal   = '';
            this.prosesQuery = '';
        },
    };
}

function filterSearchComp(items, initVal, initLabel, onPick) {
    return {
        items,
        query: initLabel || '',
        val:   (initVal !== undefined && initVal !== null && initVal !== 0 && initVal !== '0') ? initVal : '',
        open:  false,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 40);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        pick(item) {
            this.val = item.v; this.query = item.t; this.open = false;
            if (typeof onPick === 'function') onPick(item);
        },
        clear()   { this.val = ''; this.query = ''; },
        onInput() { this.val = ''; this.open = true; },
    };
}

function taField(items, initText='', initVal=null) {
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

function produksiPartSelector() {
    return {
        ...taField(taParts),
        prosesList: [],
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pick(item) {
            this.query = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part = partsData.find(p => p.id == item.v);
            this.prosesList = part ? part.proses : [];
        },
    };
}

/* Bootstrap tooltips */
document.querySelectorAll('[data-bs-toggle="tooltip"]').forEach(el => {
    new bootstrap.Tooltip(el, { trigger: 'hover' });
});

/* Client-side validation */
(function () {
    const form = document.getElementById('formProduksi');
    if (!form) return;
    function clearErrs() {
        form.querySelectorAll('.cve').forEach(e => e.remove());
        form.querySelectorAll('.cvi').forEach(el => el.classList.remove('cvi','is-invalid'));
    }
    function mkErr(el, msg) {
        el.classList.add('is-invalid','cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve'; d.textContent = msg;
        el.insertAdjacentElement('afterend', d);
        ['input','change'].forEach(ev => el.addEventListener(ev, () => { el.classList.remove('is-invalid','cvi'); if(d.parentNode) d.remove(); }, {once:true}));
    }
    function mkTa(hiddenInput, msg) {
        const wrap = hiddenInput.closest('.ta-wrap');
        const vis  = wrap.querySelector('.ta-input');
        vis.classList.add('is-invalid','cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve'; d.textContent = msg;
        wrap.insertAdjacentElement('afterend', d);
        vis.addEventListener('input', () => { vis.classList.remove('is-invalid','cvi'); if(d.parentNode) d.remove(); }, {once:true});
    }
    form.addEventListener('submit', function(e) {
        clearErrs(); const bad = []; const f = n => form.querySelector(`[name="${n}"]`);
        const tanggal = f('tanggal_produksi'); if (!tanggal.value) { mkErr(tanggal,'Tanggal wajib diisi.'); bad.push(tanggal); }
        const shift = f('id_shift'); if (!shift.value) { mkErr(shift,'Shift wajib dipilih.'); bad.push(shift); }
        const kar = f('id_karyawan'); if (!kar.value) { mkTa(kar,'Operator wajib dipilih.'); bad.push(kar.closest('.ta-wrap').querySelector('.ta-input')); }
        const mes = f('id_mesin'); if (!mes.value) { mkTa(mes,'Mesin wajib dipilih.'); bad.push(mes.closest('.ta-wrap').querySelector('.ta-input')); }
        const pro = f('id_part_proses'); if (!pro.value) { mkErr(pro, pro.disabled?'Pilih Part dulu.':'Proses wajib dipilih.'); bad.push(pro); }
        const js = f('jam_start'); if (!js.value) { mkErr(js,'Jam start wajib diisi.'); bad.push(js); }
        const jf = f('jam_finish'); if (!jf.value) { mkErr(jf,'Jam finish wajib diisi.'); bad.push(jf); }
        const qty = f('qty_aktual'); if (!qty.value || parseInt(qty.value)<1) { mkErr(qty,'Qty aktual wajib diisi (min 1).'); bad.push(qty); }
        if (bad.length) { e.preventDefault(); bad[0].scrollIntoView({behavior:'smooth',block:'center'}); }
    });
})();
</script>
@endpush
