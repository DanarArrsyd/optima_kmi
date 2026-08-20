@extends('layouts.app')
@section('title', 'Breakdown Dies')
{{-- Halaman ini menampilkan pesan flash-nya sendiri; tanpa ini pesan
     sukses muncul dua kali, dari layout dan dari halaman. --}}
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

@php
    $filterActive = $partId > 0 || $dateFrom !== date('Y-m-01') || $dateTo !== date('Y-m-d');
    $isAdmin      = auth()->user()->role === 'admin';
@endphp

<style>
.fbar-lbl{font-size:.67rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:4px}
</style>

{{-- ══ SATU ALPINE SCOPE — drawer + filter ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }}, filterOpen: {{ $filterActive ? 'true' : 'false' }} }">

{{-- ── DRAWER BACKDROP ── --}}
<div x-show="drawerOpen" x-cloak @click="drawerOpen = false"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1190"></div>

{{-- ── DRAWER PANEL ── --}}
<div x-show="drawerOpen" x-cloak
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="transform translate-x-full" x-transition:enter-end="transform translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-x-0" x-transition:leave-end="transform translate-x-full"
     style="position:fixed;top:0;right:0;height:100dvh;width:640px;max-width:100vw;z-index:1200">
    <div class="erp-drawer" style="display:flex;flex-direction:column;height:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div class="erp-drawer-hd">
        <div>
            <i class="bi bi-hammer me-2"></i>Input Breakdown Dies
        </div>
        <button type="button" @click="drawerOpen = false"
                style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;padding:0;line-height:1">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Body (scrollable) --}}
    <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:24px;overscroll-behavior:contain">

        @if($errors->any())
        <div class="alert alert-danger mb-3" >
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        @if($partList->isEmpty())
        <div class="alert alert-warning mb-3" >
            <strong>Master Part / Dies belum ada:</strong> <a href="{{ route('master.part.index') }}">Tambah Part / Dies</a> dulu.
        </div>
        @endif

        <form method="POST" action="{{ route('maintenance.breakdown-dies.store') }}" novalidate
              x-data="{ nomor: '{{ old('nomor_urut', $nextNomor) }}', tgl: '{{ old('tanggal_breakdown', date('Y-m-d')) }}',
                        get noLkd() {
                            if (!this.nomor || !this.tgl) return '—';
                            const r = ['','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
                            const d = new Date(this.tgl);
                            return `${this.nomor}/LKD/MTC/KMI/${r[d.getMonth()+1]}/${String(d.getFullYear()).slice(-2)}`;
                        } }">
            @csrf
            <div class="row g-3">

                {{-- Nomor Urut + preview No.LKD --}}
                <div class="col-5">
                    <label class="form-label" >Nomor Urut LKD <span class="text-danger">*</span></label>
                    <input type="number" name="nomor_urut" min="1" x-model="nomor"
                           class="form-control @error('nomor_urut') is-invalid @enderror">
                    @error('nomor_urut')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-7">
                    <label class="form-label" >No. LKD (otomatis)</label>
                    <div class="form-control" style="background:#f3f4f6;font-family:monospace;color:#111827" x-text="noLkd"></div>
                </div>

                {{-- Part / Dies + Proses (typeahead part, select proses — identik input produksi) --}}
                <div class="col-12" x-data="breakdownDiesPartSelector()">
                    <label class="form-label" >Part / Dies <span class="text-danger">*</span></label>
                    <div class="ta-wrap" style="margin-bottom:10px">
                        <input type="text" class="form-control ta-input @error('id_part_proses') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik part no atau nama dies...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>

                    <label class="form-label" >Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback d-block" >{{ $message }}</div>@enderror
                </div>

                {{-- Date + time pairs --}}
                @php $pairs = [
                    ['tanggal_breakdown','jam_breakdown','Breakdown'],
                    ['tanggal_mulai_perbaikan','jam_mulai_perbaikan','Mulai Perbaikan'],
                    ['tanggal_selesai_perbaikan','jam_selesai_perbaikan','Selesai Perbaikan'],
                ]; @endphp
                @foreach($pairs as [$dCol,$tCol,$label])
                <div class="col-6">
                    <label class="form-label" >Tanggal {{ $label }} <span class="text-danger">*</span></label>
                    <input type="date" name="{{ $dCol }}"
                           value="{{ old($dCol, $dCol==='tanggal_breakdown' ? date('Y-m-d') : '') }}"
                           @if($dCol==='tanggal_breakdown') x-model="tgl" @endif
                           class="form-control @error($dCol) is-invalid @enderror">
                    @error($dCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <label class="form-label" >Jam {{ $label }} <span class="text-danger">*</span></label>
                    <input type="time" name="{{ $tCol }}" value="{{ old($tCol) }}"
                           class="form-control @error($tCol) is-invalid @enderror">
                    @error($tCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endforeach

                {{-- Textareas --}}
                @foreach(['problem_dies'=>'Problem Dies','penyebab_breakdown'=>'Penyebab Breakdown','tindakan_perbaikan'=>'Tindakan Perbaikan'] as $col=>$label)
                <div class="col-12">
                    <label class="form-label" >{{ $label }} <span class="text-danger">*</span></label>
                    <textarea name="{{ $col }}" rows="2" class="form-control @error($col) is-invalid @enderror">{{ old($col) }}</textarea>
                    @error($col)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endforeach

                {{-- PIC — typeahead, bebas ketik nama baru juga --}}
                <div class="col-12" x-data="picField('{{ addslashes(old('pic_maintenance', '')) }}')">
                    <label class="form-label" >PIC Maintenance <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" name="pic_maintenance"
                               class="form-control ta-input @error('pic_maintenance') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik atau pilih nama PIC...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item"></div>
                            </template>
                        </div>
                    </div>
                    @error('pic_maintenance')<div class="invalid-feedback d-block" >{{ $message }}</div>@enderror
                </div>

            </div>{{-- /row --}}

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan Breakdown
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary" >Batal</button>
            </div>
        </form>
    </div>{{-- /drawer body --}}
    </div>
</div>{{-- /drawer panel --}}

{{-- ══ KONTEN HALAMAN ══ --}}
{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}
<div class="erp-ws">

{{-- ── Strip toolbar ─────────────────────────────────────────── --}}
<div class="erp-tb">
    <button type="button" class="erp-tb-btn is-new" @click="drawerOpen = true">
        <i class="bi bi-plus-lg"></i>Input Baru
    </button>

    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''"
            @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>

    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-dies-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportDiesPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>

    <a href="{{ route('maintenance.breakdown-dies.index') }}" class="erp-tb-btn">
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
    <form method="GET" action="{{ route('maintenance.breakdown-dies.index') }}" class="erp-dock-body">
        <div class="erp-grp">
            <b>Find</b>
            <select name="part_id" class="erp-in">
                <option value="0">Semua Dies</option>
                @foreach($partList as $p)
                <option value="{{ $p->id }}" @selected($partId == $p->id)>{{ $p->part_no }} – {{ $p->part_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-grp">
            <b>Filter by Date</b>
            <div class="erp-dock-row">
                <span>From</span>
                <input type="date" name="date_from" value="{{ $dateFrom }}" class="erp-in">
            </div>
            <div class="erp-dock-row">
                <span>To</span>
                <input type="date" name="date_to" value="{{ $dateTo }}" class="erp-in">
            </div>
        </div>
        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Terapkan</button>
            <a href="{{ route('maintenance.breakdown-dies.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">

{{-- Export Panel --}}
<x-export-modal
    panelId="exportBreakdownDiesPanel"
    :exportUrl="route('maintenance.breakdown-dies.export')"
    title="Export Breakdown Dies"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
    :showProses="false"
/>

{{-- Import Panel --}}
<div id="import-breakdown-dies-panel" class="erp-panel mb-3 {{ session('import_errors') !== null || session('import_success') ? '' : 'd-none' }}">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Breakdown Dies dari Excel</span>
        <a href="{{ route('maintenance.breakdown-dies.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('maintenance.breakdown-dies.import') }}" enctype="multipart/form-data">
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
                Kolom: Nomor Urut · Part No · Id Proses · Tanggal/Jam Breakdown · Mulai · Selesai · Problem · Penyebab · Tindakan · PIC. Nomor Urut boleh kosong (auto).
            </div>
        </form>
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
        <table class="erp-table tbl-freeze" style="min-width:1800px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th class="sc sc-1" style="width:90px">Tanggal</th>
                    <th class="sc sc-2" style="width:155px">No. LKD</th>
                    <th class="sc sc-3" style="width:120px">Part No</th>
                    <th class="sc sc-4" style="width:150px">Nama Dies</th>
                    <th style="width:140px">Proses</th>
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
                    <td class="sc sc-2" style="white-space:nowrap;font-family:monospace">{{ $b->no_lkd }}</td>
                    <td class="sc sc-3" style="font-weight:600;white-space:nowrap">{{ $b->partProses?->part?->part_no ?? '—' }}</td>
                    <td class="sc sc-4" style="white-space:nowrap">{{ $b->partProses?->part?->part_name ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $b->partProses?->nama_proses ?? '—' }}</td>
                    <td class="sc sc-5 sc-sep">
                        <span style="background:{{ $stBg }};color:{{ $stColor }};font-weight:700;padding:2px 8px;border-radius:999px">{{ $st }}</span>
                    </td>
                    <td style="white-space:nowrap">{{ \Illuminate\Support\Str::of($b->jam_breakdown)->substr(0,5) }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($b->repair_time,1) }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($b->total_downtime,1) }}</td>
                    <td >{{ $b->pic_maintenance }}</td>
                    <td style="color:#5d7283">{{ \Illuminate\Support\Str::limit($b->problem_dies,70) }}</td>
                    <td style="color:#5d7283">{{ \Illuminate\Support\Str::limit($b->penyebab_breakdown,70) }}</td>
                    <td style="color:#5d7283">{{ \Illuminate\Support\Str::limit($b->tindakan_perbaikan,70) }}</td>
                    @if($isAdmin)
                    <td style="white-space:nowrap">
                        <a href="{{ route('maintenance.breakdown-dies.edit',$b) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('maintenance.breakdown-dies.destroy',$b) }}" class="d-inline"
                              onsubmit="return confirm('Hapus breakdown {{ $b->no_lkd }}?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ $isAdmin ? 14 : 13 }}" style="text-align:center;color:#9ca3af;padding:30px">Belum ada data breakdown.</td></tr>
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

@php
$_partsProsesData = $partList->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses])->values(),
])->values();
@endphp
@push('scripts')
<script>
const taPart = @json($partList->map(fn($p) => ['v' => $p->id, 't' => $p->part_no.' – '.$p->part_name])->values());
const partsProsesData = @json($_partsProsesData);
const taPic = @json($picMaintenance->pluck('nama_karyawan')->values());

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

function breakdownDiesPartSelector() {
    return {
        ...taField(taPart),
        prosesList: [],
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pick(item) {
            this.query      = item.t;
            this.val        = item.v;
            this.open       = false;
            this.idx        = -1;
            const part      = partsProsesData.find(p => p.id == item.v);
            this.prosesList = part ? part.proses : [];
        },
    };
}

function picField(initText = '') {
    return {
        items: taPic, query: initText, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() { setTimeout(() => { this.open = false; }, 180); },
        onInput() { this.open = true; this.idx = -1; },
        pick(item) { this.query = item; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}
</script>
@endpush
