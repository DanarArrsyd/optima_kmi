@extends('layouts.app')
@section('title', 'Input Dandori')

@php
$oldKaryawanId  = old('id_karyawan');
$oldKaryawanObj = $karyawan->firstWhere('id', $oldKaryawanId);
$oldKaryawanTxt = $oldKaryawanObj?->nama_karyawan ?? '';

$oldMesinId  = old('id_mesin');
$oldMesinObj = $mesin->firstWhere('id', $oldMesinId);
$oldMesinTxt = $oldMesinObj ? "{$oldMesinObj->id_mesin} - {$oldMesinObj->nama_mesin} (Line {$oldMesinObj->line})" : '';
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-tools"></i>Input Dandori</h1>
    <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@if($karyawan->isEmpty() || $mesin->isEmpty() || $parts->isEmpty() || $losstimes->isEmpty())
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill fs-5 mt-1"></i>
        <div>
            <strong>Master data belum lengkap.</strong> Sebelum input Dandori, lengkapi dulu:
            <ul class="mb-1 mt-1">
                @if($karyawan->isEmpty())  <li><a href="{{ route('master.karyawan.index') }}">Karyawan</a> — belum ada operator (departemen: Produksi)</li> @endif
                @if($mesin->isEmpty())     <li><a href="{{ route('master.mesin.index') }}">Mesin</a> — belum ada data mesin</li> @endif
                @if($parts->isEmpty())    <li><a href="{{ route('master.part.index') }}">Part / Dies</a> — belum ada data part dan proses</li> @endif
                @if($losstimes->isEmpty()) <li><a href="{{ route('master.losstime.index') }}">Loss Time</a> — belum ada jenis loss time</li> @endif
            </ul>
        </div>
    </div>
@endif

