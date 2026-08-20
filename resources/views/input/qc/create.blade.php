@extends('layouts.app')
@section('title', 'Input LHP Quality')

@php
$oldKaryawanId  = old('id_karyawan_qc');
$oldKaryawan    = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawan?->nama_karyawan ?? '';
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-check2-square"></i>Input LHP Quality</h1>
    <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@if($karyawan->isEmpty() || $parts->isEmpty())
    <div class="alert alert-warning d-flex align-items-start gap-2 mb-3">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div>
            <strong>Master data belum lengkap.</strong> Sebelum input LHP Quality, lengkapi dulu:
            <ul class="mb-1 mt-1">
                @if($karyawan->isEmpty()) <li><a href="{{ route('master.karyawan.index') }}">Karyawan</a> — belum ada QC Inspector (departemen: QC)</li> @endif
                @if($parts->isEmpty())   <li><a href="{{ route('master.part.index') }}">Part / Dies</a> — belum ada data part dan proses</li> @endif
            </ul>
        </div>
    </div>
@endif

<div class="erp-panel" style="max-width:750px">
    <div class="erp-panel-header">Form Input LHP Quality</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.qc.store') }}" id="formQc" novalidate>
            @csrf
            <div class="row g-3">

                {{-- Tanggal --}}
                <div class="col-md-6">
                    <label class="form-label">Tanggal Pengecekan <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_pengecekan"
                           class="form-control @error('tgl_pengecekan') is-invalid @enderror"
                           value="{{ old('tgl_pengecekan', date('Y-m-d')) }}">
                    @error('tgl_pengecekan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi Part <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_produksi_part"
                           class="form-control @error('tgl_produksi_part') is-invalid @enderror"
                           value="{{ old('tgl_produksi_part', date('Y-m-d')) }}">
                    @error('tgl_produksi_part')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Shift --}}
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

                {{-- QC Inspector — typeahead --}}
                <div class="col-md-6"
                     x-data="taField(taKaryawan, '{{ $oldKaryawanTxt }}', {{ $oldKaryawanId ?: 'null' }})">
                    <label class="form-label">QC Inspector <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control ta-input @error('id_karyawan_qc') is-invalid @enderror"
                               x-model="query"
                               @focus="openDd()"
                               @blur="closeDd()"
                               @keydown="onKey($event)"
                               @input="onInput()"
                               placeholder="Ketik nama inspector...">
                        <input type="hidden" name="id_karyawan_qc" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_karyawan_qc')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Part — typeahead, auto-select finish-good proses --}}
                <div class="col-12" x-data="qcPartSelector()">
                    <label class="form-label">Part (Finish Good) <span class="text-danger">*</span></label>
                    <div class="ta-wrap" style="margin-bottom:10px">
                        <input type="text"
                               class="form-control ta-input"
                               x-model="query"
                               @focus="openDd()"
                               @blur="closeDd()"
                               @keydown="onKey($event)"
                               @input="onInput()"
                               placeholder="Ketik part no atau nama part...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>

                    {{-- Proses info (auto-selected finish good) --}}
                    <div x-show="proses !== null" x-cloak class="mb-2 p-2"
                         style="background:#f0f4f8;border:1px solid #d4dbe3;border-radius:2px;font-size:0.8rem">
                        <i class="bi bi-check2-circle text-success me-1"></i>
                        <strong>Proses Finish Good:</strong>
                        <span x-text="proses ? `${proses.id_proses} – ${proses.nama_proses}` : ''"></span>
                        <span style="color:#5d7283"
                              x-text="proses ? ` (SPH Std QC: ${proses.sph_standar_qc})` : ''"></span>
                    </div>
                    <input type="hidden" name="id_part_proses" :value="proses ? proses.id : ''">
                    @error('id_part_proses')<div class="text-danger mt-1" style="font-size:0.875rem">{{ $message }}</div>@enderror
                </div>

                {{-- Jam --}}
                <div class="col-md-6">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start"
                           class="form-control @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish"
                           class="form-control @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Qty OK --}}
                <div class="col-md-6">
                    <label class="form-label">Qty OK <span class="text-danger">*</span></label>
                    <input type="number" name="qty_ok"
                           class="form-control @error('qty_ok') is-invalid @enderror"
                           value="{{ old('qty_ok', 0) }}" min="0">
                    @error('qty_ok')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Jenis NG --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Jenis NG</label>
                    @include('input.partials.ng-rows', [
                        'jenisNg' => $jenisNg,
                        'initial' => old('ng', []),
                    ])
                </div>

            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan
                </button>
                <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@php
