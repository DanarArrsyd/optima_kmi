@extends('layouts.app')
@section('title', 'Tambah Part')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-box-seam"></i>Tambah Part</h1>
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Tambah Part</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.part.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Customer</label>
                <input type="text" name="customer" class="form-control @error('customer') is-invalid @enderror"
                       value="{{ old('customer') }}" placeholder="Contoh: Toyota, Honda, Yamaha">
                @error('customer')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Part No <span class="text-danger">*</span></label>
                <input type="text" name="part_no" class="form-control @error('part_no') is-invalid @enderror"
                       value="{{ old('part_no') }}" placeholder="Contoh: BT-091A-3">
                @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Part Name <span class="text-danger">*</span></label>
                <input type="text" name="part_name" class="form-control @error('part_name') is-invalid @enderror"
                       value="{{ old('part_name') }}" placeholder="Nama part">
                @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan & Lanjut ke Proses
                </button>
                <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
