@extends('layouts.app')
@section('title', 'LHR Repair')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@php
$_partsForFilter = $parts->map(fn ($p) => [
    'id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer,
    'proses' => $p->prosesRepair->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_allProsesForFilter = $parts->flatMap(fn ($p) => $p->prosesRepair)->unique('id')
    ->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_customersTA = $customers->map(fn ($c) => ['v' => $c, 't' => $c])->values();
$_partsTA     = $_partsForFilter->map(fn ($p) => ['v' => $p['id'], 't' => $p['part_name'], 'sub' => $p['part_no'], 'pno' => $p['part_no']])->values();
$_prosesTA    = $_allProsesForFilter->map(fn ($pr) => ['v' => $pr['id'], 't' => $pr['label']])->values();
$_karyawanTA  = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();

$_karLabel    = $filterKarId ? ($karyawan->firstWhere('id', $filterKarId)?->nama_karyawan ?? '') : '';
$_partLabel   = $filterPartId ? ($parts->firstWhere('id', $filterPartId)?->part_name ?? '') : '';
$_prosesObjF  = $filterProsesId ? $parts->flatMap(fn ($p) => $p->prosesRepair)->firstWhere('id', $filterProsesId) : null;
$_prosesLabel = $_prosesObjF ? $_prosesObjF->id_proses.' – '.$_prosesObjF->nama_proses : '';
@endphp

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('input.repair.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Input Baru
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>
    @if(auth()->user()->hasRole('admin'))
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportRepairPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @endif
    <a href="{{ route('input.repair.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('input.repair.index') }}" class="erp-dock-body">

        <div class="erp-grp">
            <b>Find</b>
            <div x-data="filterSearchComp(_karyawanTA, {{ $filterKarId ?: 0 }}, '{{ addslashes($_karLabel) }}')"
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
        </div>

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
                           placeholder="Proses Repair" class="erp-in" :class="prosesVal ? 'is-set' : ''">
                    <button type="button" x-show="prosesVal" @mousedown.prevent="prosesVal='';prosesQuery=''" class="erp-clr">&times;</button>
                    <div x-show="prosesOpen && filteredProses.length > 0" x-cloak class="erp-dd">
                        <template x-for="item in filteredProses" :key="item.v">
                            <div @mousedown.prevent="prosesVal=item.v;prosesQuery=item.t;prosesOpen=false"
                                 class="erp-dd-item" x-text="item.t"></div>
                        </template>
                    </div>
                </div>

                <input type="text" class="erp-in" readonly tabindex="-1"
                       :value="partNo || 'Nomor Part'" :title="partNo">
            </div>
        </div>

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
            <input type="hidden" name="filter_apply" value="1">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('input.repair.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">

@if(auth()->user()->hasRole('admin'))
<x-export-modal
    panelId="exportRepairPanel"
    :exportUrl="route('input.repair.export')"
    title="Export LHR Repair"
    :dateFrom="$dateFrom"
    :dateTo="$dateTo"
/>
@endif

<div class="erp-scroll">
        <table class="erp-table" style="min-width:1200px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th style="width:85px">Tanggal</th>
                    <th style="width:70px">Shift</th>
                    <th style="width:130px">Operator</th>
                    <th style="width:110px">Customer</th>
                    <th style="width:120px">Part No.</th>
                    <th style="width:200px">Part Name</th>
                    <th style="width:150px">Proses</th>
                    <th style="width:110px">Jam</th>
                    <th style="width:80px;text-align:right">Qty Act</th>
                    <th style="width:70px;text-align:right">SPH Std</th>
                    <th style="width:70px;text-align:right">SPH Act</th>
                    <th style="width:70px;text-align:right">W. Kerja</th>
                    <th style="width:70px;text-align:right">CT Act</th>
                    <th style="width:60px;text-align:right">%</th>
                    @if(auth()->user()->hasRole('admin'))
                    <th style="width:140px">Aksi</th>
                    @endif
                </tr>
            </thead>
            <tbody>
            @forelse($repairs as $r)
                @php
                    $pp = $r->partProsesRepair;
                    $part = $pp?->part;
                    $sphStd = $pp?->sph_standar_repair ?? 0;
                    $sphAct = $r->sph_aktual;
                    $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal_repair)->format('d/m/Y') }}</td>
                    <td style="white-space:nowrap">{{ $r->shift?->nama_shift ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $r->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td style="color:#5d7283">{{ $part?->customer ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_no ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_name ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $pp?->nama_proses ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $r->jam_start }} – {{ $r->jam_finish }}</td>
                    <td style="text-align:right;font-weight:600">{{ number_format($r->qty_aktual) }}</td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($sphStd) }}</td>
                    <td style="text-align:right;font-weight:600;color:{{ $sphAct >= $sphStd ? '#27ae60' : '#c0392b' }}">
                        {{ number_format($sphAct) }}
                    </td>
                    <td style="text-align:right;color:#5d7283">{{ number_format($r->waktu_kerja_jam, 1) }}j</td>
                    <td style="text-align:right">{{ number_format($r->actual_cycle_time, 1) }}s</td>
                    <td style="text-align:right;font-weight:600;color:{{ $pct === null ? '#9ca3af' : ($pct >= 100 ? '#15803d' : '#b91c1c') }}">
                        {{ $pct !== null ? $pct.'%' : '—' }}
                    </td>
                    @if(auth()->user()->hasRole('admin'))
                    <td>
                        <div class="action-btns" style="flex-wrap:nowrap">
                            <a href="{{ route('input.repair.edit', $r) }}?back_query={{ urlencode(http_build_query(request()->query())) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.repair.destroy', $r) }}"
                                  onsubmit="return confirm('Hapus data ini?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
            @empty
                <tr><td colspan="{{ auth()->user()->hasRole('admin') ? 15 : 14 }}" style="text-align:center;color:#888;padding:20px">Belum ada data LHR.</td></tr>
            @endforelse
            </tbody>
        </table>
</div>

<div class="erp-pg">{{ $repairs->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($repairs->total()) }}</b> baris LHR</span>
    @if($filterActive)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>

<script>
@if (session('batch_tersimpan'))
(function() {
    try {
        localStorage.removeItem('optima_draft_lhr_repair_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_lhr_repair_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
</script>

</div>
@endsection

@push('scripts')
<script>
const _customersTA   = @json($_customersTA);
const _partsTA       = @json($_partsTA);
const _partsFullData = @json($_partsForFilter);
const _prosesTA      = @json($_prosesTA);
const _karyawanTA    = @json($_karyawanTA);
</script>
@endpush
