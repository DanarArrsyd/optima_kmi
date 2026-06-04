@extends('layouts.app')
@section('title', 'Tambah Mesin')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-cpu"></i>Tambah Mesin</h1>
    <a href="{{ route('master.mesin.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:500px">
    <div class="erp-panel-header">Form Tambah Mesin</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.mesin.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">ID Mesin <span class="text-danger">*</span></label>
                <input type="text" name="id_mesin" class="form-control @error('id_mesin') is-invalid @enderror"
                       value="{{ old('id_mesin') }}" placeholder="Contoh: M-001">
                @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Mesin <span class="text-danger">*</span></label>
                <input type="text" name="nama_mesin" class="form-control @error('nama_mesin') is-invalid @enderror"
                       value="{{ old('nama_mesin') }}">
                @error('nama_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Line <span class="text-danger">*</span></label>
                <input type="text" name="line" class="form-control @error('line') is-invalid @enderror"
                       value="{{ old('line') }}" placeholder="Contoh: L1">
                @error('line')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Tipe Mesin <span class="text-danger">*</span></label>
                <select name="tipe_mesin" class="form-select @error('tipe_mesin') is-invalid @enderror">
                    <option value="">-- Pilih Tipe --</option>
                    <option value="PROGRESSIVE" {{ old('tipe_mesin') === 'PROGRESSIVE' ? 'selected' : '' }}>PROGRESSIVE</option>
                    <option value="STAMPING" {{ old('tipe_mesin') === 'STAMPING' ? 'selected' : '' }}>STAMPING</option>
                </select>
                @error('tipe_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Kapasitas Max Stroke (per jam)</label>
                <input type="number" name="kapasitas_max_stroke" class="form-control @error('kapasitas_max_stroke') is-invalid @enderror"
                       value="{{ old('kapasitas_max_stroke') }}" min="0">
                @error('kapasitas_max_stroke')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.mesin.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
