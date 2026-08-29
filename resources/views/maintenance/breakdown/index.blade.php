@extends('layouts.app')
@section('title', 'Breakdown Mesin')
{{-- Halaman ini menampilkan pesan flash-nya sendiri; tanpa ini pesan
     sukses muncul dua kali, dari layout dan dari halaman. --}}
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@php
/* ── Data dock filter ── */
$_mesinTA    = $mesinList->map(fn ($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin])->values();
$_picTA      = $picList->map(fn ($k) => ['v' => $k->nama_karyawan, 't' => $k->nama_karyawan])->values();
$_mesinObjF  = $mesinId ? $mesinList->firstWhere('id', $mesinId) : null;
$_mesinLabel = $_mesinObjF ? $_mesinObjF->id_mesin.' – '.$_mesinObjF->nama_mesin : '';
$filterActive = $mesinId || $filterPic !== '' || $filterStatus !== '';
@endphp

@section('content')

@php
    $filterActive = $mesinId > 0 || $dateFrom !== date('Y-m-01') || $dateTo !== date('Y-m-d');
    $isAdmin      = auth()->user()->hasRole('admin');
@endphp

<style>
.fbar-lbl{font-size:.67rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:4px}
</style>

{{-- ══ SATU ALPINE SCOPE — mengontrol konten halaman ══ --}}
<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══ --}}
{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}
<div class="erp-ws">

{{-- ── Strip toolbar ─────────────────────────────────────────── --}}
<div class="erp-tb">
    <a href="{{ route('maintenance.breakdown.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Input Baru
    </a>

    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''"
            @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>

    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-breakdown-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportBreakdownPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>

    <a href="{{ route('maintenance.breakdown.index') }}" class="erp-tb-btn">
        <i class="bi bi-arrow-clockwise"></i>Refresh
    </a>

    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- ── Dock filter (kiri) ────────────────────────────────────── --}}
<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('maintenance.breakdown.index') }}" class="erp-dock-body">

        {{-- ══ Find ══
             Tanpa Part & Customer: breakdown mesin tidak terikat part mana pun. --}}
        <div class="erp-grp">
            <b>Find</b>
            <div x-data="filterSearchComp(_mesinTA, {{ $mesinId ?: 0 }}, '{{ addslashes($_mesinLabel) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="mesin_id" :value="val">
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
            <div x-data="filterSearchComp(_picTA, '{{ $filterPic }}', '{{ addslashes($filterPic) }}')"
                 @click.outside="open=false" style="position:relative">
                <input type="hidden" name="filter_pic" :value="val">
                <input type="text" x-model="query" @focus="open=true" @input="open=true"
                       @keydown.escape="open=false" placeholder="PIC Maintenance"
                       class="erp-in" :class="val ? 'is-set' : ''">
                <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                    <template x-for="item in filtered" :key="item.v">
                        <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                    </template>
                </div>
            </div>
            <select name="filter_status" class="erp-in @if($filterStatus !== '') is-set @endif">
                <option value="">Semua Status</option>
                @foreach(['Ringan', 'Sedang', 'Berat'] as $st)
                <option value="{{ $st }}" @selected($filterStatus === $st)>{{ $st }}</option>
                @endforeach
            </select>
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
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Terapkan</button>
            <a href="{{ route('maintenance.breakdown.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">

