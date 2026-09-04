@extends('layouts.app')
@section('title', 'Master Part / Dies')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@php
/* ── Data dock filter ── */
$_partsForFilter = $partsForFilter->map(fn ($pp) => [
    'id' => $pp->id, 'part_no' => $pp->part_no, 'part_name' => $pp->part_name, 'customer' => $pp->customer,
    'proses' => $pp->proses->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_partsTA  = $_partsForFilter->map(fn ($x) => ['v' => $x['id'], 't' => $x['part_name'], 'sub' => $x['part_no'], 'pno' => $x['part_no']])->values();
$_prosesTA = $partsForFilter->flatMap(fn ($pp) => $pp->proses)->unique('id')
    ->map(fn ($pr) => ['v' => $pr->id, 't' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_partLabel   = $filterPartId ? ($partsForFilter->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$_prosesObjF  = $filterProsesId ? $partsForFilter->flatMap(fn ($pp) => $pp->proses)->firstWhere('id', $filterProsesId) : null;
$_prosesLabel = $_prosesObjF ? $_prosesObjF->id_proses.' – '.$_prosesObjF->nama_proses : '';
@endphp

@section('content')

{{-- ══ ALPINE SCOPE ══ --}}
<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.part.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah Part
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterCustomer !== '' || $filterPartId || $filterProsesId)<span aria-hidden="true">&bull;</span>@endif
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('export-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @if(auth()->user()->hasRole('admin'))
    <a href="{{ route('master.part.sph-recommendations') }}" class="erp-tb-btn @if($sphRecTotal > 0) is-anom @endif">
        <i class="bi bi-graph-up-arrow"></i>Rekomendasi SPH
        @if($sphRecTotal > 0)<span aria-hidden="true">&bull; {{ $sphRecTotal }}</span>@endif
    </a>
    @endif
    <a href="{{ route('master.part.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('master.part.index') }}" class="erp-dock-body">

        <div class="erp-grp">
            <b>Find</b>
            <input type="text" name="q" value="{{ $search }}" class="erp-in @if($search !== '') is-set @endif"
                   placeholder="Part No, Part Name, Customer, Proses">
        </div>

        <div class="erp-grp">
            <b>Part &amp; Customer</b>
            <select name="filter_customer" class="erp-in @if($filterCustomer !== '') is-set @endif">
                <option value="">Semua Customer</option>
                @foreach($customerList as $c)
                <option value="{{ $c }}" @selected($filterCustomer === $c)>{{ $c }}</option>
                @endforeach
            </select>

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

        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('master.part.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja.
     Panel rekomendasi SPH ikut di dalamnya supaya bisa digulung bersama
     tabel — dia bisa panjang dan tidak boleh mendorong grid keluar layar. --}}
<div class="erp-scroll erp-scroll-pad">

<div id="export-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header">
        <i class="bi bi-file-earmark-excel me-1"></i>Export Master Part / Dies
    </div>
    <div class="erp-panel-body">
        <form method="GET" action="{{ route('master.part.export') }}">
            <div class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label mb-1" style="font-size:0.92rem">Customer</label>
                    <select name="customer" class="form-select form-select-sm">
                        <option value="">-- Semua Customer --</option>
                        @foreach($customerList as $c)
                        <option value="{{ $c }}">{{ $c }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-download me-1"></i>Download Excel
                    </button>
                </div>
            </div>
            <div class="mt-2" style="font-size:0.88rem;color:#6c757d">
                Kosongkan Customer untuk ekspor semua part / dies.
            </div>
        </form>
    </div>
</div>

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

{{-- Kepala panel dibuang: judul di topbar, jumlah baris dan penanda filter
     aktif sudah ada di status bar. Tabel dibungkus overflow-x sendiri —
     panel Export/Rekomendasi SPH di atas jangan ikut tergeser pas tabel
     digulung mendatar. --}}
    <div style="overflow-x:auto">
    <table class="erp-table" style="min-width:900px">
        <thead>
            <tr>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb">Customer</th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">Part No</th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">Part Name</th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb">ID Proses</th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">Nama Proses</th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb">SPH Produksi<br><span style="font-weight:400;font-size:0.72rem">+ tgl upgrade</span></th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb">SPH Quality<br><span style="font-weight:400;font-size:0.72rem">+ tgl upgrade</span></th>
                <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;width:160px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($rows as $proses)
            @php $part = $proses->part; @endphp
            <tr>
                <td>{{ $part->customer ?? '—' }}</td>
                <td style="white-space:nowrap">{{ $part->part_no }}</td>
                <td style="white-space:nowrap">{{ $part->part_name }}</td>
                <td style="font-weight:600">{{ $proses->id_proses }}</td>
                <td style="white-space:nowrap">{{ $proses->nama_proses }}</td>
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
                        <span style="font-size:0.7rem;color:var(--c-sph-qc);white-space:nowrap"
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
                    @if($search !== '')
                        Tidak ada part yang cocok dengan "<strong>{{ $search }}</strong>".
                        <a href="{{ route('master.part.index') }}" class="ms-1">Reset pencarian</a>
                    @else
                        Belum ada data part &amp; proses.
                        <a href="{{ route('master.part.create') }}">Tambah Part Baru</a>
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
    </div>{{-- /overflow-x --}}
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $rows->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($rows->total()) }}</b> baris part-proses</span>
    @if($search !== '' || $filterCustomer !== '' || $filterPartId || $filterProsesId)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


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
                <th style="white-space:nowrap">Part No</th>
                <th style="white-space:nowrap">Part Name</th>
                <th style="width:200px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @foreach($partsTanpaProses as $p)
            <tr style="background:#fffbf0">
                <td>{{ $p->customer ?? '—' }}</td>
                <td style="white-space:nowrap">{{ $p->part_no }}</td>
                <td style="white-space:nowrap">{{ $p->part_name }}</td>
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

@push('scripts')
<script>
/* ── Data dock filter (komponen bersama di layout) ── */
const _partsTA       = @json($_partsTA);
const _partsFullData = @json($_partsForFilter);
const _prosesTA      = @json($_prosesTA);
</script>
@endpush