<div class="erp-panel" style="max-width:960px">
    <div class="erp-panel-header">Form Input Dandori</div>
    <div class="erp-panel-body" x-data="dandoriMain()">
        <form method="POST" action="{{ route('input.dandori.store') }}" id="formDandori" novalidate>
            @csrf

            {{-- ── SESI FIELDS ──────────────────────────────────────── --}}
            <div class="section-label mb-2" style="font-size:0.75rem;font-weight:700;letter-spacing:.06em;color:#6b7280;text-transform:uppercase">
                Informasi Sesi
            </div>
            <div class="row g-3 mb-4 pb-3" style="border-bottom:1px solid #e5e7eb">

                {{-- Tanggal --}}
                <div class="col-md-3">
                    <label class="form-label">Tanggal <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal"
                           class="form-control @error('tanggal') is-invalid @enderror"
                           value="{{ old('tanggal', date('Y-m-d')) }}">
                    @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Shift --}}
                <div class="col-md-3">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror">
                        <option value="">-- Pilih Shift --</option>
                        @foreach($shifts as $shift)
                            <option value="{{ $shift->id }}" {{ old('id_shift') == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }} ({{ $shift->jam_start }}–{{ $shift->jam_finish }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Operator — typeahead (independent x-data) --}}
                <div class="col-md-6"
                     x-data="taField(taKaryawan, '{{ $oldKaryawanTxt }}', {{ $oldKaryawanId ?: 'null' }})">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control ta-input @error('id_karyawan') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
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

                {{-- Mesin — typeahead (independent x-data) --}}
                <div class="col-md-6"
                     x-data="taField(taMesin, '{{ $oldMesinTxt }}', {{ $oldMesinId ?: 'null' }})">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control ta-input @error('id_mesin') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
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

                {{-- Part — typeahead (state managed by dandoriMain) --}}
                <div class="col-md-6">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <div class="ta-wrap" id="partTaWrapDandori">
                        <input type="text"
                               class="form-control ta-input"
                               x-model="partQuery"
                               @focus="partOpen = true; partIdx = -1"
                               @blur="partCloseDd()"
                               @keydown="partKey($event)"
                               @input="partVal = null; partOpen = true; partIdx = -1"
                               placeholder="Ketik part no atau nama part...">
                        <div class="ta-dropdown" x-show="partOpen" x-cloak>
                            <template x-if="partFiltered.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in partFiltered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === partIdx }"
                                     @mousedown.prevent="partPick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Proses — populated after part selected --}}
                <div class="col-md-6">
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses"
                            class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0"
                            @change="onProsesChange($event.target.value)">
                        <option value="">{{ '-- Pilih Part dulu --' }}</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Progressive badge --}}
                <div class="col-12" x-show="isProgressive" x-cloak>
                    <div class="alert alert-info py-2 mb-0 d-flex align-items-center gap-2" style="font-size:0.82rem">
                        <i class="bi bi-info-circle-fill"></i>
                        <span>Proses <strong>PROGRESSIVE</strong> aktif — standar waktu menggunakan kolom
                            <code>std_menit_progressive</code> dari master loss time.</span>
                    </div>
                </div>

            </div>{{-- /session fields --}}

            {{-- ── RINCIAN LOSS TIME ─────────────────────────────────── --}}
            <div class="section-label mb-2" style="font-size:0.75rem;font-weight:700;letter-spacing:.06em;color:#6b7280;text-transform:uppercase">
                Rincian Loss Time
            </div>

            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="text-muted" style="font-size:0.83rem">
                    Tambahkan setiap jenis aktivitas loss time yang terjadi selama sesi dandori ini.
                </span>
                <button type="button" class="btn btn-sm btn-outline-secondary" @click="addRow()">
                    <i class="bi bi-plus-lg me-1"></i>Tambah Baris
                </button>
            </div>

            @error('rincian')<div class="text-danger small mb-2">{{ $message }}</div>@enderror

            <div class="table-responsive mb-1">
                <table class="table table-bordered table-sm align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Jenis Loss Time</th>
                            <th style="width:130px">Aktual (mnt) <span class="text-danger">*</span></th>
                            <th style="width:105px" class="text-center">Std (mnt)</th>
                            <th style="width:105px" class="text-center">Over (mnt)</th>
                            <th style="width:44px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="(row, index) in rows" :key="index">
                            <tr>
                                <td>
                                    <div class="ta-wrap">
                                        <input type="text"
                                               class="form-control form-control-sm ta-input"
                                               x-model="row.ltQuery"
                                               @focus="row.ltOpen = true; row.ltIdx = -1"
                                               @blur="ltClose(row)"
                                               @keydown="ltKey(row, $event)"
                                               @input="row.id_losstime = ''; row.ltOpen = true; row.ltIdx = -1; onLosstimeChange(row)"
                                               placeholder="Ketik kode atau jenis...">
                                        <input type="hidden"
                                               :name="`rincian[${index}][id_losstime]`"
                                               :value="row.id_losstime">
                                        <div class="ta-dropdown" x-show="row.ltOpen" x-cloak>
                                            <template x-if="ltFiltered(row).length === 0">
                                                <div class="ta-empty">Tidak ditemukan</div>
                                            </template>
                                            <template x-for="(item, i) in ltFiltered(row)" :key="item.v">
                                                <div class="ta-item"
                                                     :class="{ 'ta-active': i === row.ltIdx }"
                                                     @mousedown.prevent="ltPick(row, item)"
                                                     x-text="item.t"></div>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <input type="number"
                                           :name="`rincian[${index}][actual_menit]`"
                                           class="form-control form-control-sm"
                                           x-model.number="row.actual_menit"
                                           @input="onActualChange(row)"
                                           step="0.1" min="0.1"
                                           placeholder="0.0" required>
                                </td>
                                <td class="text-center text-muted" style="font-size:0.88rem"
                                    x-text="row.std_menit > 0 ? row.std_menit.toFixed(1) : '—'">
                                </td>
                                <td class="text-center fw-semibold" style="font-size:0.88rem"
                                    :class="row.over_menit > 0 ? 'text-danger' : (row.std_menit > 0 ? 'text-success' : 'text-muted')"
                                    x-text="row.std_menit > 0
                                        ? (row.over_menit > 0 ? '+' + row.over_menit.toFixed(1) : 'OK')
                                        : '—'">
                                </td>
                                <td class="text-center">
                                    <button type="button"
                                            class="btn btn-sm btn-outline-danger py-0 px-1"
                                            @click="removeRow(index)"
                                            :disabled="rows.length === 1"
                                            title="Hapus baris">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                    <tfoot class="table-light">
                        <tr class="fw-semibold" style="font-size:0.88rem">
                            <td class="text-end pe-2" style="color:#374151">Total</td>
                            <td x-text="totalActual.toFixed(1) + ' mnt'" style="color:#374151"></td>
                            <td></td>
                            <td class="text-center"
                                :class="totalOver > 0 ? 'text-danger' : 'text-success'"
                                x-text="totalOver > 0 ? '+' + totalOver.toFixed(1) + ' mnt' : 'OK'">
                            </td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <p class="text-muted mb-4" style="font-size:0.78rem">
                <i class="bi bi-info-circle me-1"></i>
                Kolom <strong>Std</strong> dan <strong>Over</strong> otomatis terisi setelah memilih jenis loss time dan memasukkan waktu aktual.
            </p>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Simpan Data Dandori
                </button>
                <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>

        </form>
    </div>
