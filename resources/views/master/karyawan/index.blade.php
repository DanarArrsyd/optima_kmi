@extends('layouts.app')
@section('title', 'Master Karyawan')
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
     style="position:fixed;top:0;right:0;height:100vh;width:440px;max-width:100vw;background:#fff;z-index:1050;display:flex;flex-direction:column;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

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
    <div style="flex:1;overflow-y:auto;padding:20px">

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

<div class="erp-panel">
    <div class="erp-panel-header">Daftar Karyawan</div>
    <table class="erp-table">
        <thead>
            <tr>
                <th width="50">No</th>
                <th>NIK</th>
                <th>Nama Karyawan</th>
                <th>Departemen</th>
                <th style="width:130px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($karyawan as $i => $k)
            <tr>
                <td>{{ $karyawan->firstItem() + $i }}</td>
                <td>{{ $k->nik }}</td>
                <td>{{ $k->nama_karyawan }}</td>
                <td>{{ $k->departemen }}</td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('master.karyawan.edit', $k) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.karyawan.destroy', $k) }}"
                              onsubmit="return confirm('Hapus karyawan ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;color:#888;padding:20px">Belum ada data karyawan.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
<div class="mt-3">{{ $karyawan->links() }}</div>

</div>{{-- /alpine outer scope --}}
@endsection
