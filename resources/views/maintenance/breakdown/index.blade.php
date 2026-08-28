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
    $isAdmin      = auth()->user()->role === 'admin';
@endphp

<style>
.fbar-lbl{font-size:.67rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;color:#94a3b8;margin-bottom:4px}
</style>

{{-- ══ SATU ALPINE SCOPE — drawer + filter ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }}, filterOpen: false }">

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
            <i class="bi bi-tools me-2"></i>Input Breakdown Mesin
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

        @if($mesinList->isEmpty())
        <div class="alert alert-warning mb-3" >
            <strong>Master mesin belum ada:</strong> <a href="{{ route('master.mesin.index') }}">Tambah Mesin</a> dulu.
        </div>
        @endif

        <form method="POST" action="{{ route('maintenance.breakdown.store') }}" novalidate
              x-data="{ nomor: '{{ old('nomor_urut', $nextNomor) }}', tgl: '{{ old('tanggal_breakdown', date('Y-m-d')) }}',
                        get noLkm() {
                            if (!this.nomor || !this.tgl) return '—';
                            const r = ['','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
                            const d = new Date(this.tgl);
                            return `${this.nomor}/LKM/MTC/KMI/${r[d.getMonth()+1]}/${String(d.getFullYear()).slice(-2)}`;
                        } }">
            @csrf
            <div class="row g-3">

                {{-- Nomor Urut + preview No.LKM --}}
                <div class="col-5">
                    <label class="form-label" >Nomor Urut LKM <span class="text-danger">*</span></label>
                    <input type="number" name="nomor_urut" min="1" x-model="nomor"
                           class="form-control @error('nomor_urut') is-invalid @enderror">
                    @error('nomor_urut')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-7">
                    <label class="form-label" >No. LKM (otomatis)</label>
                    <div class="form-control" style="background:#f3f4f6;font-family:monospace;color:#111827" x-text="noLkm"></div>
                </div>

                {{-- Mesin (typeahead, identik input produksi) --}}
                @php
                    $oldMesinId  = old('id_mesin');
                    $oldMesinObj = $oldMesinId ? $mesinList->firstWhere('id', (int) $oldMesinId) : null;
                    $oldMesinTxt = $oldMesinObj ? "{$oldMesinObj->id_mesin} – {$oldMesinObj->nama_mesin}" : '';
                @endphp
                <div class="col-12" x-data="taField(taMesin, '{{ addslashes($oldMesinTxt) }}', {{ $oldMesinId ?: 'null' }})">
                    <label class="form-label" >Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input @error('id_mesin') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik kode atau nama mesin...">
                        <input type="hidden" name="id_mesin" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_mesin')<div class="invalid-feedback d-block" >{{ $message }}</div>@enderror
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
                @foreach(['problem_mesin'=>'Problem Mesin','penyebab_breakdown'=>'Penyebab Breakdown','tindakan_perbaikan'=>'Tindakan Perbaikan'] as $col=>$label)
                <div class="col-12">
                    <label class="form-label" >{{ $label }} <span class="text-danger">*</span></label>
                    <textarea name="{{ $col }}" rows="2" class="form-control @error($col) is-invalid @enderror">{{ old($col) }}</textarea>
                    @error($col)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endforeach

                {{-- PIC --}}
                <div class="col-12">
                    <label class="form-label" >PIC Maintenance <span class="text-danger">*</span></label>
                    <input type="text" name="pic_maintenance" value="{{ old('pic_maintenance') }}"
                           class="form-control @error('pic_maintenance') is-invalid @enderror">
                    @error('pic_maintenance')<div class="invalid-feedback">{{ $message }}</div>@enderror
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

const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin])->values());

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