{{-- Bag error validasi bawaan Laravel (mis. upload import gagal: file kosong,
     tipe bukan xlsx/xls/csv, atau lebih dari 5MB). Drawer lama yang dulu
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

{{-- Export Panel --}}
<x-export-modal
    panelId="exportBreakdownPanel"
    :exportUrl="route('maintenance.breakdown.export')"
    title="Export Breakdown Mesin"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
    :showProses="false"
/>

{{-- Import Panel --}}
<div id="import-breakdown-panel" class="erp-panel mb-3 {{ session('import_errors') !== null || session('import_success') ? '' : 'd-none' }}">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Breakdown Mesin dari Excel</span>
        <a href="{{ route('maintenance.breakdown.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('maintenance.breakdown.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-7">
                    <label class="form-label mb-1" >File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control" accept=".xlsx,.xls,.csv" required>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm"><i class="bi bi-upload me-1"></i>Upload &amp; Import</button>
                </div>
            </div>
            <div class="mt-2" style="color:#6c757d">
                Kolom: Nomor Urut · ID Mesin · Tanggal/Jam Breakdown · Mulai · Selesai · Problem · Penyebab · Tindakan · PIC. Nomor Urut boleh kosong (auto).
            </div>
        </form>
    </div>
</div>

@if(session('import_errors') && count(session('import_errors')) > 0)
    <div class="alert alert-warning alert-dismissible fade show mb-3">
        <strong>{{ count(session('import_errors')) }} baris dilewati:</strong>
        <div style="max-height:180px;overflow-y:auto;margin-top:6px">
            <ul class="mb-0">@foreach(session('import_errors') as $err)<li>{{ $err }}</li>@endforeach</ul>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
{{-- Judul sudah ada di topbar dan jumlah data sudah ada di status bar;
     kepala panel hanya memakan tinggi. Penggulungan dipegang .erp-scroll
     saja — wadah gulung bertumpuk mematikan kepala tabel yang menempel. --}}
<div class="erp-scroll">
        <table class="erp-table tbl-freeze" style="min-width:1650px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th class="sc sc-1" style="width:90px">Tanggal</th>
                    <th class="sc sc-2" style="width:155px">No. LKM</th>
                    <th class="sc sc-3" style="width:70px">Mesin</th>
                    <th class="sc sc-4" style="width:140px">Nama Mesin</th>
                    <th class="sc sc-5 sc-sep" style="width:80px">Status</th>
                    <th style="width:90px">Jam BD</th>
                    <th style="width:80px;text-align:right">Repair (j)</th>
                    <th style="width:95px;text-align:right">Downtime (j)</th>
                    <th style="width:120px">PIC</th>
                    <th style="min-width:180px">Problem</th>
                    <th style="min-width:180px">Penyebab</th>
                    <th style="min-width:180px">Tindakan</th>
                    @if($isAdmin)<th style="width:120px">Aksi</th>@endif
                </tr>
            </thead>
            <tbody>
            @forelse($breakdowns as $b)
                @php
                    $st = $b->status_breakdown;
                    $stColor = $st === 'Berat' ? '#b91c1c' : ($st === 'Sedang' ? '#b45309' : '#15803d');
                    $stBg    = $st === 'Berat' ? '#fee2e2' : ($st === 'Sedang' ? '#fef3c7' : '#dcfce7');
                @endphp
                <tr>
                    <td class="sc sc-1" style="white-space:nowrap">{{ \Carbon\Carbon::parse($b->tanggal_breakdown)->format('d/m/Y') }}</td>
                    <td class="sc sc-2" style="white-space:nowrap;font-family:monospace">{{ $b->no_lkm }}</td>
                    <td class="sc sc-3" style="font-weight:600">{{ $b->mesin?->id_mesin ?? '—' }}</td>
                    <td class="sc sc-4" style="white-space:nowrap">{{ $b->mesin?->nama_mesin ?? '—' }}</td>
                    <td class="sc sc-5 sc-sep">
                        <span style="background:{{ $stBg }};color:{{ $stColor }};font-weight:700;padding:2px 8px;border-radius:999px">{{ $st }}</span>
                    </td>
                    <td style="white-space:nowrap">{{ \Illuminate\Support\Str::of($b->jam_breakdown)->substr(0,5) }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($b->repair_time,1) }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($b->total_downtime,1) }}</td>
                    <td >{{ $b->pic_maintenance }}</td>
                    <td style="color:#5d7283">{{ \Illuminate\Support\Str::limit($b->problem_mesin,70) }}</td>
                    <td style="color:#5d7283">{{ \Illuminate\Support\Str::limit($b->penyebab_breakdown,70) }}</td>
                    <td style="color:#5d7283">{{ \Illuminate\Support\Str::limit($b->tindakan_perbaikan,70) }}</td>
                    @if($isAdmin)
                    <td style="white-space:nowrap">
                        <a href="{{ route('maintenance.breakdown.edit',$b) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('maintenance.breakdown.destroy',$b) }}" class="d-inline"
                              onsubmit="return confirm('Hapus breakdown {{ $b->no_lkm }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $isAdmin ? 13 : 12 }}" style="text-align:center;color:#9ca3af;padding:30px">Belum ada data breakdown.</td></tr>
            @endforelse
            </tbody>
        </table>
</div>{{-- /erp-scroll --}}

@if($breakdowns->hasPages())
<div class="erp-pg">{{ $breakdowns->links() }}</div>
@endif

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

{{-- ── Status bar: cakupan data yang sedang dilihat, selalu terlihat ── --}}
<div class="erp-status">
    <span>Period {{ \Carbon\Carbon::parse($dateFrom)->format('M Y') }} &mdash;
          From {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}
          To {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</span>
    <span><b>{{ number_format($breakdowns->total()) }}</b> data</span>
    @if($filterActive)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine scope --}}
@endsection

@push('scripts')
<script>
/* ── Data dock filter (komponen bersama di layout) ── */
const _mesinTA = @json($_mesinTA);
const _picTA   = @json($_picTA);

/* taField() kini satu definisi di layouts/app.blade.php. */

@if (session('batch_tersimpan'))
(function() {
    /* Draft di localStorage cuma hidup di browser, jadi cuma boleh dibuang
       kalau server sudah MEMBUKTIKAN barisnya tersimpan — flash
       batch_tersimpan dari BreakdownMesinController::batch() adalah bukti
       itu. Kunci dibaca dengan pola yang SAMA PERSIS dengan create.blade.php
       (`kunci` dan `kunciBackup` di konfigBreakdownMesin()) supaya keduanya
       selalu menunjuk baris yang sama untuk pengguna yang sama. Tanpa blok
       ini, draft yang sudah tersimpan tetap ada di localStorage, mulai()
       memulihkannya lagi di kunjungan berikutnya, dan satu klik SUBMIT lagi
       menulisnya DOBEL ke transaksi_breakdown_mesin — nol unique constraint
       yang mencegahnya, nol error yang muncul. */
    try {
        localStorage.removeItem('optima_draft_breakdown_mesin_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_breakdown_mesin_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
</script>
@endpush
