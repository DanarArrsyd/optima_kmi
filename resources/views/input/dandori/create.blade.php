@extends('layouts.app')
@section('title', 'Input Dandori')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Input Dandori</h4>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="card shadow-sm" style="max-width:800px">
    <div class="card-body" x-data="dandoriForm()">
        <form method="POST" action="{{ route('input.dandori.store') }}">
            @csrf

            {{-- Header fields --}}
            <div class="row g-3 mb-4">
                <div class="col-md-4">
                    <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal" class="form-control @error('tanggal') is-invalid @enderror"
                           value="{{ old('tanggal', date('Y-m-d')) }}">
                    @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Pilih --</option>
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
                <div class="col-md-6">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <select class="form-select" @change="onPartChange($event.target.value)">
                        <option value="">-- Pilih Part --</option>
                        @foreach($parts as $p)
                            <option value="{{ $p->id }}">{{ $p->part_no }} — {{ $p->part_name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0" @change="onProsesChange($event.target.value)">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Standard Time (menit)</label>
                    <input type="text" class="form-control bg-light" :value="standardTime > 0 ? standardTime + ' menit' : '-'" readonly>
                </div>
            </div>

            {{-- Rincian Loss Time --}}
            <div class="mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <label class="form-label mb-0 fw-semibold">Rincian Loss Time <span class="text-danger">*</span></label>
                    <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRow()">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Aktivitas
                    </button>
                </div>
                @error('rincian')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

                <div class="table-responsive">
                    <table class="table table-bordered table-sm">
                        <thead class="table-light">
                            <tr><th>Jenis Loss Time</th><th style="width:140px">Menit</th><th style="width:50px"></th></tr>
                        </thead>
                        <tbody>
                            <template x-for="(row, index) in rows" :key="index">
                                <tr>
                                    <td>
                                        <select :name="`rincian[${index}][id_losstime]`" class="form-select form-select-sm" x-model="row.id_losstime" required>
                                            <option value="">-- Pilih --</option>
                                            @foreach($losstimes as $lt)
                                                <option value="{{ $lt->id }}">{{ $lt->kode }} — {{ $lt->jenis_losstime }}</option>
                                            @endforeach
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" :name="`rincian[${index}][menit_loss]`"
                                               class="form-control form-control-sm"
                                               x-model.number="row.menit_loss"
                                               @input="recalculate()"
                                               min="1" required>
                                    </td>
                                    <td class="text-center">
                                        <button type="button" class="btn btn-sm btn-outline-danger"
                                                @click="removeRow(index)" :disabled="rows.length === 1">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- Live summary panel --}}
            <div class="p-3 rounded mb-4"
                 :class="status === 'OVER' ? 'bg-danger bg-opacity-10 border border-danger' : 'bg-success bg-opacity-10 border border-success'">
                <div class="row text-center">
                    <div class="col">
                        <div class="small text-muted">Total Aktual</div>
                        <div class="fw-bold fs-5" x-text="totalAktual + ' menit'"></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Standard</div>
                        <div class="fw-bold fs-5" x-text="standardTime > 0 ? standardTime + ' menit' : '-'"></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Selisih</div>
                        <div class="fw-bold fs-5"
                             :class="status === 'OVER' ? 'text-danger' : 'text-success'"
                             x-text="standardTime > 0 ? (totalAktual - standardTime > 0 ? '+' : '') + (totalAktual - standardTime) + ' menit' : '-'"></div>
                    </div>
                    <div class="col">
                        <div class="small text-muted">Status</div>
                        <span class="badge fs-6" :class="status === 'OVER' ? 'bg-danger' : 'bg-success'" x-text="status"></span>
                    </div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const partsData = @json($parts->map(fn($p) => ['id' => $p->id, 'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses, 'std_dandori_menit' => $pr->std_dandori_menit])]));

function dandoriForm() {
    return {
        prosesList: [],
        standardTime: 0,
        rows: [{ id_losstime: '', menit_loss: 1 }],
        totalAktual: 0,
        status: 'OK',

        onPartChange(partId) {
            const part = partsData.find(p => p.id == partId);
            this.prosesList = part ? part.proses : [];
            this.standardTime = 0;
            this.recalculate();
        },

        onProsesChange(prosesId) {
            const proses = this.prosesList.find(p => p.id == prosesId);
            this.standardTime = proses ? proses.std_dandori_menit : 0;
            this.recalculate();
        },

        addRow() {
            this.rows.push({ id_losstime: '', menit_loss: 1 });
            this.recalculate();
        },

        removeRow(index) {
            if (this.rows.length > 1) {
                this.rows.splice(index, 1);
                this.recalculate();
            }
        },

        recalculate() {
            this.totalAktual = this.rows.reduce((sum, r) => sum + (parseInt(r.menit_loss) || 0), 0);
            this.status = (this.standardTime > 0 && this.totalAktual > this.standardTime) ? 'OVER' : 'OK';
        }
    }
}
</script>
@endpush
