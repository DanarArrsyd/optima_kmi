@extends('layouts.app')
@section('title', 'Tambah User')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah User</h4>
</div>
<div class="card shadow-sm" style="max-width:520px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.users.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Nama <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control @error('password') is-invalid @enderror">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-select @error('role') is-invalid @enderror">
                    <option value="">-- Pilih Role --</option>
                    <option value="admin" {{ old('role') === 'admin' ? 'selected' : '' }}>Admin</option>
                    <option value="staff_produksi" {{ old('role') === 'staff_produksi' ? 'selected' : '' }}>Staff Produksi</option>
                    <option value="staff_qc" {{ old('role') === 'staff_qc' ? 'selected' : '' }}>Staff QC</option>
                    <option value="manager" {{ old('role') === 'manager' ? 'selected' : '' }}>Manager</option>
                </select>
                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Karyawan (opsional)</label>
                <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                    <option value="">-- Tidak ditautkan --</option>
                    @foreach($karyawan as $k)
                        <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                            {{ $k->nik }} — {{ $k->nama_karyawan }}
                        </option>
                    @endforeach
                </select>
                @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>
@endsection
