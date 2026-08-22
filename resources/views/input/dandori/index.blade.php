@extends('layouts.app')
@section('title', 'LHP Dandori')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@php
$oldKaryawanId  = old('id_karyawan');
$oldKaryawanObj = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawanObj?->nama_karyawan ?? '';

$oldMesinId  = old('id_mesin');
$oldMesinObj = $mesin->firstWhere('id', $oldMesinId);
$oldMesinTxt = $oldMesinObj ? "{$oldMesinObj->id_mesin} - {$oldMesinObj->nama_mesin} (Line {$oldMesinObj->line})" : '';

/* ── Data untuk dock filter ──
   Bentuknya sama dengan LHP Produksi supaya komponen ketik-cari bersama di
   layout bisa dipakai apa adanya, tanpa cabang khusus per halaman. */
$_partsForFilter = $parts->map(fn ($p) => [
    'id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer,
    'proses' => $p->proses->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_allProsesForFilter = $parts->flatMap(fn ($p) => $p->proses)->unique('id')
    ->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_customersTA = $customers->map(fn ($c) => ['v' => $c, 't' => $c])->values();
$_partsTA     = $_partsForFilter->map(fn ($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']])->values();
$_prosesTA    = $_allProsesForFilter->map(fn ($pr) => ['v' => $pr['id'], 't' => $pr['label']])->values();
$_operatorTA  = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_mesinTA     = $mesin->map(fn ($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin])->values();
$_losstimeTA  = $losstimes->map(fn ($l) => ['v' => $l->id, 't' => $l->kode.' – '.$l->jenis_losstime])->values();

/* Label awal isian: tanpa ini kotak tampil kosong padahal filternya menyala. */
$_karLabel    = $filterKarId ? ($karyawan->firstWhere('id', $filterKarId)?->nama_karyawan ?? '') : '';
$_mesinObjF   = $filterMesinId ? $mesin->firstWhere('id', $filterMesinId) : null;
$_mesinLabel  = $_mesinObjF ? $_mesinObjF->id_mesin.' – '.$_mesinObjF->nama_mesin : '';
$_ltObjF      = $filterLosstimeId ? $losstimes->firstWhere('id', $filterLosstimeId) : null;
$_ltLabel     = $_ltObjF ? $_ltObjF->kode.' – '.$_ltObjF->jenis_losstime : '';
$_partLabel   = $filterPartId ? ($parts->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$_prosesObjF  = $filterProsesId ? $parts->flatMap(fn ($p) => $p->proses)->firstWhere('id', $filterProsesId) : null;
$_prosesLabel = $_prosesObjF ? $_prosesObjF->id_proses.' – '.$_prosesObjF->nama_proses : '';

$filterActive = ($search ?? '') !== '' || $filterKarId || $filterMesinId || $filterLosstimeId
    || $filterCustomer !== '' || $filterPartId || $filterProsesId;
@endphp

@section('content')

{{-- ══ SATU ALPINE SCOPE — mengontrol drawer sekaligus konten halaman ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }}, filterOpen: false }">

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
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1190">
</div>

{{-- ── DRAWER PANEL ─────────────────────────────────────────────── --}}
{{-- x-show HANYA mengontrol visibility — JANGAN taruh display:flex di sini --}}
<div x-show="drawerOpen"
     x-cloak
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="transform translate-x-full"
     x-transition:enter-end="transform translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-x-0"
     x-transition:leave-end="transform translate-x-full"
     style="position:fixed;top:0;right:0;height:100dvh;width:700px;max-width:100vw;z-index:1200">

    {{-- Inner flex container: SELALU flex, tidak disentuh x-show --}}
    <div class="erp-drawer" style="display:flex;flex-direction:column;height:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div class="erp-drawer-hd">
        <div>
            <i class="bi bi-tools me-2"></i>Dandori tanpa LHP
        </div>
        <button type="button" @click="drawerOpen = false"
                >
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Alpine + Form wrapper --}}
    <div x-data="dandoriMain()" style="flex:1;min-height:0;display:flex;flex-direction:column">
        <form method="POST" action="{{ route('input.dandori.store') }}" id="formDandori" novalidate
              style="flex:1;min-height:0;display:flex;flex-direction:column">
            @csrf

            {{-- Body (scrollable) --}}
            <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:20px 24px;overscroll-behavior:contain">

                @if($errors->any())
                <div class="alert alert-danger mb-3" >
                    <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
                </div>
                @endif

                @if($karyawan->isEmpty() || $mesin->isEmpty() || $parts->isEmpty() || $losstimes->isEmpty())
                <div class="alert alert-warning mb-3" >
                    <strong>Master data belum lengkap:</strong>
                    @if($karyawan->isEmpty()) <a href="{{ route('master.karyawan.index') }}">Tambah Karyawan/Operator</a>. @endif
                    @if($mesin->isEmpty()) <a href="{{ route('master.mesin.index') }}">Tambah Mesin</a>. @endif
                    @if($parts->isEmpty()) <a href="{{ route('master.part.index') }}">Tambah Part</a>. @endif
                    @if($losstimes->isEmpty()) <a href="{{ route('master.losstime.index') }}">Tambah Loss Time</a>. @endif
                </div>
                @endif

                {{-- ── INFORMASI SESI ──────────────────────────────── --}}
                <div style="font-size:0.7rem;font-weight:700;letter-spacing:.06em;color:#6b7280;text-transform:uppercase;margin-bottom:8px">
                    Informasi Sesi
                </div>
                <div class="row g-2 mb-3 pb-3" style="border-bottom:1px solid #e5e7eb">

                    {{-- Tanggal --}}
                    <div class="col-6">
                        <label class="form-label" >Tanggal <span class="text-danger">*</span></label>
                        <input type="date" name="tanggal"
                               class="form-control form-control-sm @error('tanggal') is-invalid @enderror"
                               value="{{ old('tanggal', date('Y-m-d')) }}">
                        @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>

                    {{-- Shift --}}
                    <div class="col-6">
                        <label class="form-label" >Shift <span class="text-danger">*</span></label>
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
                        <label class="form-label" >Operator <span class="text-danger">*</span></label>
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
                        @error('id_karyawan')<div class="invalid-feedback d-block" >{{ $message }}</div>@enderror
                    </div>

                    {{-- Mesin --}}
                    <div class="col-12"
                         x-data="taField(taMesin, '{{ $oldMesinTxt }}', {{ $oldMesinId ?: 'null' }})">
                        <label class="form-label" >Mesin <span class="text-danger">*</span></label>
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
                        @error('id_mesin')<div class="invalid-feedback d-block" >{{ $message }}</div>@enderror
                    </div>

                    {{-- Part --}}
                    <div class="col-6">
                        <label class="form-label" >Part <span class="text-danger">*</span></label>
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
                        <label class="form-label" >Proses <span class="text-danger">*</span></label>
                        <select name="id_part_proses"
                                class="form-select form-select-sm @error('id_part_proses') is-invalid @enderror"
                                :disabled="prosesList.length === 0"
                                @change="onProsesChange($event.target.value)">
                            <option value="">-- Pilih Part dulu --</option>
                            <template x-for="p in prosesList" :key="p.id">
                                <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                            </template>
                        </select>
                        @error('id_part_proses')<div class="invalid-feedback d-block" >{{ $message }}</div>@enderror
                    </div>

                    {{-- Progressive badge --}}
                    <div class="col-12" x-show="isProgressive" x-cloak>
                        <div class="alert alert-info py-2 mb-0 d-flex align-items-center gap-2" >
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
                    <span style="color:#6b7280">Tambahkan setiap aktivitas loss time dalam sesi ini.</span>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRow()" >
                        <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                    </button>
                </div>

                @error('rincian')<div class="text-danger mb-2" >{{ $message }}</div>@enderror

                <div class="table-responsive mb-2">
                    <table class="table table-bordered table-sm align-middle mb-0" >
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
                            <tr class="fw-semibold" >
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

                <p style="color:#6b7280;margin-bottom:0">
                    <i class="bi bi-info-circle me-1"></i>
                    Kolom <strong>Std</strong> dan <strong>Over</strong> otomatis terisi setelah memilih jenis loss time dan memasukkan waktu aktual.
                </p>

            </div>{{-- /scrollable body --}}

            {{-- Footer sticky — selalu terlihat di bawah drawer --}}
            <div style="flex-shrink:0;padding:14px 20px;border-top:2px solid #e5e7eb;display:flex;gap:10px;background:#f9fafb">
                <button type="submit" class="btn btn-primary" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan Data Dandori
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary" >
                    Batal
                </button>
            </div>

        </form>
    </div>{{-- /alpine + form wrapper --}}

    </div>{{-- /inner flex container --}}
</div>{{-- /drawer panel --}}
@endif

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}
@php
    /* Tautan "Last Input": kalau baris terakhir milik user tidak ada di
       halaman ini, lompat ke tanggalnya, bukan ke jangkar yang tak ketemu. */
    $lastUrl = $myLastRecord
        ? route('input.dandori.index', [
            'date_from' => $myLastRecord->tanggal->format('Y-m-d'),
            'date_to'   => $myLastRecord->tanggal->format('Y-m-d'),
          ]).'#last-input'
        : null;
    $filterActive = ($search ?? '') !== '';
@endphp

<div class="erp-ws">

{{-- ── Strip toolbar ─────────────────────────────────────────── --}}
<div class="erp-tb">
    {{-- Jalur sekunder. Alur utama pencatatan dandori sekarang lewat centang
         "Ada Dandori" di form LHP Produksi; tombol ini untuk 17% kejadian yang
         memang tidak punya baris LHP — ganti dies yang produksinya batal, atau
         yang produksinya jalan di shift berikutnya. Gaya `is-new` (hijau,
         dipakai aksi Tambah utama) sengaja dilepas supaya ia tidak lagi
         terbaca sebagai jalan masuk utama. --}}
    @if(! auth()->user()->isGuest())
    <button type="button" class="erp-tb-btn" @click="drawerOpen = true">
        <i class="bi bi-plus-lg"></i>Dandori tanpa LHP
    </button>
    @endif

    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''"
            @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>

    @if(auth()->user()->role === 'admin')
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('importPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    @endif

    @if(! auth()->user()->isGuest())
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportDandoriPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @endif

    <a href="{{ route('input.dandori.index') }}" class="erp-tb-btn">
        <i class="bi bi-arrow-clockwise"></i>Refresh
    </a>

    @if($myLastRecord)
    <a href="{{ $myLastOnPage ? '#last-input' : $lastUrl }}" class="erp-tb-btn"
       title="Input terakhir Anda: {{ $myLastRecord->tanggal->format('d/m/Y') }} &middot; {{ $myLastRecord->mesin?->id_mesin ?? '—' }} &middot; {{ $myLastRecord->partProses?->part?->part_name ?? '—' }}">
        <i class="bi bi-clock-history"></i>Last Input
    </a>
    @endif

    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- ── Dock filter (kiri) ────────────────────────────────────── --}}
