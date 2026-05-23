@extends('layouts.app')
@section('title', 'Input LHP Produksi')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Input LHP Produksi</h4>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="card shadow-sm" style="max-width:750px">
    <div class="card-body">
        <form method="POST" action="{{ route('input.produksi.store') }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi" class="form-control @error('tanggal_produksi') is-invalid @enderror"
                           value="{{ old('tanggal_produksi', date('Y-m-d')) }}">
                    @error('tanggal_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Pilih Operator --</option>
                        @foreach($karyawan as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror">
                        <option value="">-- Pilih Mesin --</option>
                        @foreach($mesin as $m)
                            <option value="{{ $m->id }}" {{ old('id_mesin') == $m->id ? 'selected' : '' }}>
                                {{ $m->nama_mesin }} (Line {{ $m->line }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
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
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses} (SPH: ${p.sph_standar_produksi})`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start" class="form-control @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish" class="form-control @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Qty Aktual <span class="text-danger">*</span></label>
                    <input type="number" name="qty_aktual" class="form-control @error('qty_aktual') is-invalid @enderror"
                           value="{{ old('qty_aktual') }}" min="1" required>
                    @error('qty_aktual')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection
@push('scripts')
<script>
const partsData = @json($parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses, 'sph_standar_produksi' => $pr->sph_standar_produksi])]));

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
