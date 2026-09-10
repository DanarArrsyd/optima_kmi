@extends('layouts.app')
@section('title', 'Edit LHR Produksi')

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit LHR Produksi</h1>
    <a href="{{ route('input.repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->updateRepairProduksi->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->updateRepairProduksi->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:640px">
    <div class="erp-panel-header">Form Edit LHR Produksi</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.repair-produksi.update', $repair) }}">
            @csrf @method('PUT')
            <input type="hidden" name="back_query" value="{{ request('back_query') }}">

            <div class="mb-3">
                <label class="form-label">Tanggal Repair <span class="text-danger">*</span></label>
                <input type="date" name="tanggal_repair_produksi" class="form-control @error('tanggal_repair_produksi', 'updateRepairProduksi') is-invalid @enderror"
                       value="{{ old('tanggal_repair_produksi', $repair->tanggal_repair_produksi->format('Y-m-d')) }}">
                @error('tanggal_repair_produksi', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Shift <span class="text-danger">*</span></label>
                <select name="id_shift" class="form-select @error('id_shift', 'updateRepairProduksi') is-invalid @enderror">
                    @foreach ($shifts as $s)
                        <option value="{{ $s->id }}" @selected(old('id_shift', $repair->id_shift) == $s->id)>{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
                @error('id_shift', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Operator <span class="text-danger">*</span></label>
                <select name="id_karyawan" class="form-select @error('id_karyawan', 'updateRepairProduksi') is-invalid @enderror">
                    @foreach ($karyawan as $k)
                        <option value="{{ $k->id }}" @selected(old('id_karyawan', $repair->id_karyawan) == $k->id)>{{ $k->nama_karyawan }}</option>
                    @endforeach
                </select>
                @error('id_karyawan', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="mb-3">
                <label class="form-label">Part &amp; Proses Repair Produksi <span class="text-danger">*</span></label>
                <select name="id_part_proses_repair_produksi" class="form-select @error('id_part_proses_repair_produksi', 'updateRepairProduksi') is-invalid @enderror">
                    @foreach ($parts as $p)
                        @foreach ($p->prosesRepairProduksi as $pr)
                            <option value="{{ $pr->id }}" @selected(old('id_part_proses_repair_produksi', $repair->id_part_proses_repair_produksi) == $pr->id)>
                                {{ $p->part_no }} — {{ $p->part_name }} — {{ $pr->id_proses }} {{ $pr->nama_proses }}
                            </option>
                        @endforeach
                    @endforeach
                </select>
                @error('id_part_proses_repair_produksi', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="row g-2 mb-3">
                <div class="col">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start', 'updateRepairProduksi') is-invalid @enderror"
                           value="{{ old('jam_start', $repair->jam_start) }}">
                    @error('jam_start', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish', 'updateRepairProduksi') is-invalid @enderror"
                           value="{{ old('jam_finish', $repair->jam_finish) }}">
                    @error('jam_finish', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label">Qty Act Repair <span class="text-danger">*</span></label>
                <input type="number" name="qty_aktual" min="1" class="form-control @error('qty_aktual', 'updateRepairProduksi') is-invalid @enderror"
                       value="{{ old('qty_aktual', $repair->qty_aktual) }}">
                @error('qty_aktual', 'updateRepairProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
