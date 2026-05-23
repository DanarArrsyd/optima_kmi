@extends('layouts.app')
@section('title', 'Input LHP Quality')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Input LHP Quality</h4>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="card shadow-sm" style="max-width:750px">
    <div class="card-body">
        <form method="POST" action="{{ route('input.qc.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Tanggal Pengecekan <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_pengecekan" class="form-control @error('tgl_pengecekan') is-invalid @enderror"
                           value="{{ old('tgl_pengecekan', date('Y-m-d')) }}">
                    @error('tgl_pengecekan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi Part <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_produksi_part" class="form-control @error('tgl_produksi_part') is-invalid @enderror"
                           value="{{ old('tgl_produksi_part', date('Y-m-d')) }}">
                    @error('tgl_produksi_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih Shift --</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }} ({{ $shift->jam_start }} – {{ $shift->jam_finish }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">QC Inspector <span class="text-danger">*</span></label>
                    <select name="id_karyawan_qc" class="form-select @error('id_karyawan_qc') is-invalid @enderror">
                        <option value="">-- Pilih Inspector --</option>
                        @foreach($karyawan as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan_qc') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan_qc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-12" x-data="partProsesSelector()">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <select class="form-select mb-2" x-model="selectedPart" @change="loadProses()">
                        <option value="">-- Pilih Part --</option>
                        @foreach($parts as $p)
                            <option value="{{ $p->id }}">{{ $p->part_no }} — {{ $p->part_name }}</option>
                        @endforeach
                    </select>
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses} (SPH QC: ${p.sph_standar_qc})`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Qty OK <span class="text-danger">*</span></label>
                    <input type="number" name="qty_ok" class="form-control @error('qty_ok') is-invalid @enderror"
                           value="{{ old('qty_ok', 0) }}" min="0" required>
                    @error('qty_ok')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Qty NG <span class="text-danger">*</span></label>
                    <input type="number" name="qty_ng" class="form-control @error('qty_ng') is-invalid @enderror"
                           value="{{ old('qty_ng', 0) }}" min="0" required>
                    @error('qty_ng')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
@push('scripts')
<script>
const partsData = @json($parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses, 'sph_standar_qc' => $pr->sph_standar_qc])]));

function partProsesSelector() {
    return {
        selectedPart: '',
        prosesList: [],
        loadProses() {
            const part = partsData.find(p => p.id == this.selectedPart);
            this.prosesList = part ? part.proses : [];
        }
    }
}
</script>
@endpush
