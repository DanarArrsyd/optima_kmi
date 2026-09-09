@extends('layouts.app')
@section('title', 'Edit Proses Repair Produksi')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit Proses Repair Produksi</h1>
    <a href="{{ route('master.proses-repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Edit Proses Repair Produksi</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.proses-repair-produksi.update', $prosesRepairProduksi) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Part <span class="text-danger">*</span></label>
                <select name="id_part" class="form-select @error('id_part') is-invalid @enderror">
                    @foreach($parts as $p)
                        <option value="{{ $p->id }}" @selected(old('id_part', $prosesRepairProduksi->id_part) == $p->id)>{{ $p->part_no }} — {{ $p->part_name }}</option>
                    @endforeach
                </select>
                @error('id_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                       value="{{ old('id_proses', $prosesRepairProduksi->id_proses) }}">
                @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                       value="{{ old('nama_proses', $prosesRepairProduksi->nama_proses) }}">
                @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">SPH Standar Repair Produksi <span class="text-danger">*</span></label>
                <input type="number" step="0.01" name="sph_standar_repair_produksi"
                       class="form-control @error('sph_standar_repair_produksi') is-invalid @enderror"
                       value="{{ old('sph_standar_repair_produksi', $prosesRepairProduksi->sph_standar_repair_produksi) }}" min="0">
                @error('sph_standar_repair_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.proses-repair-produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