$_taKaryawan = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taParts    = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_taJenisNg  = $jenisNg->map(fn($j) => ['v' => $j->id, 't' => "{$j->kode} — {$j->nama_ng}"])->values();
$_partsData  = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'             => $pr->id,
        'id_proses'      => $pr->id_proses,
        'nama_proses'    => $pr->nama_proses,
        'sph_standar_qc' => $pr->sph_standar_qc,
    ])->last(), // finish-good = last proses
])->values();
@endphp
@push('scripts')
<script>
/* ── Data from PHP ─────────────────────────────────────── */
const taKaryawan = @json($_taKaryawan);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);
const taJenisNg  = @json($_taJenisNg);

/* taField() kini satu definisi di layouts/app.blade.php. */

/* ── Client-side validation ─────────────────────────────── */
(function () {
    const form = document.getElementById('formQc');
    if (!form) return;

    function clearErrs() {
        form.querySelectorAll('.cve').forEach(e => e.remove());
        form.querySelectorAll('.cvi').forEach(el => el.classList.remove('cvi', 'is-invalid'));
    }

    function mkErr(el, msg) {
        el.classList.add('is-invalid', 'cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve';
        d.textContent = msg;
        el.insertAdjacentElement('afterend', d);
        ['input', 'change'].forEach(ev =>
            el.addEventListener(ev, () => {
                el.classList.remove('is-invalid', 'cvi');
                if (d.parentNode) d.remove();
            }, { once: true })
        );
    }

    function mkTa(hiddenInput, msg) {
        const wrap = hiddenInput.closest('.ta-wrap');
        const vis  = wrap.querySelector('.ta-input');
        vis.classList.add('is-invalid', 'cvi');
        const d = document.createElement('div');
        d.className = 'invalid-feedback d-block cve';
        d.textContent = msg;
        wrap.insertAdjacentElement('afterend', d);
        vis.addEventListener('input', () => {
            vis.classList.remove('is-invalid', 'cvi');
            if (d.parentNode) d.remove();
        }, { once: true });
    }

    form.addEventListener('submit', function (e) {
        clearErrs();
        const bad = [];
        const f   = n => form.querySelector(`[name="${n}"]`);

        const tgl1 = f('tgl_pengecekan');
        if (!tgl1.value) { mkErr(tgl1, 'Tanggal pengecekan wajib diisi.'); bad.push(tgl1); }

        const tgl2 = f('tgl_produksi_part');
        if (!tgl2.value) { mkErr(tgl2, 'Tanggal produksi part wajib diisi.'); bad.push(tgl2); }

        const shift = f('id_shift');
        if (!shift.value) { mkErr(shift, 'Shift wajib dipilih.'); bad.push(shift); }

        const kar = f('id_karyawan_qc');
        if (!kar.value) { mkTa(kar, 'QC Inspector wajib dipilih.'); bad.push(kar.closest('.ta-wrap').querySelector('.ta-input')); }

        /* id_part_proses is a hidden Alpine-managed input; error shown near part ta-input */
        const partProses = f('id_part_proses');
        if (!partProses.value) {
            const col  = partProses.closest('.col-12');
            const wrap = col.querySelector('.ta-wrap');
            const vis  = wrap.querySelector('.ta-input');
            vis.classList.add('is-invalid', 'cvi');
            const d = document.createElement('div');
            d.className = 'invalid-feedback d-block cve';
            d.textContent = 'Part (Finish Good) wajib dipilih.';
            wrap.insertAdjacentElement('afterend', d);
            vis.addEventListener('input', () => {
                vis.classList.remove('is-invalid', 'cvi');
                if (d.parentNode) d.remove();
            }, { once: true });
            bad.push(vis);
        }

        const jamStart = f('jam_start');
        if (!jamStart.value) { mkErr(jamStart, 'Jam start wajib diisi.'); bad.push(jamStart); }

        const jamFinish = f('jam_finish');
        if (!jamFinish.value) { mkErr(jamFinish, 'Jam finish wajib diisi.'); bad.push(jamFinish); }

        if (bad.length) {
            e.preventDefault();
            bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();

/* ── QC Part + auto finish-good proses ─────────────────── */
function qcPartSelector() {
    return {
        ...taField(taParts),
        proses: null,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pick(item) {
            this.query  = item.t;
            this.val    = item.v;
            this.open   = false;
            this.idx    = -1;
            const part  = partsData.find(p => p.id == item.v);
            this.proses = part ? part.proses : null;
        },
    };
}
</script>
@endpush
