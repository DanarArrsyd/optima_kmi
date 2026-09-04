@extends('layouts.app')
@section('title', 'Edit Jenis NG')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-exclamation-octagon"></i>Edit Jenis NG</h1>
    <a href="{{ route('master.jenis-ng.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Edit Jenis NG — {{ $jenisNg->kode }}</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.jenis-ng.update', $jenisNg) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Kode <span class="text-danger">*</span></label>
                <input type="text" name="kode" class="form-control @error('kode') is-invalid @enderror"
                       value="{{ old('kode', $jenisNg->kode) }}" style="text-transform:uppercase" maxlength="20">
                @error('kode')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama NG <span class="text-danger">*</span></label>
                <input type="text" name="nama_ng" class="form-control @error('nama_ng') is-invalid @enderror"
                       value="{{ old('nama_ng', $jenisNg->nama_ng) }}" maxlength="100">
                @error('nama_ng')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Deskripsi</label>
                <input type="text" name="deskripsi" class="form-control @error('deskripsi') is-invalid @enderror"
                       value="{{ old('deskripsi', $jenisNg->deskripsi) }}" maxlength="255">
                @error('deskripsi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Update</button>
                <a href="{{ route('master.jenis-ng.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
