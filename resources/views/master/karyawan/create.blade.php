@extends('layouts.app')
@section('title', 'Tambah Karyawan')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-people"></i>Tambah Karyawan</h1>
    <a href="{{ route('master.karyawan.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Tambah Karyawan</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.karyawan.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">NIK <span class="text-danger">*</span></label>
                <input type="text" name="nik" class="form-control @error('nik') is-invalid @enderror"
                       value="{{ old('nik') }}" placeholder="Nomor Induk Karyawan">
                @error('nik')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Karyawan <span class="text-danger">*</span></label>
                <input type="text" name="nama_karyawan" class="form-control @error('nama_karyawan') is-invalid @enderror"
                       value="{{ old('nama_karyawan') }}">
                @error('nama_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Departemen <span class="text-danger">*</span></label>
                <select name="departemen" class="form-select @error('departemen') is-invalid @enderror">
                    <option value="">-- Pilih Departemen --</option>
                    <option value="Produksi" {{ old('departemen') === 'Produksi' ? 'selected' : '' }}>Produksi</option>
                    <option value="QC" {{ old('departemen') === 'QC' ? 'selected' : '' }}>QC</option>
                </select>
                @error('departemen')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.karyawan.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
