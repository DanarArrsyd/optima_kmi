@extends('layouts.app')
@section('title', 'Tambah Loss Time')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-exclamation-triangle"></i>Tambah Loss Time</h1>
    <a href="{{ route('master.losstime.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Tambah Loss Time</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.losstime.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Kode <span class="text-danger">*</span></label>
                <input type="text" name="kode" class="form-control @error('kode') is-invalid @enderror"
                       value="{{ old('kode') }}" placeholder="Contoh: LT-01">
                @error('kode')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Jenis Loss Time <span class="text-danger">*</span></label>
                <input type="text" name="jenis_losstime" class="form-control @error('jenis_losstime') is-invalid @enderror"
                       value="{{ old('jenis_losstime') }}" placeholder="Contoh: Dies Change">
                @error('jenis_losstime')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Std Menit Default</label>
                <input type="number" name="std_menit_default" class="form-control @error('std_menit_default') is-invalid @enderror"
                       value="{{ old('std_menit_default') }}" min="0">
                <small class="text-muted">Kosongkan = full loss (semua menit dihitung over)</small>
                @error('std_menit_default')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Std Menit Progressive</label>
                <input type="number" name="std_menit_progressive" class="form-control @error('std_menit_progressive') is-invalid @enderror"
                       value="{{ old('std_menit_progressive') }}" min="0">
                <small class="text-muted">Kosongkan = full loss (untuk mesin tipe PROGRESSIVE)</small>
                @error('std_menit_progressive')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.losstime.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
