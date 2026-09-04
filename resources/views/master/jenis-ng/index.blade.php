@extends('layouts.app')
@section('title', 'Master Jenis NG')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

{{-- Tombol Filter di bawah butuh filterOpen — sebelumnya nol ada x-data
     pembungkus sama sekali, jadi filterOpen undefined dan dock filter
     nol pernah kebuka; klik tombolnya nol berefek apa pun. --}}
<div x-data="{ filterOpen: false }">

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.jenis-ng.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterKategori !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <a href="{{ route('master.jenis-ng.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('master.jenis-ng.index') }}" class="erp-dock-body">
        <div class="erp-grp">
            <b>Find</b>
            <input type="text" name="q" value="{{ $search }}" class="erp-in @if($search !== '') is-set @endif"
                   placeholder="Kode atau Nama NG">
            <select name="filter_kategori" class="erp-in @if($filterKategori !== '') is-set @endif">
                <option value="">Semua Kategori</option>
                @foreach($kategoriList as $o)
                <option value="{{ $o }}" @selected($filterKategori === $o)>{{ $o }}</option>
                @endforeach
            </select>
        </div>

        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('master.jenis-ng.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">



{{-- Import Panel --}}
<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Jenis NG dari Excel</span>
        <a href="{{ route('master.jenis-ng.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.jenis-ng.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label mb-1" style="font-size:0.8rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control form-control-sm" accept=".xlsx,.xls,.csv">
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
            <div class="mt-2" style="font-size:0.75rem;color:#6c757d">
                Kolom wajib: <strong>Kode</strong>, <strong>Nama NG</strong>. Opsional: Deskripsi.
            </div>
        </form>
    </div>
</div>

{{-- Table --}}
    <table class="erp-table">
        <thead>
            <tr>
                <th style="width:100px">Kode</th>
                <th>Nama NG</th>
                <th>Deskripsi</th>
                <th style="width:200px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($jenisNg as $ng)
            <tr>
                <td style="font-weight:600;letter-spacing:0.3px">{{ $ng->kode }}</td>
                <td>{{ $ng->nama_ng }}</td>
                <td style="color:#6b7280">{{ $ng->deskripsi ?? '—' }}</td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('master.jenis-ng.edit', $ng) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.jenis-ng.destroy', $ng) }}"
                              onsubmit="return confirm('Hapus jenis NG ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" style="text-align:center;color:#888;padding:20px">Belum ada data jenis NG. Tambahkan di atas.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $jenisNg->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($jenisNg->total()) }}</b> jenis NG</span>
    @if($search !== '' || $filterKategori !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine outer scope --}}
@endsection