</div>
@endsection

@php
$_taKaryawan   = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taMesin      = $mesin->map(fn($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
$_taParts      = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData    = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'         => $pr->id,
        'id_proses'  => $pr->id_proses,
        'nama_proses'=> $pr->nama_proses,
    ])->values(),
])->values();
$_losstimeData = $losstimes->map(fn($lt) => [
    'id'                  => $lt->id,
    'kode'                => $lt->kode,
    'jenis_losstime'      => $lt->jenis_losstime,
    'std_menit_default'   => (float) ($lt->std_menit_default   ?? 0),
    'std_menit_progressive'=> (float) ($lt->std_menit_progressive ?? 0),
])->values();
@endphp

@push('scripts')
<script>
/* ── Static data from PHP ────────────────────────────────── */
const taKaryawan   = @json($_taKaryawan);
const taMesin      = @json($_taMesin);
const taParts      = @json($_taParts);
const partsData    = @json($_partsData);
const losstimeData = @json($_losstimeData);

/* ── Generic typeahead component (shared) ───────────────── */
function taField(items, initText = '', initVal = null) {
    return {
        items,
        query: initText,
        val:   initVal,
        open:  false,
        idx:   -1,

        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = '';
                }
            }, 180);
        },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) {
            this.query = item.t; this.val = item.v;
            this.open = false;  this.idx = -1;
        },
        onKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault(); this.open = true;
                this.idx = Math.min(this.idx + 1, this.filtered.length - 1);
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
    const form = document.getElementById('formDandori');
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

        const tanggal = f('tanggal');
        if (!tanggal.value) { mkErr(tanggal, 'Tanggal wajib diisi.'); bad.push(tanggal); }

        const shift = f('id_shift');
        if (!shift.value) { mkErr(shift, 'Shift wajib dipilih.'); bad.push(shift); }

        const kar = f('id_karyawan');
        if (!kar.value) { mkTa(kar, 'Operator wajib dipilih.'); bad.push(kar.closest('.ta-wrap').querySelector('.ta-input')); }

        const mes = f('id_mesin');
        if (!mes.value) { mkTa(mes, 'Mesin wajib dipilih.'); bad.push(mes.closest('.ta-wrap').querySelector('.ta-input')); }

        /* Part + Proses */
        const pro = f('id_part_proses');
        if (!pro.value) {
            if (pro.disabled) {
                /* Part not selected yet — highlight part ta-input */
                const partWrap = document.getElementById('partTaWrapDandori');
                if (partWrap) {
                    const vis = partWrap.querySelector('.ta-input');
                    vis.classList.add('is-invalid', 'cvi');
                    const d = document.createElement('div');
                    d.className = 'invalid-feedback d-block cve';
                    d.textContent = 'Part wajib dipilih terlebih dahulu.';
                    partWrap.insertAdjacentElement('afterend', d);
                    vis.addEventListener('input', () => {
                        vis.classList.remove('is-invalid', 'cvi');
                        if (d.parentNode) d.remove();
                    }, { once: true });
                    bad.push(vis);
                }
            } else {
                mkErr(pro, 'Proses wajib dipilih.');
                bad.push(pro);
            }
        }

        /* Rincian rows — losstime is now a typeahead (hidden input carries the value) */
        let firstRowErr = null;
        form.querySelectorAll('input[type="hidden"][name*="[id_losstime]"]').forEach(hidden => {
            if (!hidden.value) {
                const wrap = hidden.closest('.ta-wrap');
                const vis  = wrap.querySelector('.ta-input');
                vis.classList.add('is-invalid', 'cvi');
                const d = document.createElement('div');
                d.className = 'invalid-feedback d-block cve';
                d.textContent = 'Pilih jenis loss time.';
                wrap.insertAdjacentElement('afterend', d);
                vis.addEventListener('input', () => {
                    vis.classList.remove('is-invalid', 'cvi');
                    if (d.parentNode) d.remove();
                }, { once: true });
                if (!firstRowErr) firstRowErr = vis;
            }
        });
        form.querySelectorAll('input[name*="[actual_menit]"]').forEach(inp => {
            if (!inp.value || parseFloat(inp.value) <= 0) { mkErr(inp, 'Aktual menit wajib diisi (min 0.1).'); if (!firstRowErr) firstRowErr = inp; }
        });
        if (firstRowErr) bad.push(firstRowErr);

        if (bad.length) {
            e.preventDefault();
            bad[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
    });
})();

