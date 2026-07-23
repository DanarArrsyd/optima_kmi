@extends('layouts.app')
@section('title', 'Input Preventive Dies')

@php $part = $partProses->part; @endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-tools"></i>Input Preventive Dies</h1>
    <a href="{{ route('maintenance.control-stroke-dies') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:750px">
    <div class="erp-panel-header">Form Input Preventive
        <span style="font-size:0.75rem;font-weight:400;color:#9ca3af;margin-left:8px">
            {{ $part?->part_no }} · {{ $part?->part_name }} · {{ $partProses->nama_proses }}
        </span>
    </div>
    <div class="erp-panel-body">

        {{-- Ringkasan stroke dies ini --}}
        <div class="mb-3">
            @include('maintenance.partials.stroke-summary', [
                'currentStroke' => $currentStroke,
                'threshold'     => $threshold,
            ])
        </div>

        <div class="alert alert-warning d-flex align-items-center gap-2 py-2" style="font-size:14px">
            <i class="bi bi-arrow-counterclockwise"></i>
            <span>Setelah disimpan, stroke dies ini direset dari
                  <strong>{{ number_format($currentStroke, 0, ',', '.') }}</strong> ke <strong>0</strong>.</span>
        </div>

        <form method="POST" action="{{ route('maintenance.control-stroke-dies.preventive', $partProses) }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Tanggal Preventive <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_preventive"
                           class="form-control @error('tanggal_preventive') is-invalid @enderror"
                           value="{{ old('tanggal_preventive', date('Y-m-d')) }}" max="{{ date('Y-m-d') }}">
                    @error('tanggal_preventive')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-md-6">
                    <label class="form-label">PIC / Petugas <span class="text-danger">*</span></label>
                    <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Pilih Petugas --</option>
                        @foreach($karyawanList as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    @if($karyawanList->isEmpty())
                        <div class="form-text text-danger">
                            Belum ada karyawan departemen Maintenance yang aktif. Tambahkan dulu di Master Karyawan.
                        </div>
                    @endif
                </div>

                <div class="col-12">
                    <label class="form-label">Catatan</label>
                    <textarea name="catatan" rows="3"
                              class="form-control @error('catatan') is-invalid @enderror"
                              placeholder="Opsional — mis. ganti pisau, cek clearance">{{ old('catatan') }}</textarea>
                    @error('catatan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                <div class="col-12">
                    <label class="form-label">Checksheet Preventive (PDF) <span class="text-danger">*</span></label>
                    <input type="file" name="checksheet" accept="application/pdf"
                           class="form-control @error('checksheet') is-invalid @enderror">
                    @error('checksheet')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <div class="form-text">Maks. 2 MB, format PDF.</div>
                </div>
            </div>

            <div class="d-flex gap-2 mt-4">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan &amp; Reset ke 0
                </button>
                <a href="{{ route('maintenance.control-stroke-dies') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
