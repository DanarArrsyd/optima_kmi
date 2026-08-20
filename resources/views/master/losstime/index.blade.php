@extends('layouts.app')
@section('title', 'Master Loss Time')
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
            <i class="bi bi-exclamation-triangle me-2"></i>Tambah Loss Time
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

        <form method="POST" action="{{ route('master.losstime.store') }}" novalidate>
            @csrf
            <div class="row g-3">

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Kode <span class="text-danger">*</span></label>
                    <input type="text" name="kode"
                           class="form-control form-control-sm @error('kode') is-invalid @enderror"
                           value="{{ old('kode') }}" placeholder="Contoh: BRD">
                    @error('kode')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Jenis Loss Time <span class="text-danger">*</span></label>
                    <input type="text" name="jenis_losstime"
                           class="form-control form-control-sm @error('jenis_losstime') is-invalid @enderror"
                           value="{{ old('jenis_losstime') }}" placeholder="Nama jenis loss time">
                    @error('jenis_losstime')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">
                        Std Menit Default <span class="text-muted" style="font-weight:400">(opsional)</span>
                    </label>
                    <input type="number" name="std_menit_default"
                           class="form-control form-control-sm @error('std_menit_default') is-invalid @enderror"
                           value="{{ old('std_menit_default') }}" min="0" step="0.01" placeholder="Menit">
                    @error('std_menit_default')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div style="font-size:0.72rem;color:#6c757d;margin-top:4px">Kosongkan = full loss</div>
                </div>

                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">
                        Std Menit Progressive <span class="text-muted" style="font-weight:400">(opsional)</span>
                    </label>
                    <input type="number" name="std_menit_progressive"
                           class="form-control form-control-sm @error('std_menit_progressive') is-invalid @enderror"
                           value="{{ old('std_menit_progressive') }}" min="0" step="0.01" placeholder="Menit">
                    @error('std_menit_progressive')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
    <h1 class="page-title"><i class="bi bi-exclamation-triangle"></i>Master Loss Time</h1>
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

<div class="erp-panel">
    <div class="erp-panel-header">Daftar Loss Time</div>
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 300px);min-height:200px">
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
                        <span style="color:#9ca3af">Full loss</span>
                    @endif
                </td>
                <td style="white-space:nowrap;text-align:center">
                    @if($lt->std_menit_progressive)
                        <span style="font-variant-numeric:tabular-nums">{{ $lt->std_menit_progressive }} mnt</span>
                    @else
                        <span style="color:#9ca3af">—</span>
                    @endif
                </td>
                <td style="white-space:nowrap">
                    <div class="action-btns" style="flex-wrap:nowrap">
                        <a href="{{ route('master.losstime.edit', $lt) }}" class="btn btn-warning btn-sm">Edit</a>
                        <form method="POST" action="{{ route('master.losstime.destroy', $lt) }}"
                              onsubmit="return confirm('Hapus loss time ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;color:#888;padding:20px">Belum ada data loss time.</td></tr>
        @endforelse
        </tbody>
    </table>
    </div>{{-- /overflow wrapper --}}
</div>
<div class="mt-3">{{ $losstimes->links() }}</div>

</div>{{-- /alpine outer scope --}}
@endsection