/* ── Main Dandori form component ────────────────────────── */
function dandoriMain() {
    return {
        /* Part typeahead state (managed here so isProgressive is shared) */
        partQuery: '',
        partVal: null,
        partOpen: false,
        partIdx: -1,

        get partFiltered() {
            if (!this.partQuery.trim()) return taParts.slice(0, 60);
            const q = this.partQuery.toLowerCase();
            return taParts.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },

        partCloseDd() {
            setTimeout(() => {
                this.partOpen = false;
                if (this.partVal && !taParts.find(i => i.v == this.partVal && i.t === this.partQuery)) {
                    this.partVal = null; this.partQuery = '';
                    this.prosesList = [];
                    this.isProgressive = false;
                }
            }, 180);
        },

        partPick(item) {
            this.partQuery = item.t;
            this.partVal   = item.v;
            this.partOpen  = false;
            this.partIdx   = -1;
            const part = partsData.find(p => p.id == item.v);
            this.prosesList    = part ? part.proses : [];
            this.isProgressive = false;
            this.rows.forEach(r => this.recompute(r));
        },

        partKey(e) {
            if (e.key === 'ArrowDown') {
                e.preventDefault(); this.partOpen = true;
                this.partIdx = Math.min(this.partIdx + 1, this.partFiltered.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                this.partIdx = Math.max(this.partIdx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (this.partIdx >= 0 && this.partFiltered[this.partIdx]) this.partPick(this.partFiltered[this.partIdx]);
            } else if (e.key === 'Escape') {
                this.partOpen = false;
            }
        },

        /* Proses */
        prosesList: [],
        isProgressive: false,

        onProsesChange(prosesId) {
            const p = this.prosesList.find(p => p.id == prosesId);
            this.isProgressive = p
                ? p.nama_proses.trim().toUpperCase() === 'PROGRESSIVE'
                : false;
            this.rows.forEach(r => this.recompute(r));
        },

        /* Losstime typeahead (shared items list, per-row state stored in row objects) */
        ltItems: losstimeData.map(lt => ({ v: lt.id, t: `${lt.kode} — ${lt.jenis_losstime}` })),

        ltFiltered(row) {
            if (!row.ltQuery.trim()) return this.ltItems.slice(0, 60);
            const q = row.ltQuery.toLowerCase();
            return this.ltItems.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },

        ltPick(row, item) {
            row.ltQuery      = item.t;
            row.id_losstime  = item.v;
            row.ltOpen       = false;
            row.ltIdx        = -1;
            this.recompute(row);
        },

        ltClose(row) {
            setTimeout(() => {
                row.ltOpen = false;
                /* If typed text no longer matches selection, clear */
                if (row.id_losstime && !this.ltItems.find(i => i.v == row.id_losstime && i.t === row.ltQuery)) {
                    row.id_losstime = '';
                    row.ltQuery     = '';
                    this.recompute(row);
                }
            }, 180);
        },

        ltKey(row, e) {
            const f = this.ltFiltered(row);
            if (e.key === 'ArrowDown') {
                e.preventDefault(); row.ltOpen = true;
                row.ltIdx = Math.min(row.ltIdx + 1, f.length - 1);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                row.ltIdx = Math.max(row.ltIdx - 1, 0);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                if (row.ltIdx >= 0 && f[row.ltIdx]) this.ltPick(row, f[row.ltIdx]);
            } else if (e.key === 'Escape') {
                row.ltOpen = false;
            }
        },

        /* Rows */
        rows: [{ id_losstime: '', ltQuery: '', ltOpen: false, ltIdx: -1, actual_menit: '', std_menit: 0, over_menit: 0 }],

        addRow() {
            this.rows.push({ id_losstime: '', ltQuery: '', ltOpen: false, ltIdx: -1, actual_menit: '', std_menit: 0, over_menit: 0 });
        },

        removeRow(index) {
            if (this.rows.length > 1) this.rows.splice(index, 1);
        },

        onLosstimeChange(row) { this.recompute(row); },
        onActualChange(row)   { this.recompute(row); },

        recompute(row) {
            const lt = losstimeData.find(l => l.id == row.id_losstime);
            if (!lt) { row.std_menit = 0; row.over_menit = 0; return; }

            row.std_menit = this.isProgressive
                ? (lt.std_menit_progressive || lt.std_menit_default || 0)
                : (lt.std_menit_default || 0);

            const actual = parseFloat(row.actual_menit) || 0;
            row.over_menit = Math.max(0, actual - row.std_menit);
        },

        get totalActual() {
            return this.rows.reduce((s, r) => s + (parseFloat(r.actual_menit) || 0), 0);
        },
        get totalOver() {
            return this.rows.reduce((s, r) => s + (r.over_menit || 0), 0);
        },
    };
}
</script>
@endpush
