@extends('layouts.app')
@section('title', 'LHP Dandori')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@php
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

{{-- ══ SATU ALPINE SCOPE — mengontrol konten halaman ══ --}}
<div x-data="{ filterOpen: false }">

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
    <a href="{{ route('input.dandori.create') }}" class="erp-tb-btn">
        <i class="bi bi-plus-lg"></i>Dandori tanpa LHP
    </a>
    @endif

    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''"
            @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>

    @if(auth()->user()->hasRole('admin'))
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

{{-- Bag error validasi bawaan Laravel (mis. upload import gagal: file kosong,
     tipe bukan xlsx/xls/csv, atau lebih dari 10MB). Drawer lama yang dulu
     merender $errors sudah dicabut bareng laci penulis — tanpa blok ini,
     kegagalan validasi cuma redirect balik ke halaman ini tanpa pesan apa
     pun, seolah tidak terjadi apa-apa. --}}
@if ($errors->any())
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
            <div>
                <strong>Import gagal:</strong>
                <ul class="mb-0">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        </div>
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

@if(auth()->user()->hasRole('admin'))
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
                        @if(auth()->user()->hasRole('admin'))
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

@if (session('batch_tersimpan'))
(function() {
    /* Draft di localStorage cuma hidup di browser, jadi cuma boleh dibuang
       kalau server sudah MEMBUKTIKAN barisnya tersimpan — flash
       batch_tersimpan dari DandoriController::batch() adalah bukti itu.
       Kunci ditulis SAMA PERSIS dengan `kunci` dan `kunciBackup` di
       konfigDandori() (create.blade.php) supaya keduanya selalu menunjuk
       baris yang sama untuk pengguna yang sama. Dijaga
       HalamanBatchDandoriTest::test_kunci_draft_cocok_dengan_yang_dihapus_index. */
    try {
        localStorage.removeItem('optima_draft_dandori_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_dandori_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
</script>

</div>{{-- /alpine outer scope --}}
@endsection

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

</script>
@endpush
