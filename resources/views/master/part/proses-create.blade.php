@extends('layouts.app')
@section('title', 'Tambah Proses — ' . $part->part_no)
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-plus-circle"></i>Tambah Proses — {{ $part->part_no }}</h1>
    <a href="{{ route('master.part.edit', $part) }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:600px">
    <div class="erp-panel-header">Form Tambah Proses — {{ $part->part_no }} · {{ $part->part_name }}</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.part.proses.store', $part) }}">
            @csrf
            <div class="row g-3 mb-3">
                <div class="col-4">
                    <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                    <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                           placeholder="1" value="{{ old('id_proses') }}">
                    @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-8">
                    <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                    <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                           placeholder="PROGRESSIVE / BENDING / dll" value="{{ old('nama_proses') }}">
                    @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-2" style="font-size:13px;font-weight:700;color:var(--text-3);letter-spacing:.06em;text-transform:uppercase">
                <i class="bi bi-speedometer2 me-1"></i>Standar SPH
            </div>
            <div class="row g-3 mb-3">
                <div class="col-6">
                    <label class="form-label">SPH Produksi <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" step="1" name="sph_standar_produksi"
                               class="form-control @error('sph_standar_produksi') is-invalid @enderror"
                               value="{{ old('sph_standar_produksi', 0) }}" min="0">
                        <span class="input-group-text">pcs/jam</span>
                    </div>
                    @error('sph_standar_produksi')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <label class="form-label">SPH QC <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" step="1" name="sph_standar_qc"
                               class="form-control @error('sph_standar_qc') is-invalid @enderror"
                               value="{{ old('sph_standar_qc', 0) }}" min="0">
                        <span class="input-group-text">pcs/jam</span>
                    </div>
                    @error('sph_standar_qc')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-2" style="font-size:13px;font-weight:700;color:var(--text-3);letter-spacing:.06em;text-transform:uppercase">
                <i class="bi bi-clock me-1"></i>Standar Waktu Dandori
            </div>
            <div class="row g-3 mb-4">
                <div class="col-6">
                    <label class="form-label">Std Trial QC <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="std_trial_qc_menit"
                               class="form-control @error('std_trial_qc_menit') is-invalid @enderror"
                               value="{{ old('std_trial_qc_menit', 0) }}" min="0">
                        <span class="input-group-text">mnt</span>
                    </div>
                    @error('std_trial_qc_menit')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
                <div class="col-6">
                    <label class="form-label">Std Setting Dies <span class="text-danger">*</span></label>
                    <div class="input-group">
                        <input type="number" name="std_setting_dies_menit"
                               class="form-control @error('std_setting_dies_menit') is-invalid @enderror"
                               value="{{ old('std_setting_dies_menit', 0) }}" min="0">
                        <span class="input-group-text">mnt</span>
                    </div>
                    @error('std_setting_dies_menit')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-success btn-sm">
                    <i class="bi bi-plus-lg me-1"></i>Tambah Proses
                </button>
                <a href="{{ route('master.part.edit', $part) }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
