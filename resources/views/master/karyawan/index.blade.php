@extends('layouts.app')
@section('title', 'Master Karyawan')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.karyawan.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterDept !== '' || $filter !== 'semua')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    {{-- Ekspor membawa filter yang sedang aktif — isi file = isi tabel. --}}
    <a href="{{ route('master.karyawan.export', request()->query()) }}" class="erp-tb-btn">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </a>
    <a href="{{ route('master.karyawan.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('master.karyawan.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks :value="$search" placeholder="NIK atau Nama Karyawan" />
        <x-filter.cari name="filter_dept" placeholder="Departemen" :value="$filterDept"
                       :items="collect($deptList)->map(fn ($o) => ['v' => $o, 't' => $o])" />
        <x-filter.cari name="filter" placeholder="Status" :value="$filter === 'semua' ? null : $filter"
                       :items="[['v' => 'aktif', 't' => 'Aktif'], ['v' => 'nonaktif', 't' => 'Nonaktif']]" />
    </x-filter.grup>
</x-filter.dock>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">



<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Karyawan dari Excel</span>
        <a href="{{ route('master.karyawan.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.karyawan.import') }}" enctype="multipart/form-data">
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
                Kolom wajib: <strong>NIK</strong>, <strong>Nama Karyawan</strong>. Opsional: Departemen.
                Data existing akan di-update berdasarkan NIK.
            </div>
        </form>
    </div>
</div>

{{-- Status Aktif/Non-Aktif/Semua disaring lewat dropdown "Semua Status" di
     dock Filter, bukan tombol sendiri di sini — satu kontrol per fungsi,
     nggak dobel sama select yang sudah ada. --}}
<div style="font-size:0.78rem;color:#6b7280;margin-bottom:10px">
    {{ $karyawan->total() }} karyawan
    @if($filter==='aktif') aktif
    @elseif($filter==='nonaktif') non-aktif
    @else (semua)
    @endif
</div>

{{-- Kepala panel dibuang: judul sudah di topbar, jumlah sudah di status bar.
     Catatan "non-aktif tidak muncul di form input" dipertahankan sebagai
     strip tipis — itu keterangan perilaku, bukan sekadar hiasan judul. --}}
@if($filter === 'nonaktif')
<div class="erp-warnstrip">
    <i class="bi bi-info-circle"></i>
    <span>Karyawan non-aktif tidak muncul di form input LHP.</span>
</div>
@endif
    <table class="erp-table" style="min-width:700px;width:100%">
        <thead style="position:sticky;top:0;z-index:10">
            <tr>
                <th style="width:46px;white-space:nowrap">No</th>
                <th style="width:90px;white-space:nowrap">NIK</th>
                <th>Nama Karyawan</th>
                <th style="width:140px;white-space:nowrap">Departemen</th>
                <th style="width:90px;text-align:center;white-space:nowrap">Status</th>
                @if($adaKolomTanggal)
                <th style="width:120px;white-space:nowrap">Nonaktif Sejak</th>
                @endif
                <th style="width:240px;white-space:nowrap">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($karyawan as $i => $k)
            <tr style="{{ !$k->is_aktif ? 'opacity:0.55;background:#f9fafb' : '' }}">
                <td style="white-space:nowrap">{{ $karyawan->firstItem() + $i }}</td>
                <td style="font-family:monospace;white-space:nowrap">{{ $k->nik }}</td>
                {{-- Badge "NON-AKTIF" di sini dulu dobel sama kolom Status di sebelah —
                     satu penanda cukup, dua bikin baris berisik. --}}
                <td>{{ $k->nama_karyawan }}</td>
                <td style="white-space:nowrap">{{ $k->departemen }}</td>
                <td style="text-align:center;white-space:nowrap">
                    @if($k->is_aktif)
                        <span style="font-size:0.72rem;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:3px;font-weight:600">Aktif</span>
                    @else
                        <span style="font-size:0.72rem;background:#e5e7eb;color:#4b5563;padding:2px 8px;border-radius:3px;font-weight:600">Non-Aktif</span>
                    @endif
                </td>
                @if($adaKolomTanggal)
                <td style="white-space:nowrap">
                    {{ $k->tanggal_nonaktif ? $k->tanggal_nonaktif->format('d/m/Y') : '—' }}
                </td>
                @endif
                <td style="white-space:nowrap">
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.karyawan.edit', $k) }}" class="btn btn-warning btn-sm">Edit</a>

                        {{-- Toggle Aktif/Non-Aktif --}}
                        <form method="POST" action="{{ route('master.karyawan.toggle-aktif', $k) }}"
                              x-data="{ buka: false }" style="display:inline-block">
                            @csrf @method('PATCH')
                            @if($k->is_aktif)
                                <button type="button" class="btn btn-outline-secondary btn-sm"
                                        x-on:click="buka = ! buka">
                                    <i class="bi bi-person-slash me-1"></i>Non-Aktifkan
                                </button>
                                <span x-show="buka" x-cloak style="display:inline-flex;gap:4px;align-items:center;margin-left:4px">
                                    {{-- App jalan UTC, pabrik WIB — antara 00:00-07:00 WIB, date('Y-m-d')
                                         masih menyebut tanggal kemarin. Pakai jam WIB biar prefill benar. --}}
                                    <input type="date" name="tanggal_nonaktif" class="erp-in"
                                           value="{{ now('Asia/Jakarta')->toDateString() }}" max="{{ now('Asia/Jakarta')->toDateString() }}"
                                           style="width:140px">
                                    <button type="submit" class="btn btn-sm btn-danger">Simpan</button>
                                    <button type="button" class="btn btn-sm btn-outline-secondary"
                                            x-on:click="buka = false">Batal</button>
                                </span>
                            @else
                                <button type="submit" class="btn btn-sm btn-success"
                                        onclick="return confirm('Aktifkan kembali karyawan {{ addslashes($k->nama_karyawan) }}? Tanggal nonaktifnya akan dikosongkan.')">
                                    <i class="bi bi-person-check me-1"></i>Aktifkan
                                </button>
                            @endif
                        </form>

                        <form method="POST" action="{{ route('master.karyawan.destroy', $k) }}"
                              onsubmit="return confirm('Hapus permanen karyawan ini? Data historis LHP yang terkait tidak akan terhapus.')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="{{ $adaKolomTanggal ? 7 : 6 }}" style="text-align:center;color:#888;padding:20px">
                    @if($filter === 'nonaktif') Tidak ada karyawan non-aktif.
                    @elseif($filter === 'aktif') Belum ada karyawan aktif.
                    @else Belum ada data karyawan.
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $karyawan->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($karyawan->total()) }}</b> karyawan</span>
    @if($search !== '' || $filterDept !== '' || $filter !== 'semua')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine outer scope --}}
@endsection
