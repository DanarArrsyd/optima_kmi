@extends('layouts.app')
@section('title', 'Input LHP Produksi')

@php
/* Pre-compute old() display values for typeahead pre-fill */
$oldKaryawanId  = old('id_karyawan');
$oldKaryawan    = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawan?->nama_karyawan ?? '';

$oldMesinId  = old('id_mesin');
$oldMesinObj = $mesin->firstWhere('id', $oldMesinId);
$oldMesinTxt = $oldMesinObj ? "{$oldMesinObj->id_mesin} - {$oldMesinObj->nama_mesin} (Line {$oldMesinObj->line})" : '';

$oldPartId  = null; // Part uses a separate hidden field via Alpine

$oldAdaNg      = old('ada_ng', false);
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-clipboard-data"></i>Input LHP Produksi</h1>
    <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@if($karyawan->isEmpty() || $mesin->isEmpty() || $parts->isEmpty())
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div>
            <strong>Master data belum lengkap.</strong> Sebelum input LHP Produksi, lengkapi dulu:
            <ul class="mb-1 mt-1">
                @if($karyawan->isEmpty()) <li><a href="{{ route('master.karyawan.index') }}">Karyawan</a> — belum ada operator (departemen: Produksi)</li> @endif
                @if($mesin->isEmpty())    <li><a href="{{ route('master.mesin.index') }}">Mesin</a> — belum ada data mesin</li> @endif
                @if($parts->isEmpty())   <li><a href="{{ route('master.part.index') }}">Part / Dies</a> — belum ada data part dan proses</li> @endif
            </ul>
        </div>
    </div>
@endif