<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('input.dandori.index') }}" class="erp-dock-body">

        {{-- ══ Find ══ --}}
        <div class="erp-grp">
            <b>Find</b>

            <div x-data="filterSearchComp(_operatorTA, {{ $filterKarId ?: 0 }}, '{{ addslashes($_karLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_karyawan_id" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Nama Operator"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>

            <div x-data="filterSearchComp(_mesinTA, {{ $filterMesinId ?: 0 }}, '{{ addslashes($_mesinLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_mesin_id" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Nama Mesin"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>

            <div x-data="filterSearchComp(_losstimeTA, {{ $filterLosstimeId ?: 0 }}, '{{ addslashes($_ltLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_losstime_id" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Jenis Loss Time"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
        </div>

        {{-- ══ Part & Customer ══ --}}
        <div class="erp-grp">
            <b>Part &amp; Customer</b>

            <div x-data="filterSearchComp(_customersTA, '{{ $filterCustomer }}', '{{ $filterCustomer }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_customer" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Nama Customer"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>

            {{-- Part, Proses, dan Nomor Part satu scope: memilih part mengisi
                 nomornya dan mengosongkan proses, persis seperti di LHP Produksi. --}}
            <div x-data="partGroupComp({{ $filterPartId ?: 0 }}, '{{ addslashes($_partLabel) }}', {{ $filterProsesId ?: 0 }}, '{{ addslashes($_prosesLabel) }}')"
                 style="display:flex;flex-direction:column;gap:4px">

                <div @click.outside="partOpen=false" style="position:relative">
                    <input type="hidden" name="filter_part_id" :value="partVal">
                    <input type="text" x-model="partQuery" @focus="partOpen=true"
                           @input="partOpen=true;partVal='';prosesVal='';prosesQuery=''"
                           @keydown.escape="partOpen=false" placeholder="Nama Part"
                           class="erp-in" :class="partVal ? 'is-set' : ''">
                    <button type="button" x-show="partVal"
                            @mousedown.prevent="partVal='';partQuery='';prosesVal='';prosesQuery=''"
                            class="erp-clr">&times;</button>
                    <div x-show="partOpen && filteredParts.length > 0" x-cloak class="erp-dd">
                        <template x-for="item in filteredParts" :key="item.v">
                            <div @mousedown.prevent="pickPart(item)" class="erp-dd-item" :title="item.t">
                                <span style="font-weight:700" x-text="item.t"></span>
                                <span class="sub" x-text="item.sub"></span>
                            </div>
                        </template>
                    </div>
                </div>

                <div @click.outside="prosesOpen=false" style="position:relative">
                    <input type="hidden" name="filter_proses_id" :value="prosesVal">
                    <input type="text" x-model="prosesQuery" @focus="prosesOpen=true"
                           @input="prosesOpen=true;prosesVal=''" @keydown.escape="prosesOpen=false"
                           placeholder="Proses" class="erp-in" :class="prosesVal ? 'is-set' : ''">
                    <button type="button" x-show="prosesVal" @mousedown.prevent="prosesVal='';prosesQuery=''" class="erp-clr">&times;</button>
                    <div x-show="prosesOpen && filteredProses.length > 0" x-cloak class="erp-dd">
                        <template x-for="item in filteredProses" :key="item.v">
                            <div @mousedown.prevent="prosesVal=item.v;prosesQuery=item.t;prosesOpen=false"
                                 class="erp-dd-item" x-text="item.t"></div>
                        </template>
                    </div>
                </div>

                {{-- Nomor part tidak diketik: dia turunan dari Nama Part. --}}
                <input type="text" class="erp-in" readonly tabindex="-1"
                       :value="partNo || 'Nomor Part'" :title="partNo">
            </div>
        </div>

        {{-- ══ Filter by Date ══ --}}
        {{-- ══ Filter by Date ══
             Centangnya mematikan rentang tanggal sama sekali, bukan sekadar
             menyembunyikan isian — tanpa itu tidak ada cara mencari lintas
             seluruh riwayat. --}}
        <div class="erp-grp" x-data="{ pakaiTgl: {{ $pakaiTanggal ? 'true' : 'false' }} }">
            <label class="erp-chk">
                <input type="checkbox" name="pakai_tanggal" value="1" x-model="pakaiTgl">
                <b>Filter by Date</b>
            </label>
            <div class="erp-dock-row">
                <span>From</span>
                <input type="date" name="date_from" value="{{ $dateFrom }}" class="erp-in" :disabled="!pakaiTgl">
            </div>
            <div class="erp-dock-row">
                <span>To</span>
                <input type="date" name="date_to" value="{{ $dateTo }}" class="erp-in" :disabled="!pakaiTgl">
            </div>
        </div>

        <div class="erp-grp">
            {{-- Penanda bahwa form memang dikirim: tanpa ini, centang yang
                 dilepas tidak terkirim sama sekali dan server tidak bisa
                 membedakan "dimatikan" dari "halaman baru dibuka". --}}
            <input type="hidden" name="filter_apply" value="1">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('input.dandori.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">



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

@if(! auth()->user()->isGuest())
{{-- Export Panel --}}
<x-export-modal
    panelId="exportDandoriPanel"
    :exportUrl="route('input.dandori.export')"
    title="Export Dandori"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
/>
@endif

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
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja.
     Wadah gulung bertumpuk membuat kepala tabel yang menempel berhenti bekerja. --}}
