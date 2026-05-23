@extends('layouts.app')
@section('title', 'Tambah Part')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah Part</h4>
</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.part.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Part No <span class="text-danger">*</span></label>
                <input type="text" name="part_no" class="form-control @error('part_no') is-invalid @enderror"
                       value="{{ old('part_no') }}" placeholder="BT-091A-3">
                @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Part Name <span class="text-danger">*</span></label>
                <input type="text" name="part_name" class="form-control @error('part_name') is-invalid @enderror"
                       value="{{ old('part_name') }}">
                @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan & Lanjut ke Proses</button>
        </form>
    </div>
</div>
@endsection
