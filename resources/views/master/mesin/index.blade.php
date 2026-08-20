@extends('layouts.app')
@section('title', 'Master Mesin')
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
            <i class="bi bi-cpu me-2"></i>Tambah Mesin
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

        <form method="POST" action="{{ route('master.mesin.store') }}" novalidate>
            @csrf
            <div class="row g-3">

                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">ID Mesin <span class="text-danger">*</span></label>
                    <input type="text" name="id_mesin"
                           class="form-control form-control-sm @error('id_mesin') is-invalid @enderror"
                           value="{{ old('id_mesin') }}" placeholder="Contoh: M-001">
                    @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-6">
                    <label class="form-label" style="font-size:0.8rem">Line <span class="text-danger">*</span></label>
                    <input type="text" name="line"
                           class="form-control form-control-sm @error('line') is-invalid @enderror"
                           value="{{ old('line') }}" placeholder="Contoh: A">
                    @error('line')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Nama Mesin <span class="text-danger">*</span></label>
                    <input type="text" name="nama_mesin"
                           class="form-control form-control-sm @error('nama_mesin') is-invalid @enderror"
                           value="{{ old('nama_mesin') }}" placeholder="Nama mesin">
                    @error('nama_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Tipe Mesin <span class="text-danger">*</span></label>
                    <select name="tipe_mesin" class="form-select form-select-sm @error('tipe_mesin') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
                        @foreach(\App\Models\MasterMesin::TIPE_MESIN as $tipe)
                            <option value="{{ $tipe }}" {{ old('tipe_mesin') === $tipe ? 'selected' : '' }}>{{ $tipe }}</option>
                        @endforeach
                    </select>
                    @error('tipe_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Kapasitas Max Stroke <span class="text-muted" style="font-weight:400">(opsional)</span></label>
                    <input type="number" name="kapasitas_max_stroke"
                           class="form-control form-control-sm @error('kapasitas_max_stroke') is-invalid @enderror"
                           value="{{ old('kapasitas_max_stroke') }}" min="0" placeholder="stroke/jam">
                    @error('kapasitas_max_stroke')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Tanggal Instalasi <span class="text-muted" style="font-weight:400">(opsional — basis MTBF & Availability)</span></label>
                    <input type="date" name="tanggal_instalasi"
                           class="form-control form-control-sm @error('tanggal_instalasi') is-invalid @enderror"
                           value="{{ old('tanggal_instalasi') }}">
                    @error('tanggal_instalasi')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
    <h1 class="page-title"><i class="bi bi-cpu"></i>Master Mesin</h1>
    <div class="action-btns">
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Tambah
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="document.getElementById('import-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
    </div>
</div>


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

<div class="erp-panel">
    <div class="erp-panel-header">Daftar Mesin</div>
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 300px);min-height:200px">
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
                              onsubmit="return confirm('Hapus mesin ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Belum ada data mesin.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>{{-- /overflow wrapper --}}
</div>
<div class="mt-3">{{ $mesin->links() }}</div>

</div>{{-- /alpine outer scope --}}
@endsection
