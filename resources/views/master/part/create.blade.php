@extends('layouts.app')
@section('title', 'Tambah Part')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-box-seam"></i>Tambah Part</h1>
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<form method="POST" action="{{ route('master.part.store') }}" x-data="prosesRows(@js(old('proses', [])))" novalidate>
    @csrf

    <div class="row g-4">

        {{-- LEFT: Info Part --}}
        <div class="col-lg-5">
            <div class="erp-panel">
                <div class="erp-panel-header">Info Part</div>
                <div class="erp-panel-body">
                    <div class="mb-3">
                        <label class="form-label">Customer <span class="text-muted">(opsional)</span></label>
                        <input type="text" name="customer" class="form-control @error('customer') is-invalid @enderror"
                               value="{{ old('customer') }}" placeholder="Contoh: Toyota, Honda, Yamaha">
                        @error('customer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Part No <span class="text-danger">*</span></label>
                        <input type="text" name="part_no" class="form-control @error('part_no') is-invalid @enderror"
                               value="{{ old('part_no') }}" placeholder="Contoh: BT-091A-3">
                        @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Part Name <span class="text-danger">*</span></label>
                        <input type="text" name="part_name" class="form-control @error('part_name') is-invalid @enderror"
                               value="{{ old('part_name') }}" placeholder="Nama part">
                        @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-1">
                        <label class="form-label">Tanggal Instalasi Dies <span class="text-muted" style="font-weight:400;text-transform:none;letter-spacing:0">(opsional — basis MTBF &amp; Availability Dies)</span></label>
                        <input type="date" name="tanggal_instalasi" class="form-control @error('tanggal_instalasi') is-invalid @enderror"
                               value="{{ old('tanggal_instalasi') }}">
                        @error('tanggal_instalasi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>
        </div>

        {{-- RIGHT: Proses (opsional, bisa langsung diisi di sini alih-alih
             lompat ke halaman edit setelah simpan) --}}
        <div class="col-lg-7">
            <div class="erp-panel">
                <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
                    <span>Proses <span class="text-muted" style="font-weight:400;text-transform:none;letter-spacing:0">(opsional — bisa ditambah nanti juga)</span></span>
                    <button type="button" class="btn btn-outline-secondary btn-sm" @click="add()">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Baris Proses
                    </button>
                </div>
                <div class="erp-panel-body">
                    <template x-if="rows.length === 0">
                        <div class="text-muted" style="padding:16px 0;text-align:center">
                            Belum ada proses. Klik "Tambah Baris Proses" kalau mau isi sekarang,
                            atau lewati dulu — bisa ditambah kapan saja dari halaman edit part.
                        </div>
                    </template>

                    <template x-for="(row, i) in rows" :key="row.uid">
                        <div style="border:1px solid var(--border-2);border-radius:2px;padding:12px;margin-bottom:10px">
                            <div class="row g-2 mb-2">
                                <div class="col-3">
                                    <label class="form-label" style="font-size:12px">ID Proses</label>
                                    <input type="text" class="form-control form-control-sm"
                                           :name="`proses[${i}][id_proses]`" x-model="row.id_proses"
                                           placeholder="1" required>
                                </div>
                                <div class="col-7">
                                    <label class="form-label" style="font-size:12px">Nama Proses</label>
                                    <input type="text" class="form-control form-control-sm"
                                           :name="`proses[${i}][nama_proses]`" x-model="row.nama_proses"
                                           placeholder="PROGRESSIVE / BENDING / dll" required>
                                </div>
                                <div class="col-2 d-flex align-items-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger w-100"
                                            @click="remove(i)" title="Hapus baris">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </div>
                            </div>
                            <div class="row g-2">
                                <div class="col-3">
                                    <label class="form-label" style="font-size:12px">SPH Produksi</label>
                                    <input type="number" step="1" min="0" class="form-control form-control-sm"
                                           :name="`proses[${i}][sph_standar_produksi]`"
                                           x-model.number="row.sph_standar_produksi" required>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:12px">SPH QC</label>
                                    <input type="number" step="1" min="0" class="form-control form-control-sm"
                                           :name="`proses[${i}][sph_standar_qc]`"
                                           x-model.number="row.sph_standar_qc" required>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:12px">Std Trial QC (mnt)</label>
                                    <input type="number" min="0" class="form-control form-control-sm"
                                           :name="`proses[${i}][std_trial_qc_menit]`"
                                           x-model.number="row.std_trial_qc_menit" required>
                                </div>
                                <div class="col-3">
                                    <label class="form-label" style="font-size:12px">Std Setting Dies (mnt)</label>
                                    <input type="number" min="0" class="form-control form-control-sm"
                                           :name="`proses[${i}][std_setting_dies_menit]`"
                                           x-model.number="row.std_setting_dies_menit" required>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

    </div>{{-- /row --}}

    <div class="d-flex gap-2">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-save me-1"></i>Simpan Part
        </button>
        <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
    </div>
</form>
@endsection

@push('scripts')
<script>
function prosesRows(initial) {
    let seq = 0;
    const baris = (r = {}) => ({
        uid: seq++,
        id_proses: r.id_proses ?? '',
        nama_proses: r.nama_proses ?? '',
        sph_standar_produksi: r.sph_standar_produksi ?? 0,
        sph_standar_qc: r.sph_standar_qc ?? 0,
        std_trial_qc_menit: r.std_trial_qc_menit ?? 0,
        std_setting_dies_menit: r.std_setting_dies_menit ?? 0,
    });
    return {
        rows: (initial || []).map(baris),
        add() {
            this.rows.push(baris());
        },
        remove(i) {
            this.rows.splice(i, 1);
        },
    };
}
</script>
@endpush
