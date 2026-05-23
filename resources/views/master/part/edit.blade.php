@extends('layouts.app')
@section('title', 'Edit Part')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Edit Part — <span class="text-muted">{{ $part->part_no }}</span></h4>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

{{-- Part Header --}}
<div class="card shadow-sm mb-4" style="max-width:500px">
    <div class="card-header fw-semibold">Info Part</div>
    <div class="card-body">
        <form method="POST" action="{{ route('master.part.update', $part) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Part No <span class="text-danger">*</span></label>
                <input type="text" name="part_no" class="form-control @error('part_no') is-invalid @enderror"
                       value="{{ old('part_no', $part->part_no) }}">
                @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Part Name <span class="text-danger">*</span></label>
                <input type="text" name="part_name" class="form-control @error('part_name') is-invalid @enderror"
                       value="{{ old('part_name', $part->part_name) }}">
                @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Update Part</button>
        </form>
    </div>
</div>

{{-- Proses Sub-table --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between">
        <span>Daftar Proses</span>
        <span class="badge bg-secondary">{{ $part->proses->count() }} proses</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID Proses</th><th>Nama Proses</th>
                    <th>SPH Prod</th><th>CT Prod</th>
                    <th>SPH QC</th><th>CT QC</th>
                    <th>Std Dandori</th><th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($part->proses as $p)
                <tr>
                    <td>{{ $p->id_proses }}</td>
                    <td>{{ $p->nama_proses }}</td>
                    <td>{{ $p->sph_standar_produksi }}</td>
                    <td>{{ $p->cycle_time_standar_produksi }}s</td>
                    <td>{{ $p->sph_standar_qc }}</td>
                    <td>{{ $p->cycle_time_standar_qc }}s</td>
                    <td>{{ $p->std_dandori_menit }} mnt</td>
                    <td>
                        <form method="POST" action="{{ route('master.part.proses.destroy', [$part, $p]) }}"
                              onsubmit="return confirm('Hapus proses ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-2">Belum ada proses.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Add Proses Form --}}
<div class="card shadow-sm" style="max-width:800px">
    <div class="card-header fw-semibold">Tambah Proses</div>
    <div class="card-body">
        <form method="POST" action="{{ route('master.part.proses.store', $part) }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-2">
                    <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                    <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                           placeholder="1" value="{{ old('id_proses') }}">
                    @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                    <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                           placeholder="PROGRESSIVE" value="{{ old('nama_proses') }}">
                    @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Std Dandori (mnt) <span class="text-danger">*</span></label>
                    <input type="number" name="std_dandori_menit" class="form-control @error('std_dandori_menit') is-invalid @enderror"
                           value="{{ old('std_dandori_menit', 15) }}" min="1">
                    @error('std_dandori_menit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label">SPH Standar Produksi</label>
                    <input type="number" step="0.01" name="sph_standar_produksi" class="form-control"
                           value="{{ old('sph_standar_produksi', 0) }}" min="0" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cycle Time Prod (dtk)</label>
                    <input type="number" step="0.01" name="cycle_time_standar_produksi" class="form-control"
                           value="{{ old('cycle_time_standar_produksi', 0) }}" min="0" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">SPH Standar QC</label>
                    <input type="number" step="0.01" name="sph_standar_qc" class="form-control"
                           value="{{ old('sph_standar_qc', 0) }}" min="0" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cycle Time QC (dtk)</label>
                    <input type="number" step="0.01" name="cycle_time_standar_qc" class="form-control"
                           value="{{ old('cycle_time_standar_qc', 0) }}" min="0" required>
                </div>
            </div>
            <button type="submit" class="btn btn-success mt-3">
                <i class="bi bi-plus-lg me-1"></i>Tambah Proses
            </button>
        </form>
    </div>
</div>
@endsection
