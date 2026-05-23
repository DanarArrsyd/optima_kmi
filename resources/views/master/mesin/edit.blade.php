@extends('layouts.app')
@section('title', 'Edit Mesin')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.mesin.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Edit Mesin</h4>
</div>
<div class="card shadow-sm" style="max-width:520px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.mesin.update', $mesin) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">ID Mesin <span class="text-danger">*</span></label>
                <input type="text" name="id_mesin" class="form-control @error('id_mesin') is-invalid @enderror"
                       value="{{ old('id_mesin', $mesin->id_mesin) }}">
                @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Mesin <span class="text-danger">*</span></label>
                <input type="text" name="nama_mesin" class="form-control @error('nama_mesin') is-invalid @enderror"
                       value="{{ old('nama_mesin', $mesin->nama_mesin) }}">
                @error('nama_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Line <span class="text-danger">*</span></label>
                <input type="text" name="line" class="form-control @error('line') is-invalid @enderror"
                       value="{{ old('line', $mesin->line) }}">
                @error('line')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Tipe Mesin <span class="text-danger">*</span></label>
                <select name="tipe_mesin" class="form-select @error('tipe_mesin') is-invalid @enderror">
                    <option value="PROGRESSIVE" {{ old('tipe_mesin', $mesin->tipe_mesin) === 'PROGRESSIVE' ? 'selected' : '' }}>PROGRESSIVE</option>
                    <option value="STAMPING" {{ old('tipe_mesin', $mesin->tipe_mesin) === 'STAMPING' ? 'selected' : '' }}>STAMPING</option>
                </select>
                @error('tipe_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Kapasitas Max Stroke (per jam)</label>
                <input type="number" name="kapasitas_max_stroke" class="form-control @error('kapasitas_max_stroke') is-invalid @enderror"
                       value="{{ old('kapasitas_max_stroke', $mesin->kapasitas_max_stroke) }}" min="0">
                @error('kapasitas_max_stroke')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Update</button>
        </form>
    </div>
</div>
@endsection
