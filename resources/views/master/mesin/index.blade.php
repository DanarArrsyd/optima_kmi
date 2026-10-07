@extends('layouts.app')
@section('title', 'Master Mesin')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.mesin.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterLine !== '' || $filterTipe !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    {{-- Ekspor membawa filter yang sedang aktif — isi file = isi tabel. --}}
    <a href="{{ route('master.mesin.export', request()->query()) }}" class="erp-tb-btn">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </a>
    <a href="{{ route('master.mesin.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('master.mesin.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks :value="$search" placeholder="ID atau Nama Mesin" />
        <x-filter.cari name="filter_line" placeholder="Line" :value="$filterLine"
                       :items="collect($lineList)->map(fn ($o) => ['v' => $o, 't' => $o])" />
        <x-filter.cari name="filter_tipe" placeholder="Tipe Mesin" :value="$filterTipe"
                       :items="collect($tipeList)->map(fn ($o) => ['v' => $o, 't' => $o])" />
    </x-filter.grup>
</x-filter.dock>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">



<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Mesin dari Excel</span>
        <a href="{{ route('master.mesin.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.mesin.import') }}" enctype="multipart/form-data">
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
                Kolom wajib: <strong>ID Mesin</strong>, <strong>Nama Mesin</strong>. Opsional: Line, Tipe Mesin, Kapasitas Max Stroke.
                Data existing akan di-update berdasarkan ID Mesin.
            </div>
        </form>
    </div>
</div>

    <table class="erp-table" style="min-width:680px;width:100%">
        <thead style="position:sticky;top:0;z-index:10">
            <tr>
                <th style="width:100px;white-space:nowrap">ID Mesin</th>
                <th>Nama Mesin</th>
                <th style="width:80px;white-space:nowrap">Line</th>
                <th style="width:140px;white-space:nowrap">Tipe</th>
                <th style="width:190px;white-space:nowrap">Kapasitas Max (stroke/jam)</th>
                <th style="width:140px;white-space:nowrap">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($mesin as $m)
            <tr>
                <td style="white-space:nowrap;font-family:monospace;font-weight:600">{{ $m->id_mesin }}</td>
                <td>{{ $m->nama_mesin }}</td>
                <td style="white-space:nowrap">{{ $m->line }}</td>
                <td style="white-space:nowrap">{{ $m->tipe_mesin }}</td>
                <td style="white-space:nowrap;text-align:right">{{ $m->kapasitas_max_stroke ? number_format($m->kapasitas_max_stroke) : '—' }}</td>
                <td style="white-space:nowrap">
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.mesin.edit', $m) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.mesin.destroy', $m) }}"
                              data-konfirmasi="Hapus mesin ini?">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <x-tabel-kosong kolom="6" entitas="data mesin" :tambah="route('master.mesin.create')" />
        @endforelse
        </tbody>
    </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $mesin->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($mesin->total()) }}</b> mesin</span>
    @if($search !== '' || $filterLine !== '' || $filterTipe !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine outer scope --}}
@endsection
