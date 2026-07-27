@extends('layouts.app')
@section('title', 'Edit User')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-person-gear"></i>Edit User</h1>
    <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:500px">
    <div class="erp-panel-header">Form Edit User — {{ $user->name }}</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.users.update', $user) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Nama <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name', $user->name) }}">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email', $user->email) }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Password Baru</label>
                <input type="password" name="password" class="form-control @error('password') is-invalid @enderror"
                       placeholder="Kosongkan jika tidak ingin mengubah">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Role <span class="text-danger">*</span></label>
                <select name="role" class="form-select @error('role') is-invalid @enderror">
                    <option value="admin"          {{ old('role', $user->role) === 'admin'          ? 'selected' : '' }}>Admin</option>
                    <option value="staff_produksi"    {{ old('role', $user->role) === 'staff_produksi'    ? 'selected' : '' }}>Staff Produksi</option>
                    <option value="staff_qc"          {{ old('role', $user->role) === 'staff_qc'          ? 'selected' : '' }}>Staff QC</option>
                    <option value="staff_maintenance" {{ old('role', $user->role) === 'staff_maintenance' ? 'selected' : '' }}>Staff Maintenance</option>
                    <option value="manager"           {{ old('role', $user->role) === 'manager'           ? 'selected' : '' }}>Manager</option>
                    <option value="guest"             {{ old('role', $user->role) === 'guest'             ? 'selected' : '' }}>Guest (Read-Only)</option>
                </select>
                @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Tautkan ke Karyawan (opsional)</label>
                <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                    <option value="">-- Tidak ditautkan --</option>
                    @foreach($karyawan as $k)
                        <option value="{{ $k->id }}"
                            {{ old('id_karyawan', $user->id_karyawan) == $k->id ? 'selected' : '' }}>
                            {{ $k->nik }} — {{ $k->nama_karyawan }}
                        </option>
                    @endforeach
                </select>
                @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Update</button>
                <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
