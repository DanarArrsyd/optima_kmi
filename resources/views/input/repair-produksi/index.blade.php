@extends('layouts.app')
@section('title', 'LHR Repair Produksi')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('input.repair-produksi.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Input Baru
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($filterActive)<span aria-hidden="true">&bull;</span>@endif
    </button>
    @if(auth()->user()->hasRole(['admin', 'staff_repair', 'staff_produksi']))
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-lhr-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('exportRepairProduksiPanel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </button>
    @endif
    <a href="{{ route('input.repair-produksi.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('input.repair-produksi.index')">
    <x-filter.grup judul="Find">
        <x-filter.cari name="filter_karyawan_id" placeholder="Nama Operator" :value="$filterKarId"
                       :items="$karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])" />
    </x-filter.grup>

    <x-filter.part-group :customer="$filterCustomer" :part-id="$filterPartId" :proses-id="$filterProsesId"
                         relasi-proses="prosesRepairProduksi" label-proses="Proses Repair Produksi" />

    <x-filter.tanggal :from="$dateFrom" :to="$dateTo" :pakai="$pakaiTanggal" />
</x-filter.dock>

<div class="erp-ws-main">

@if(auth()->user()->hasRole(['admin', 'staff_repair', 'staff_produksi']))
@include('partials.import-panel', [
    'id' => 'import-lhr-panel',
    'action' => route('input.repair-produksi.import'),
    'template' => route('input.repair-produksi.import-template'),
    'judul' => 'Import LHR Repair Produksi dari Excel',
])
<x-export-modal
    panelId="exportRepairProduksiPanel"
    :exportUrl="route('input.repair-produksi.export')"
    title="Export LHR Repair Produksi"
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
                    $pp = $r->partProsesRepairProduksi;
                    $part = $pp?->part;
                    $sphStd = $pp?->sph_standar_repair_produksi ?? 0;
                    $sphAct = $r->sph_aktual;
                    $pct = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal_repair_produksi)->format('d/m/Y') }}</td>
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
                    <td style="text-align:right;font-weight:600;color:{{ $pct === null ? 'var(--text-4)' : ($pct >= 100 ? 'var(--c-success)' : 'var(--c-danger)') }}">
                        {{ $pct !== null ? $pct.'%' : '—' }}
                    </td>
                    @if(auth()->user()->hasRole('admin'))
                    <td>
                        <div class="action-btns" style="flex-wrap:nowrap">
                            <a href="{{ route('input.repair-produksi.edit', $r) }}?back_query={{ urlencode(http_build_query(request()->query())) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('input.repair-produksi.destroy', $r) }}"
                                  data-konfirmasi="Hapus data ini?">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                    @endif
                </tr>
            @empty
                <x-tabel-kosong :kolom="auth()->user()->hasRole('admin') ? 15 : 14" entitas="data LHR Produksi" periode />
            @endforelse
            </tbody>
        </table>
</div>

<div class="erp-pg">{{ $repairs->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($repairs->total()) }}</b> baris LHR Produksi</span>
    @if($filterActive)<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>

<script>
@if (session('batch_tersimpan'))
(function() {
    try {
        localStorage.removeItem('optima_draft_lhr_repair_produksi_u{{ auth()->id() }}');
        localStorage.removeItem('optima_draft_lhr_repair_produksi_terkirim_u{{ auth()->id() }}');
    } catch (e) {
        // localStorage tak tersedia; tidak ada yang perlu dibuang.
    }
})();
@endif
</script>

</div>
@endsection

