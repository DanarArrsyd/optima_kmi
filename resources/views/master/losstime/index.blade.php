@extends('layouts.app')
@section('title', 'Master Loss Time')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.losstime.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    {{-- Ekspor membawa filter yang sedang aktif — isi file = isi tabel. --}}
    <a href="{{ route('master.losstime.export', request()->query()) }}" class="erp-tb-btn">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </a>
    <a href="{{ route('master.losstime.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('master.losstime.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks :value="$search" placeholder="Kode atau Jenis Loss Time" />
    </x-filter.grup>
</x-filter.dock>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">



<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Loss Time dari Excel</span>
        <a href="{{ route('master.losstime.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.losstime.import') }}" enctype="multipart/form-data">
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
                Kolom wajib: <strong>Kode</strong>, <strong>Jenis Losstime</strong>. Opsional: Std Menit Default, Std Menit Progressive.
                Data existing akan di-update berdasarkan Kode.
            </div>
        </form>
    </div>
</div>

    <table class="erp-table" style="min-width:620px;width:100%">
        <thead style="position:sticky;top:0;z-index:10">
            <tr>
                <th style="width:100px;white-space:nowrap">Kode</th>
                <th>Jenis Loss Time</th>
                <th style="width:160px;white-space:nowrap;text-align:center">Std Menit Default</th>
                <th style="width:180px;white-space:nowrap;text-align:center">Std Menit Progressive</th>
                <th style="width:140px;white-space:nowrap">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($losstimes as $lt)
            <tr>
                <td style="white-space:nowrap;font-family:monospace;font-weight:600">{{ $lt->kode }}</td>
                <td>{{ $lt->jenis_losstime }}</td>
                <td style="white-space:nowrap;text-align:center">
                    @if($lt->std_menit_default)
                        <span style="font-variant-numeric:tabular-nums">{{ $lt->std_menit_default }} mnt</span>
                    @else
                        <span style="color:var(--text-4)">Full loss</span>
                    @endif
                </td>
                <td style="white-space:nowrap;text-align:center">
                    @if($lt->std_menit_progressive)
                        <span style="font-variant-numeric:tabular-nums">{{ $lt->std_menit_progressive }} mnt</span>
                    @else
                        <span style="color:var(--text-4)">—</span>
                    @endif
                </td>
                <td style="white-space:nowrap">
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.losstime.edit', $lt) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.losstime.destroy', $lt) }}"
                              data-konfirmasi="Hapus loss time ini?">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <x-tabel-kosong kolom="5" entitas="data loss time" :tambah="route('master.losstime.create')" />
        @endforelse
        </tbody>
    </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $losstimes->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($losstimes->total()) }}</b> jenis</span>
    @if($search !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine outer scope --}}
@endsection
