@extends('layouts.app')
@section('title', 'Master Karyawan')
@section('suppress_layout_flash', 'yes')
@section('content')

<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }} }">

{{-- ── DRAWER BACKDROP ──────────────────────────────────────────── --}}
<div x-show="drawerOpen"
     x-cloak
     @click="drawerOpen = false"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040">
</div>

{{-- ── DRAWER PANEL ─────────────────────────────────────────────── --}}
<div x-show="drawerOpen"
     x-cloak
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="transform translate-x-full"
     x-transition:enter-end="transform translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-x-0"
     x-transition:leave-end="transform translate-x-full"
     style="position:fixed;top:0;right:0;height:100dvh;width:440px;max-width:100vw;z-index:1050;">
    <div style="display:flex;flex-direction:column;height:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-person-plus me-2"></i>Tambah Karyawan
        </div>
        <button type="button" @click="drawerOpen = false"
                style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;padding:0;line-height:1">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Body (scrollable) --}}
    <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:24px;overscroll-behavior:contain">

        @if($errors->any())
        <div class="alert alert-danger mb-3" style="font-size:0.82rem">
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ route('master.karyawan.store') }}" novalidate>
            @csrf
            <div class="row g-3">

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">NIK <span class="text-danger">*</span></label>
                    <input type="text" name="nik"
                           class="form-control form-control-sm @error('nik') is-invalid @enderror"
                           value="{{ old('nik') }}" placeholder="Nomor Induk Karyawan">
                    @error('nik')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Nama Karyawan <span class="text-danger">*</span></label>
                    <input type="text" name="nama_karyawan"
                           class="form-control form-control-sm @error('nama_karyawan') is-invalid @enderror"
                           value="{{ old('nama_karyawan') }}" placeholder="Nama lengkap">
                    @error('nama_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Departemen</label>
                    <select name="departemen" class="form-select form-select-sm @error('departemen') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
                        <option value="Produksi" {{ old('departemen') === 'Produksi' ? 'selected' : '' }}>Produksi</option>
                        <option value="QC" {{ old('departemen') === 'QC' ? 'selected' : '' }}>QC</option>
                        <option value="Maintenance" {{ old('departemen') === 'Maintenance' ? 'selected' : '' }}>Maintenance</option>
                    </select>
                    @error('departemen')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary btn-sm">
                    Batal
                </button>
            </div>
        </form>
    </div>{{-- /drawer body --}}
    </div>{{-- /inner flex container --}}
</div>{{-- /drawer panel --}}

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-people"></i>Master Karyawan</h1>
    <div class="action-btns">
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Tambah
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="document.getElementById('import-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

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

{{-- ── FILTER TABS ──────────────────────────────────────────────── --}}
<div style="display:flex;gap:6px;margin-bottom:12px;align-items:center;flex-wrap:wrap">
    <a href="{{ route('master.karyawan.index', ['filter'=>'aktif']) }}"
       class="btn btn-sm {{ $filter==='aktif' ? 'btn-success' : 'btn-outline-secondary' }}">
        <i class="bi bi-person-check me-1"></i>Aktif
    </a>
    <a href="{{ route('master.karyawan.index', ['filter'=>'nonaktif']) }}"
       class="btn btn-sm {{ $filter==='nonaktif' ? 'btn-secondary' : 'btn-outline-secondary' }}">
        <i class="bi bi-person-slash me-1"></i>Non-Aktif
    </a>
    <a href="{{ route('master.karyawan.index', ['filter'=>'semua']) }}"
       class="btn btn-sm {{ $filter==='semua' ? 'btn-dark' : 'btn-outline-secondary' }}">
        <i class="bi bi-people me-1"></i>Semua
    </a>
    <span style="font-size:0.78rem;color:#6b7280;margin-left:4px">
        {{ $karyawan->total() }} karyawan
        @if($filter==='aktif') aktif
        @elseif($filter==='nonaktif') non-aktif
        @else (semua)
        @endif
    </span>
</div>

<div class="erp-panel">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span>Daftar Karyawan</span>
        @if($filter === 'nonaktif')
        <span style="font-size:0.75rem;color:#6b7280;font-weight:400">
            <i class="bi bi-info-circle me-1"></i>Karyawan non-aktif tidak muncul di form input LHP
        </span>
        @endif
    </div>
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 300px);min-height:200px">
    <table class="erp-table" style="min-width:700px;width:100%">
        <thead style="position:sticky;top:0;z-index:10">
            <tr>
                <th style="width:46px;white-space:nowrap">No</th>
                <th style="width:90px;white-space:nowrap">NIK</th>
                <th>Nama Karyawan</th>
                <th style="width:140px;white-space:nowrap">Departemen</th>
                <th style="width:90px;text-align:center;white-space:nowrap">Status</th>
                <th style="width:240px;white-space:nowrap">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($karyawan as $i => $k)
            <tr style="{{ !$k->is_aktif ? 'opacity:0.55;background:#f9fafb' : '' }}">
                <td style="white-space:nowrap">{{ $karyawan->firstItem() + $i }}</td>
                <td style="font-family:monospace;white-space:nowrap">{{ $k->nik }}</td>
                <td>
                    {{ $k->nama_karyawan }}
                    @if(!$k->is_aktif)
                    <span style="font-size:0.68rem;background:#4b5563;color:#fff;padding:1px 6px;border-radius:3px;margin-left:4px;letter-spacing:.04em">NON-AKTIF</span>
                    @endif
                </td>
                <td style="white-space:nowrap">{{ $k->departemen }}</td>
                <td style="text-align:center;white-space:nowrap">
                    @if($k->is_aktif)
                        <span style="font-size:0.72rem;background:#d1fae5;color:#065f46;padding:2px 8px;border-radius:3px;font-weight:600">Aktif</span>
                    @else
                        <span style="font-size:0.72rem;background:#e5e7eb;color:#4b5563;padding:2px 8px;border-radius:3px;font-weight:600">Non-Aktif</span>
                    @endif
                </td>
                <td style="white-space:nowrap">
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.karyawan.edit', $k) }}" class="btn btn-warning btn-sm">Edit</a>

                        {{-- Toggle Aktif/Non-Aktif --}}
                        <form method="POST" action="{{ route('master.karyawan.toggle-aktif', $k) }}"
                              onsubmit="return confirm('{{ $k->is_aktif ? 'Non-aktifkan' : 'Aktifkan kembali' }} karyawan {{ addslashes($k->nama_karyawan) }}?')">
                            @csrf @method('PATCH')
                            @if($k->is_aktif)
                                <button type="submit" class="btn btn-sm"
                                        style="background:#4b5563;color:#fff;border:none">
                                    <i class="bi bi-person-slash me-1"></i>Non-Aktifkan
                                </button>
                            @else
                                <button type="submit" class="btn btn-sm btn-success">
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
                <td colspan="6" style="text-align:center;color:#888;padding:20px">
                    @if($filter === 'nonaktif') Tidak ada karyawan non-aktif.
                    @elseif($filter === 'aktif') Belum ada karyawan aktif.
                    @else Belum ada data karyawan.
                    @endif
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
    </div>{{-- /overflow wrapper --}}
</div>
<div class="mt-3">{{ $karyawan->links() }}</div>

</div>{{-- /alpine outer scope --}}
@endsection
