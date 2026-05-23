@extends('layouts.app')
@section('title', 'Tambah Loss Time')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.losstime.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah Loss Time</h4>
</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.losstime.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Kode <span class="text-danger">*</span></label>
                <input type="text" name="kode" class="form-control @error('kode') is-invalid @enderror"
                       value="{{ old('kode') }}">
                @error('kode')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Jenis Loss Time <span class="text-danger">*</span></label>
                <input type="text" name="jenis_losstime" class="form-control @error('jenis_losstime') is-invalid @enderror"
                       value="{{ old('jenis_losstime') }}">
                @error('jenis_losstime')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Std Menit Default</label>
                <input type="number" name="std_menit_default" class="form-control @error('std_menit_default') is-invalid @enderror"
                       value="{{ old('std_menit_default') }}" min="0">
                <div class="form-text text-muted">Kosongkan jika loss ini dihitung penuh (full loss).</div>
                @error('std_menit_default')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Std Menit Progressive</label>
                <input type="number" name="std_menit_progressive" class="form-control @error('std_menit_progressive') is-invalid @enderror"
                       value="{{ old('std_menit_progressive') }}" min="0">
                <div class="form-text text-muted">Kosongkan jika loss ini dihitung penuh (full loss).</div>
                @error('std_menit_progressive')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>
@endsection
