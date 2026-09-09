@extends('layouts.app')
@section('title', 'Edit LHR')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit LHR</h1>
    <a href="{{ route('input.repair.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->updateRepair->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->updateRepair->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:640px">
    <div class="erp-panel-header">Form Edit LHR</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.repair.update', $repair) }}">
            @csrf @method('PUT')
            <input type="hidden" name="back_query" value="{{ request('back_query') }}">

            <div class="mb-3">
                <label class="form-label">Tanggal Repair <span class="text-danger">*</span></label>
                <input type="date" name="tanggal_repair" class="form-control @error('tanggal_repair', 'updateRepair') is-invalid @enderror"
                       value="{{ old('tanggal_repair', $repair->tanggal_repair->format('Y-m-d')) }}">
                @error('tanggal_repair', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Shift <span class="text-danger">*</span></label>
                <select name="id_shift" class="form-select @error('id_shift', 'updateRepair') is-invalid @enderror">
                    @foreach ($shifts as $s)
                        <option value="{{ $s->id }}" @selected(old('id_shift', $repair->id_shift) == $s->id)>{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
                @error('id_shift', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Operator <span class="text-danger">*</span></label>
                <select name="id_karyawan" class="form-select @error('id_karyawan', 'updateRepair') is-invalid @enderror">
                    @foreach ($karyawan as $k)
                        <option value="{{ $k->id }}" @selected(old('id_karyawan', $repair->id_karyawan) == $k->id)>{{ $k->nama_karyawan }}</option>
                    @endforeach
                </select>
                @error('id_karyawan', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Part &amp; Proses Repair <span class="text-danger">*</span></label>
                <select name="id_part_proses_repair" class="form-select @error('id_part_proses_repair', 'updateRepair') is-invalid @enderror">
                    @foreach ($parts as $p)
                        @foreach ($p->prosesRepair as $pr)
                            <option value="{{ $pr->id }}" @selected(old('id_part_proses_repair', $repair->id_part_proses_repair) == $pr->id)>
                                {{ $p->part_no }} — {{ $p->part_name }} — {{ $pr->id_proses }} {{ $pr->nama_proses }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                @error('id_part_proses_repair', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-2 mb-3">
                <div class="col">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start', 'updateRepair') is-invalid @enderror"
                           value="{{ old('jam_start', $repair->jam_start) }}">
                    @error('jam_start', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish', 'updateRepair') is-invalid @enderror"
                           value="{{ old('jam_finish', $repair->jam_finish) }}">
                    @error('jam_finish', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Qty Act Repair <span class="text-danger">*</span></label>
                <input type="number" name="qty_aktual" min="1" class="form-control @error('qty_aktual', 'updateRepair') is-invalid @enderror"
                       value="{{ old('qty_aktual', $repair->qty_aktual) }}">
                @error('qty_aktual', 'updateRepair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.repair.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
