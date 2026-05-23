@extends('layouts.app')
@section('title', 'Tambah Shift')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.shift.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah Shift</h4>
</div>
<div class="card shadow-sm" style="max-width:480px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.shift.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Nama Shift <span class="text-danger">*</span></label>
                <input type="text" name="nama_shift" class="form-control @error('nama_shift') is-invalid @enderror"
                       value="{{ old('nama_shift') }}" placeholder="Shift Pagi">
                @error('nama_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                <input type="time" name="jam_start" class="form-control @error('jam_start') is-invalid @enderror"
                       value="{{ old('jam_start') }}">
                @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                <input type="time" name="jam_finish" class="form-control @error('jam_finish') is-invalid @enderror"
                       value="{{ old('jam_finish') }}">
                @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>
@endsection
