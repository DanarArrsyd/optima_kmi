@extends('layouts.app')
@section('title', 'Tambah Karyawan')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.karyawan.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah Karyawan</h4>
</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.karyawan.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">NIK <span class="text-danger">*</span></label>
                <input type="text" name="nik" class="form-control @error('nik') is-invalid @enderror"
                       value="{{ old('nik') }}">
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
                    <option value="">-- Pilih --</option>
                    <option value="Produksi" {{ old('departemen') === 'Produksi' ? 'selected' : '' }}>Produksi</option>
                    <option value="QC" {{ old('departemen') === 'QC' ? 'selected' : '' }}>QC</option>
                </select>
                @error('departemen')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>
@endsection
