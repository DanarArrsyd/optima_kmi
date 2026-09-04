@extends('layouts.app')
@section('title', 'LHP Quality')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@php
/* ── Data dock filter ──
   Bentuknya sama dengan halaman lain supaya komponen ketik-cari bersama di
   layout bisa dipakai apa adanya. */
$_partsForFilter = $parts->map(fn ($p) => [
    'id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer,
    'proses' => $p->proses->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_allProsesForFilter = $parts->flatMap(fn ($p) => $p->proses)->unique('id')
    ->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_customersTA  = $customers->map(fn ($c) => ['v' => $c, 't' => $c])->values();
$_partsTA      = $_partsForFilter->map(fn ($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']])->values();
$_prosesTA     = $_allProsesForFilter->map(fn ($pr) => ['v' => $pr['id'], 't' => $pr['label']])->values();
$_inspectorTA  = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_jenisNgTA    = $jenisNg->map(fn ($n) => ['v' => $n->id, 't' => $n->kode.' – '.$n->nama_ng])->values();

/* Label awal isian: tanpa ini kotak tampil kosong padahal filternya menyala. */
$_karLabel    = $filterKarId ? ($karyawan->firstWhere('id', $filterKarId)?->nama_karyawan ?? '') : '';
$_ngObjF      = $filterJenisNgId ? $jenisNg->firstWhere('id', $filterJenisNgId) : null;
$_ngLabel     = $_ngObjF ? $_ngObjF->kode.' – '.$_ngObjF->nama_ng : '';
$_partLabel   = $filterPartId ? ($parts->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$_prosesObjF  = $filterProsesId ? $parts->flatMap(fn ($p) => $p->proses)->firstWhere('id', $filterProsesId) : null;
$_prosesLabel = $_prosesObjF ? $_prosesObjF->id_proses.' – '.$_prosesObjF->nama_proses : '';

$filterActive = ($search ?? '') !== '' || $filterKarId || $filterJenisNgId
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
        ? route('input.qc.index', [
            'date_from' => $myLastRecord->tgl_pengecekan,
            'date_to'   => $myLastRecord->tgl_pengecekan,
          ]).'#last-input'
        : null;
    $filterActive = ($search ?? '') !== '';
@endphp

<div class="erp-ws">

{{-- ── Strip toolbar ─────────────────────────────────────────── --}}
<div class="erp-tb">
    @if(! auth()->user()->isGuest())
    <a href="{{ route('input.qc.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Input Baru
    </a>
    @endif

    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''"
            @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>

    @if(! auth()->user()->isGuest())
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-qc-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportQcPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @endif

    <a href="{{ route('input.qc.index') }}" class="erp-tb-btn">
        <i class="bi bi-arrow-clockwise"></i>Refresh
    </a>

    @if(auth()->user()->hasRole('admin'))
    {{-- Note staff yang belum di-resolve gampang kelewat kalau tenggelam di
         tengah baris lintas beberapa hari. Badge menghitung SEMUA yang belum
         resolve dalam cakupan filter aktif (bukan cuma halaman ini); klik
         menyaring tabel supaya cuma baris ber-note terbuka yang tampil. --}}
    <a href="{{ request()->fullUrlWithQuery(['only_open_notes' => $onlyOpenNotes ? 0 : 1, 'page' => null]) }}"
       class="erp-tb-btn @if($onlyOpenNotes) is-on @elseif($openNotesCount > 0) is-anom @endif"
       title="{{ $openNotesCount }} note dari staff belum di-resolve{{ $onlyOpenNotes ? ' — klik untuk tampilkan semua lagi' : '' }}">
        <i class="bi bi-chat-left-text-fill"></i>Note
        @if($openNotesCount > 0)<span aria-hidden="true">&bull; {{ $openNotesCount }}</span>@endif
    </a>
    @endif

    @if($myLastRecord)
    <a href="{{ $myLastOnPage ? '#last-input' : $lastUrl }}" class="erp-tb-btn"
       title="Input terakhir Anda: {{ \Carbon\Carbon::parse($myLastRecord->tgl_pengecekan)->format('d/m/Y') }} &middot; {{ $myLastRecord->partProses?->part?->part_name ?? '—' }}">
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
    <form method="GET" action="{{ route('input.qc.index') }}" class="erp-dock-body">

        {{-- ══ Find ══ --}}
        <div class="erp-grp">
            <b>Find</b>
            <div x-data="filterSearchComp(_inspectorTA, {{ $filterKarId ?: 0 }}, '{{ addslashes($_karLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_karyawan_id" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Nama Inspector"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
            <div x-data="filterSearchComp(_jenisNgTA, {{ $filterJenisNgId ?: 0 }}, '{{ addslashes($_ngLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_jenis_ng_id" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="Jenis NG"
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
            <div x-data="filterSearchComp(_customersTA, '{{ $filterCustomer }}', '{{ addslashes($filterCustomer) }}')"
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
            {{-- Penanda form dikirim: tanpa ini, centang yang dilepas tidak
                 terkirim sama sekali dan server tidak bisa membedakan
                 "dimatikan" dari "halaman baru dibuka". --}}
            <input type="hidden" name="filter_apply" value="1">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('input.qc.index') }}" class="erp-btn">Reset</a>
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

@if(! auth()->user()->isGuest())
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
                    <label class="form-label mb-1" >File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
            <div class="mt-2" style="color:#6c757d">
                Mulai dari baris ke-4 (baris 1 = header, baris 2 = keterangan, baris 3 = contoh).
                Proses <em>finish good</em> dipilih otomatis dari data master.
                Download template untuk melihat referensi data master.
            </div>
        </form>
    </div>
</div>
@endif



@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="alert alert-warning alert-dismissible fade show mb-3" role="alert">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-5 mt-1 flex-shrink-0"></i>
            <div style="width:100%">
                <strong>{{ count(session('import_errors')) }} baris gagal diimport:</strong>
                <div style="max-height:180px;overflow-y:auto;margin-top:6px">
                    <ul class="mb-0" >
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
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">
        <table class="erp-table" style="min-width:1470px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th style="width:85px">Tgl Cek</th>
                    <th style="width:85px">Tgl Prod</th>
                    <th style="width:70px">Shift</th>
                    <th style="width:130px">Operator</th>
                    <th style="width:75px">Customer</th>
                    <th style="width:130px">Part No.</th>
                    <th style="width:200px">Part Name</th>
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
                    $isMyLast = $myLastRecord && $t->id === $myLastRecord->id;
                @endphp
                <tr id="{{ $isMyLast ? 'last-input' : '' }}"
                    data-row-id="{{ $t->id }}"
                    class="{{ request('highlight_id') == $t->id ? 'notif-highlight' : '' }}"
                    style="{{ $isMyLast ? 'box-shadow:inset 3px 0 0 #0284c7;' : '' }}">
                    <td>
                        {{ \Carbon\Carbon::parse($t->tgl_pengecekan)->format('d/m/Y') }}
                        @if($isMyLast)
                        <div style="margin-top:2px;font-size:0.6rem;font-weight:700;color:#0284c7;letter-spacing:.04em;line-height:1;white-space:nowrap">
                            <i class="bi bi-arrow-left-short"></i>Terakhirmu
                        </div>
                        @endif
                    </td>
                    <td style="color:#5d7283">{{ \Carbon\Carbon::parse($t->tgl_produksi_part)->format('d/m/Y') }}</td>
                    <td style="white-space:nowrap">{{ $t->shift?->nama_shift ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $t->karyawanQc?->nama_karyawan ?? '—' }}</td>
                    <td style="color:#5d7283">{{ $part?->customer ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_no ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_name ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $t->jam_start }} – {{ $t->jam_finish }}</td>
                    <td style="text-align:right;font-weight:600;color:#27ae60">{{ number_format($t->qty_ok) }}</td>
                    <td style="text-align:right;font-weight:600;color:#c0392b">
                        {{ number_format($t->qty_ng) }}
                        @if($totalQty > 0 && $t->qty_ng > 0)
                            <span class="erp-meta" style="font-weight:400;color:#888">
                                ({{ round($t->qty_ng / $totalQty * 100, 1) }}%)
                            </span>
                        @endif
                    </td>
                    <td style="color:#5d7283">
                        {{ $t->ng_ringkas ?: '—' }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($sphStd) }}</td>
                    <td style="text-align:right;font-weight:600;color:{{ $sphAct >= $sphStd ? '#27ae60' : '#c0392b' }}">
                        {{ number_format($sphAct) }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($t->waktu_kerja_jam, 1) }}j</td>
                    <td style="text-align:right">{{ number_format($t->actual_cycle_time, 1) }}s</td>
                    <td style="text-align:right;font-weight:600;color:{{ $pct === null ? '#9ca3af' : ($pct >= 100 ? '#15803d' : '#b91c1c') }}">
                        {{ $pct !== null ? $pct . '%' : '—' }}
                    </td>
                    <td>
                        @php $note = $t->staff_note ?? null; @endphp
                        @if(auth()->user()->hasRole('admin'))
                        <div class="action-btns">
                            <a href="{{ route('input.qc.edit', $t) }}"
                               class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.qc.destroy', $t) }}"
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
                                    onclick="openNoteDrawer('qc',{{ $t->id }},{{ \Illuminate\Support\Js::from($note) }},true,'{{ $updatedAt }}','{{ $noteAt }}','{{ $resolvedAt }}',{{ \Illuminate\Support\Js::from($resolvedBy) }})"
                                    class="btn btn-sm"
                                    style="background:{{ $resolved ? '#f0fdf4' : '#fef9c3' }};border:1px solid {{ $resolved ? '#86efac' : '#fde047' }};color:{{ $resolved ? '#166534' : '#713f12' }};white-space:nowrap">
                                <i class="bi bi-{{ $resolved ? 'check2-circle' : 'chat-left-text-fill' }}"></i> Note
                            </button>
                            @endif
                        </div>
                        @elseif(! auth()->user()->isGuest())
                        @php $staffResolvedAt = $t->staff_note_resolved_at?->toIso8601String() ?? ''; @endphp
                        <button type="button"
                                onclick="openNoteDrawer('qc',{{ $t->id }},{{ \Illuminate\Support\Js::from($note ?? '') }},false,'','','{{ $staffResolvedAt }}','')"
                                class="btn btn-sm {{ $staffResolvedAt ? 'btn-success' : ($note ? 'btn-warning' : 'btn-outline-secondary') }}"
                                style="white-space:nowrap">
                            <i class="bi bi-{{ $staffResolvedAt ? 'check2-circle' : ('chat-left-text' . ($note ? '-fill' : '')) }} me-1"></i>
                            {{ $staffResolvedAt ? 'Selesai' : ($note ? 'Edit Note' : 'Note') }}
                        </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="17" style="text-align:center;color:#888;padding:20px">Belum ada data LHP Quality pada rentang tanggal ini.</td></tr>
            @endforelse
            </tbody>
        </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg"><div class="mt-3">{{ $transaksi->links() }}</div></div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

{{-- ── Status bar: cakupan data yang sedang dilihat, selalu terlihat ── --}}
<div class="erp-status">
    <span>Period {{ \Carbon\Carbon::parse($dateFrom)->format('M Y') }} &mdash;
          From {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}
          To {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</span>
    <span><b>{{ number_format($transaksi->total()) }}</b> record</span>
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
                                  placeholder="Contoh: Qty NG seharusnya 3, bukan 0. Mohon dikoreksi."
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
       batch_tersimpan dari QcController::batch() adalah bukti itu.
       Kunci dibaca dengan pola yang SAMA PERSIS dengan create.blade.php
       (`kunci` dan `kunciBackup` di konfigQc()) supaya keduanya selalu
       menunjuk baris yang sama untuk pengguna yang sama. */
    try {
        localStorage.removeItem('optima_draft_lhp_qc_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_lhp_qc_terkirim_u{{ auth()->id() }}');
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
const _inspectorTA   = @json($_inspectorTA);
const _jenisNgTA     = @json($_jenisNgTA);
</script>
@endpush
