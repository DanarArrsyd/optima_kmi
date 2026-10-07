@extends('layouts.app')
@section('title', 'Master Proses Repair')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@section('content')
<div x-data="{ filterOpen: false }">
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.proses-repair.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <a href="{{ route('master.proses-repair.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('master.proses-repair.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks :value="$search" placeholder="Part atau Nama Proses" />
    </x-filter.grup>
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll">
    <table class="erp-table" style="min-width:720px;width:100%">
        <thead style="position:sticky;top:0;z-index:10">
            <tr>
                <th style="width:130px">Customer</th>
                <th style="width:130px">Part No.</th>
                <th>Part Name</th>
                <th style="width:100px">ID Proses</th>
                <th>Nama Proses</th>
                <th style="width:120px;text-align:right">SPH Standar</th>
                <th style="width:140px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($prosesRepair as $pr)
            <tr>
                <td>{{ $pr->part?->customer ?? '—' }}</td>
                <td>{{ $pr->part?->part_no ?? '—' }}</td>
                <td>{{ $pr->part?->part_name ?? '—' }}</td>
                <td style="font-family:monospace">{{ $pr->id_proses }}</td>
                <td>{{ $pr->nama_proses }}</td>
                <td style="text-align:right">{{ number_format($pr->sph_standar_repair) }}</td>
                <td>
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.proses-repair.edit', $pr) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.proses-repair.destroy', $pr) }}"
                              onsubmit="return confirm('Hapus proses repair ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" style="text-align:center;color:#888;padding:20px">Belum ada proses repair.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="erp-pg">{{ $prosesRepair->links() }}</div>

</div>
</div>

<div class="erp-status">
    <span><b>{{ number_format($prosesRepair->total()) }}</b> proses repair</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>
</div>
@endsection