<div class="erp-scroll">
        <table class="erp-table tbl-freeze" style="min-width:1350px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th class="sc sc-1" style="width:85px">Tanggal</th>
                    <th class="sc sc-2" style="width:65px">Shift</th>
                    <th class="sc sc-3" style="width:140px">Operator</th>
                    <th class="sc sc-4" style="width:55px">Mesin</th>
                    <th class="sc sc-5 sc-sep" style="width:110px">Nama Mesin</th>
                    <th style="width:85px">Part No</th>
                    <th style="width:120px">Part Name</th>
                    <th style="width:72px;text-align:center">ID Proses</th>
                    <th style="width:110px">Nama Proses</th>
                    <th style="width:65px">Kode LT</th>
                    <th style="width:140px">Jenis Loss Time</th>
                    <th style="width:86px;text-align:right">Actual (mnt)</th>
                    <th style="width:72px;text-align:right">Std (mnt)</th>
                    <th style="width:78px;text-align:right">Over (mnt)</th>
                    <th style="width:90px">Aksi</th>
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
                    $isMyLast     = $myLastRecord && $r->id === $myLastRecord->id;
                @endphp
                <tr id="{{ $isMyLast ? 'last-input' : '' }}"
                    data-row-id="{{ $r->id }}"
                    class="{{ request('highlight_id') == $r->id ? 'notif-highlight' : '' }}"
                    style="{{ $isNewSession && !$loop->first ? 'border-top:2px solid #d1d5db;' : '' }}{{ $isMyLast ? 'box-shadow:inset 3px 0 0 #0284c7;' : '' }}">
                    <td class="sc sc-1" style="{{ $isNewSession ? 'font-weight:600' : 'color:#c4c9d4;' }}">
                        {{ $r->tanggal->format('d/m/Y') }}
                        @if($isMyLast)
                        <div style="margin-top:2px;font-size:0.6rem;font-weight:700;color:#0284c7;letter-spacing:.04em;line-height:1;white-space:nowrap">
                            <i class="bi bi-arrow-left-short"></i>Terakhirmu
                        </div>
                        @endif
                    </td>
                    <td class="sc sc-2" style="white-space:nowrap;{{ !$isNewSession ? 'color:#c4c9d4;' : '' }}">{{ $r->shift?->nama_shift ?? '—' }}</td>
                    <td class="sc sc-3" style="white-space:nowrap;{{ !$isNewSession ? 'color:#c4c9d4;' : '' }}">{{ $r->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td class="sc sc-4" style="{{ !$isNewSession ? 'color:#c4c9d4' : 'font-weight:600' }}">{{ $r->mesin?->id_mesin ?? '—' }}</td>
                    <td class="sc sc-5 sc-sep" style="white-space:nowrap;{{ !$isNewSession ? 'color:#c4c9d4' : '' }}">{{ $r->mesin?->nama_mesin ?? '—' }}</td>
                    <td style="white-space:nowrap;{{ !$isNewSession ? 'color:#c4c9d4;' : '' }}">{{ $part?->part_no ?? '—' }}</td>
                    <td style="white-space:nowrap;{{ !$isNewSession ? 'color:#c4c9d4' : '' }}">{{ $part?->part_name ?? '—' }}</td>
                    <td style="text-align:center;{{ !$isNewSession ? 'color:#c4c9d4' : 'font-weight:600' }}">{{ $pp?->id_proses ?? '—' }}</td>
                    <td style="{{ !$isNewSession ? 'color:#c4c9d4' : '' }}">{{ $pp?->nama_proses ?? '—' }}</td>
                    <td style="font-weight:600;letter-spacing:0.3px">{{ $r->losstime?->kode ?? '—' }}</td>
                    <td >{{ $r->losstime?->jenis_losstime ?? '—' }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($r->actual_menit, 1) }}</td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($r->std_menit, 1) }}</td>
                    <td style="text-align:right;font-weight:600">
                        @if($r->over_menit > 0)
                            <span style="color:#c0392b">+{{ number_format($r->over_menit, 1) }}</span>
                        @else
                            <span style="color:#27ae60;font-weight:400">OK</span>
                        @endif
                    </td>
                    @php $note = $r->staff_note ?? null; @endphp
                    <td>
                        @if(auth()->user()->role === 'admin')
                        <div class="action-btns">
                            <a href="{{ route('input.dandori.edit', $r) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.dandori.destroy', $r) }}"
                                  onsubmit="return confirm('Hapus baris loss time ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                            @if($note)
                            @php
                                $resolved   = $r->staff_note_resolved_at;
                                $resolvedBy = $r->noteResolvedBy?->name ?? '';
                                $noteAt     = $r->staff_note_at?->toIso8601String() ?? '';
                                $updatedAt  = $r->updated_at?->toIso8601String() ?? '';
                                $resolvedAt = $resolved?->toIso8601String() ?? '';
                            @endphp
                            <button type="button"
                                    onclick="openNoteDrawer('dandori',{{ $r->id }},{{ \Illuminate\Support\Js::from($note) }},true,'{{ $updatedAt }}','{{ $noteAt }}','{{ $resolvedAt }}',{{ \Illuminate\Support\Js::from($resolvedBy) }})"
                                    class="btn btn-sm"
                                    style="background:{{ $resolved ? '#f0fdf4' : '#fef9c3' }};border:1px solid {{ $resolved ? '#86efac' : '#fde047' }};color:{{ $resolved ? '#166534' : '#713f12' }};white-space:nowrap">
                                <i class="bi bi-{{ $resolved ? 'check2-circle' : 'chat-left-text-fill' }}"></i> Note
                            </button>
                            @endif
                        </div>
                        @elseif(! auth()->user()->isGuest())
                        @php $staffResolvedAt = $r->staff_note_resolved_at?->toIso8601String() ?? ''; @endphp
                        <button type="button"
                                onclick="openNoteDrawer('dandori',{{ $r->id }},{{ \Illuminate\Support\Js::from($note ?? '') }},false,'','','{{ $staffResolvedAt }}','')"
                                class="btn btn-sm {{ $staffResolvedAt ? 'btn-success' : ($note ? 'btn-warning' : 'btn-outline-secondary') }}"
                                style="white-space:nowrap">
                            <i class="bi bi-{{ $staffResolvedAt ? 'check2-circle' : ('chat-left-text' . ($note ? '-fill' : '')) }} me-1"></i>
                            {{ $staffResolvedAt ? 'Selesai' : ($note ? 'Edit Note' : 'Note') }}
                        </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="15"
                        style="text-align:center;color:#888;padding:20px">
                        Belum ada data Dandori pada rentang tanggal ini.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg"><div class="mt-3">{{ $rincian->links() }}</div></div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