<div class="erp-panel" style="max-width:750px">
    <div class="erp-panel-header">Form Input LHP Produksi</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.produksi.store') }}" id="formProduksi" novalidate>
            @csrf
            <div class="row g-3">

                {{-- Tanggal --}}
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi"
                           class="form-control @error('tanggal_produksi') is-invalid @enderror"
                           value="{{ old('tanggal_produksi', date('Y-m-d')) }}">
                    @error('tanggal_produksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
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

                {{-- Operator — typeahead --}}
                <div class="col-md-6"
                     x-data="taField(taKaryawan, '{{ $oldKaryawanTxt }}', {{ $oldKaryawanId ?: 'null' }})">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control ta-input @error('id_karyawan') is-invalid @enderror"
                               x-model="query"
                               @focus="openDd()"
                               @blur="closeDd()"
                               @keydown="onKey($event)"
                               @input="onInput()"
                               placeholder="Ketik nama operator...">
                        <input type="hidden" name="id_karyawan" :value="val">
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
                    @error('id_karyawan')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Mesin — typeahead --}}
                <div class="col-md-6"
                     x-data="taField(taMesin, '{{ $oldMesinTxt }}', {{ $oldMesinId ?: 'null' }})">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control ta-input @error('id_mesin') is-invalid @enderror"
                               x-model="query"
                               @focus="openDd()"
                               @blur="closeDd()"
                               @keydown="onKey($event)"
                               @input="onInput()"
                               placeholder="Ketik ID atau nama mesin...">
                        <input type="hidden" name="id_mesin" :value="val">
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
                    @error('id_mesin')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Part + Proses — typeahead for part, select for proses --}}
                <div class="col-12" x-data="produksiPartSelector()">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
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

                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses"
                            class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id"
                                    x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_produksi})`">
                            </option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Jam Start & Finish --}}
                <div class="col-md-4">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start"
                           class="form-control @error('jam_start') is-invalid @enderror"
                           value="{{ old('jam_start') }}">
                    @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish"
                           class="form-control @error('jam_finish') is-invalid @enderror"
                           value="{{ old('jam_finish') }}">
                    @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Qty Aktual <span class="text-danger">*</span></label>
                    <input type="number" name="qty_aktual"
                           class="form-control @error('qty_aktual') is-invalid @enderror"
                           value="{{ old('qty_aktual') }}" min="1" required>
                    @error('qty_aktual')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- NG Proses Toggle --}}
                <div class="col-12" x-data="{ adaNg: {{ $oldAdaNg ? 'true' : 'false' }} }">
                    <div class="p-3" style="background:#fafafa;border:1px solid #e5e7eb;border-radius:4px">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_ng" id="ada_ng"
                                   value="1" x-model="adaNg"
                                   {{-- Un-tick harus benar-benar mengosongkan rows Alpine di partial
                                        ng-rows (bukan cuma menyembunyikannya lewat x-show), supaya
                                        ng_count ikut jadi 0 dan konsisten dengan ng=[] yang dikirim
                                        prepareForValidation(). Tanpa ini ng_count bisa membawa sisa
                                        hitungan baris lama saat checkbox dilepas. --}}
                                   @change="if (!adaNg) { const w = $el.closest('[x-data]').querySelector('.ng-rows'); if (w) Alpine.$data(w).rows = []; }"
                                   {{ $oldAdaNg ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="ada_ng"
                                   style="font-size:0.875rem;color:#374151">
                                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Ada NG Proses?
                            </label>
                        </div>

                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', [
                                'jenisNg' => $jenisNg,
                                'initial' => old('ng', []),
                            ])
                        </div>
                    </div>
                </div>

            </div>
            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan
                </button>
                <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@php
$_taKaryawan = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taMesin    = $mesin->map(fn($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
$_taParts    = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData  = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'                   => $pr->id,
        'id_proses'            => $pr->id_proses,
        'nama_proses'          => $pr->nama_proses,
        'sph_standar_produksi' => $pr->sph_standar_produksi,
    ])->values(),
])->values();
$_taJenisNg  = $jenisNg->map(fn($j) => ['v' => $j->id, 't' => "{$j->kode} — {$j->nama_ng}"])->values();
@endphp
@push('scripts')
<script>
/* ── Data from PHP ─────────────────────────────────────── */
const taKaryawan = @json($_taKaryawan);
const taMesin    = @json($_taMesin);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);
const taJenisNg  = @json($_taJenisNg);

/* ── Generic typeahead field component ─────────────────── */
function taField(items, initText = '', initVal = null) {
    return {
        items,
        query:  initText,
        val:    initVal,
        open:   false,
        idx:    -1,

        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() {
            setTimeout(() => {
                this.open = false;
                /* If typed text doesn't match current selection, clear */
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = '';
                }
            }, 180);
        },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) {
            this.query = item.t;
            this.val   = item.v;
            this.open  = false;
            this.idx   = -1;
        },
        onKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                this.open = true;
                this.idx  = Math.min(this.idx + 1, this.filtered.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.idx = Math.max(this.idx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.idx >= 0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]);
            } else if (e.key === 'Escape') {
                this.open = false;
            }
        },
    };
}

/* ── Client-side validation ─────────────────────────────── */
(function () {
    const form = document.getElementById('formProduksi');
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

        const tanggal = f('tanggal_produksi');
        if (!tanggal.value) { mkErr(tanggal, 'Tanggal produksi wajib diisi.'); bad.push(tanggal); }

        const shift = f('id_shift');
        if (!shift.value) { mkErr(shift, 'Shift wajib dipilih.'); bad.push(shift); }

        const kar = f('id_karyawan');
        if (!kar.value) { mkTa(kar, 'Operator wajib dipilih.'); bad.push(kar.closest('.ta-wrap').querySelector('.ta-input')); }

        const mes = f('id_mesin');
        if (!mes.value) { mkTa(mes, 'Mesin wajib dipilih.'); bad.push(mes.closest('.ta-wrap').querySelector('.ta-input')); }

        const pro = f('id_part_proses');
        if (!pro.value) {
            mkErr(pro, pro.disabled ? 'Pilih Part terlebih dahulu, kemudian pilih Proses.' : 'Proses wajib dipilih.');
            bad.push(pro);
        }

        const jamStart = f('jam_start');
        if (!jamStart.value) { mkErr(jamStart, 'Jam start wajib diisi.'); bad.push(jamStart); }

        const jamFinish = f('jam_finish');
        if (!jamFinish.value) { mkErr(jamFinish, 'Jam finish wajib diisi.'); bad.push(jamFinish); }

        const qty = f('qty_aktual');
        if (!qty.value || parseInt(qty.value) < 1) { mkErr(qty, 'Qty aktual wajib diisi (minimal 1).'); bad.push(qty); }

        if (bad.length) {
            e.preventDefault();
            bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();

/* ── Part + Proses selector (extends taField) ──────────── */
function produksiPartSelector() {
    return {
        ...taField(taParts),
        prosesList: [],
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pick(item) {
            this.query     = item.t;
            this.val       = item.v;
            this.open      = false;
            this.idx       = -1;
            const part     = partsData.find(p => p.id == item.v);
            this.prosesList = part ? part.proses : [];
        },
    };
}
</script>
@endpush