{{-- ── Status bar: cakupan data yang sedang dilihat, selalu terlihat ── --}}
<div class="erp-status">
    <span>Period {{ \Carbon\Carbon::parse($dateFrom)->format('M Y') }} &mdash;
          From {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}
          To {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</span>
    <span><b>{{ number_format($rincian->total()) }}</b> baris</span>
    @if($filterActive)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}

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
        resolvedBy=$event.detail.resolvedBy||''; resolveAction=$event.detail.resolveAction||''"
     x-on:keydown.escape.window="open=false">

    <div x-show="open" x-cloak @click="open=false"
         style="position:fixed;inset:0;z-index:1190"></div>

    <div x-show="open" x-cloak
         x-transition:enter="transition ease-out duration-250"
         x-transition:enter-start="transform translate-x-full"
         x-transition:enter-end="transform translate-x-0"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="transform translate-x-0"
         x-transition:leave-end="transform translate-x-full"
         class="erp-drawer"
         style="position:fixed;top:0;right:0;height:100dvh;width:420px;max-width:100vw;
                z-index:1200;display:flex;flex-direction:column;
                background:#fff;box-shadow:-6px 0 32px rgba(0,0,0,.15)">

        <div class="erp-drawer-hd">
            <div>
                <i class="bi bi-chat-left-text-fill me-2"></i>
                <span x-show="isAdmin">Note dari Staff</span>
                <span x-show="!isAdmin">Note untuk Admin</span>
            </div>
            <button type="button" @click="open=false"
                    >
                <i class="bi bi-x-lg"></i>
            </button>
        </div>

        <div style="flex:1;overflow-y:auto;padding:24px">
            <div x-show="isAdmin">
                <p style="color:#6b7280;margin-bottom:8px">Catatan dari staff:</p>
                <div x-text="text"
                     style="background:#fafafa;border:1px solid #e5e7eb;border-radius:4px;
                            padding:12px;min-height:80px;white-space:pre-wrap;color:#111827"></div>
                <div x-show="noteAt" style="color:#9ca3af;margin-top:6px">
                    Dikirim: <span x-text="noteAt ? new Date(noteAt).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}) : ''"></span>
                </div>
                <div style="margin-top:16px">
                    <div x-show="dataChanged === true"
                         style="padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;color:#166534">
                        <i class="bi bi-check-circle-fill me-1"></i>Data sudah diperbarui setelah note dikirim
                    </div>
                    <div x-show="dataChanged === false"
                         style="padding:10px 14px;background:#fef9c3;border:1px solid #fde047;border-radius:4px;color:#713f12">
                        <i class="bi bi-exclamation-triangle-fill me-1"></i>Data <strong>belum</strong> diperbarui sejak note dikirim
                    </div>
                </div>
                <div x-show="resolvedAt"
                     style="margin-top:12px;padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;color:#166534">
                    <i class="bi bi-check2-circle me-1"></i>
                    Sudah ditandai selesai oleh <strong x-text="resolvedBy"></strong>
                    pada <span x-text="resolvedAt ? new Date(resolvedAt).toLocaleString('id-ID',{dateStyle:'medium',timeStyle:'short'}) : ''"></span>
                </div>
            </div>
            <div x-show="!isAdmin">
                <template x-if="resolvedAt">
                    <div>
                        <div style="padding:10px 14px;background:#f0fdf4;border:1px solid #86efac;border-radius:4px;color:#166534;margin-bottom:12px">
                            <i class="bi bi-lock-fill me-1"></i>
                            Note sudah di-feedback admin. Tidak dapat diubah lagi.
                        </div>
                        <div style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:4px;padding:12px 14px;color:#374151;white-space:pre-wrap" x-text="text || '(kosong)'"></div>
                    </div>
                </template>
                <template x-if="!resolvedAt">
                    <div>
                        <p style="color:#6b7280;margin-bottom:14px;line-height:1.6">
                            Tulis catatan untuk admin mengenai kesalahan pada data ini.
                            Admin akan melihat catatan ini dan dapat memperbaiki data.
                        </p>
                        <textarea x-model="text" rows="6"
                                  class="form-control" style="resize:vertical"
                                  placeholder="Contoh: Actual menit seharusnya 20, bukan 15. Mohon dikoreksi."
                                  maxlength="1000"></textarea>
                        <div style="color:#9ca3af;text-align:right;margin-top:4px">
                            <span x-text="text.length">0</span>/1000
                        </div>
                        <div x-show="hasNote" style="margin-top:16px;padding:10px 14px;
                             background:#fefce8;border:1px solid #fde047;border-radius:4px;color:#713f12">
                            <i class="bi bi-info-circle me-1"></i>
                            Note sebelumnya sudah dikirim. Kamu bisa ubah atau hapus.
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <div style="flex-shrink:0;padding:16px 20px;border-top:1px solid #e5e7eb;display:flex;gap:8px;justify-content:flex-end">
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
$_taKaryawan   = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taMesin      = $mesin->map(fn($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
$_taParts      = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData    = $parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses])->values()])->values();
$_losstimeData = $losstimes->map(fn($lt) => ['id' => $lt->id, 'kode' => $lt->kode, 'jenis_losstime' => $lt->jenis_losstime, 'std_menit_default' => (float)($lt->std_menit_default ?? 0), 'std_menit_progressive' => (float)($lt->std_menit_progressive ?? 0)])->values();
@endphp

@push('scripts')
<script>
/* ── Data dock filter (dipakai komponen bersama di layout) ── */
const _customersTA   = @json($_customersTA);
const _partsTA       = @json($_partsTA);
const _partsFullData = @json($_partsForFilter);
const _prosesTA      = @json($_prosesTA);
const _operatorTA    = @json($_operatorTA);
const _mesinTA       = @json($_mesinTA);
const _losstimeTA    = @json($_losstimeTA);

/* ── Static data from PHP ────────────────────────────────── */
const taKaryawan   = @json($_taKaryawan);
const taMesin      = @json($_taMesin);
const taParts      = @json($_taParts);
const partsData    = @json($_partsData);
const losstimeData = @json($_losstimeData);

/* taField() kini satu definisi di layouts/app.blade.php. */

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
