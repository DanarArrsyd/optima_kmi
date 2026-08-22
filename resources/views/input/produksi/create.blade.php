@extends('layouts.app')

@section('title', 'Input LHP Produksi')

@php
    $_taKaryawan = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
    $_taMesin = $mesin->map(fn ($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $parts->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->proses->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
            'sph_standar_produksi' => (float) $pr->sph_standar_produksi,
        ])->values(),
    ])->values();
    $_shiftData = $shifts->map(fn ($s) => ['id' => $s->id, 'nama' => $s->nama_shift])->values();
@endphp

@section('content')
<div class="erp-panel" x-data="lhpBatch()" x-init="mulai()">
    <div class="erp-panel-header">Input LHP Produksi</div>
    <div class="erp-panel-body">

        <template x-if="pesan">
            <div class="alert alert-info py-2" x-text="pesan"></div>
        </template>

        @if ($errors->any())
            <div class="alert alert-danger py-2">
                <div class="fw-semibold mb-1">Draft ditolak server:</div>
                <ul class="mb-0 ps-3">
                    @foreach ($errors->all() as $pesanGalat)
                        <li>{{ $pesanGalat }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        {{-- ══ FORM SATU BARIS ══ --}}
        <form id="formBaris" novalidate @submit.prevent="simpanKeDraft()">
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi" class="form-control"
                           value="{{ now()->format('Y-m-d') }}">
                </div>

                <div class="col-md-3">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select">
                        <option value="">-- Pilih Shift --</option>
                        @foreach ($shifts as $s)
                            <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="col-md-3" x-data="taField(taKaryawan)">
                    <label class="form-label">Operator <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input" x-model="query"
                               @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
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
                </div>

                <div class="col-md-3" x-data="taField(taMesin)">
                    <label class="form-label">Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input" x-model="query"
                               @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
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
                </div>

                <div class="col-md-4" x-data="partPicker()">
                    <label class="form-label">Part <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input" x-model="query"
                               @focus="open = true; idx = -1" @blur="tutup()" @keydown="tombol($event)"
                               @input="val = null; open = true; idx = -1"
                               placeholder="Ketik part no atau nama...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="tersaring.length === 0">
                                <div class="ta-empty">Tidak ditemukan</div>
                            </template>
                            <template x-for="(item, i) in tersaring" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pilih(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>

                    <label class="form-label mt-2">Part No.</label>
                    <input type="text" class="form-control" id="partNoTampil" :value="partNo" readonly
                           placeholder="Terisi otomatis dari Part">
                </div>

                <div class="col-md-4">
                    <label class="form-label">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select" :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Part dulu --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            {{-- data-progressive dibaca blok dandori untuk memilih
                                 std_menit_progressive. Penanda, bukan teks pilihan. --}}
                            <option :value="p.id"
                                    :data-progressive="p.nama_proses.trim().toUpperCase() === 'PROGRESSIVE' ? '1' : '0'"
                                    x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_produksi})`">
                            </option>
                        </template>
                    </select>
                </div>

                <div class="col-md-4">
                    <div class="row g-2">
                        <div class="col-4">
                            <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                            <input type="time" name="jam_start" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                            <input type="time" name="jam_finish" class="form-control">
                        </div>
                        <div class="col-4">
                            <label class="form-label">Qty OK <span class="text-danger">*</span></label>
                            <input type="number" name="qty_aktual" class="form-control" min="1">
                        </div>
                    </div>
                </div>

                {{-- Ada NG? --}}
                <div class="col-12" x-data="{ adaNg: false }">
                    <div class="input-panel">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_ng" id="ada_ng"
                                   value="1" x-model="adaNg"
                                   @change="if (!adaNg) { const w = $el.closest('[x-data]').querySelector('.ng-rows'); if (w) Alpine.$data(w).rows = []; }">
                            <label class="form-check-label fw-semibold" for="ada_ng"
                                   style="font-size:0.875rem;color:#374151">
                                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Ada NG Proses?
                            </label>
                        </div>

                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', ['jenisNg' => $jenisNg, 'initial' => []])
                        </div>
                    </div>
                </div>

                {{-- Ada Dandori? --}}
                <div class="col-12" x-data="{ adaDandori: false }">
                    <div class="input-panel">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_dandori"
                                   id="ada_dandori" value="1" x-model="adaDandori"
                                   @change="dandoriSync($el, adaDandori)">
                            <label class="form-check-label fw-semibold" for="ada_dandori"
                                   style="font-size:0.875rem;color:#374151">
                                <i class="bi bi-tools me-1 text-secondary"></i>Ada Dandori?
                            </label>
                        </div>

                        <div x-show="adaDandori" x-cloak class="mt-2">
                            @include('input.partials.dandori-rows', [
                                'losstimes' => $losstimes,
                                'karyawan' => $karyawan,
                                'initial' => [],
                            ])
                        </div>
                    </div>
                </div>
            </div>

            <template x-if="galat">
                <div class="alert alert-danger py-2 mt-3 mb-0" x-text="galat"></div>
            </template>

            <div class="d-flex justify-content-end mt-3">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-download me-1"></i>SAVE TO DRAFT
                </button>
            </div>
        </form>

        <hr class="my-4">

        {{-- ══ DRAFT TERSIMPAN ══ --}}
        <div class="fw-semibold mb-2">DRAFT TERSIMPAN <span x-text="`(${rows.length})`"></span></div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th><th>Shift</th><th>Operator</th><th>Mesin</th>
                        <th>Part No.</th><th>Part Name</th><th>Proses</th><th>Jam</th>
                        <th>Qty OK</th><th>Qty NG</th><th>Jenis NG</th><th>Dandori</th>
                        <th>SPH Std</th><th>SPH Act</th><th>W. Kerja</th><th>CT Act</th><th>%</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="18" class="text-center text-muted py-3">
                            Belum ada baris draft. Isi form di atas lalu klik SAVE TO DRAFT.
                        </td></tr>
                    </template>
                    <template x-for="(r, i) in rows" :key="r.uid">
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
                            <td x-text="r.tanggal_produksi"></td>
                            <td x-text="r.shift_teks"></td>
                            <td x-text="r.karyawan_teks"></td>
                            <td x-text="r.mesin_teks"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="`${r.jam_start}–${r.jam_finish}`"></td>
                            <td x-text="r.qty_aktual"></td>
                            <td x-text="r.ng.reduce((s, n) => s + Number(n.qty || 0), 0) || '—'"></td>
                            <td x-text="ringkasNg(r)"></td>
                            <td x-text="ringkasDandori(r)"></td>
                            <td x-text="r.sph_std.toFixed(0)"></td>
                            <td x-text="r.sph_act.toFixed(0)"></td>
                            <td x-text="r.jam_kerja.toFixed(2)"></td>
                            <td x-text="r.ct_act.toFixed(2)"></td>
                            <td x-text="`${r.persen.toFixed(0)}%`"></td>
                            <td class="text-center">
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        @click="hapus(i)" title="Hapus baris">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="small text-muted mt-2">
            SPH Act, CT Act, dan % di atas adalah pratinjau. Angka yang tersimpan dihitung ulang di server.
        </div>

        {{-- ══ SUBMIT ══ --}}
        <form method="POST" action="{{ route('input.produksi.batch') }}" id="formBatch" class="mt-3">
            @csrf
            <template x-for="(r, i) in rows" :key="r.uid">
                <div>
                    <input type="hidden" :name="`rows[${i}][tanggal_produksi]`" :value="r.tanggal_produksi">
                    <input type="hidden" :name="`rows[${i}][id_shift]`" :value="r.id_shift">
                    <input type="hidden" :name="`rows[${i}][id_karyawan]`" :value="r.id_karyawan">
                    <input type="hidden" :name="`rows[${i}][id_mesin]`" :value="r.id_mesin">
                    <input type="hidden" :name="`rows[${i}][id_part_proses]`" :value="r.id_part_proses">
                    <input type="hidden" :name="`rows[${i}][jam_start]`" :value="r.jam_start">
                    <input type="hidden" :name="`rows[${i}][jam_finish]`" :value="r.jam_finish">
                    <input type="hidden" :name="`rows[${i}][qty_aktual]`" :value="r.qty_aktual">
                    <input type="hidden" :name="`rows[${i}][ng_count]`" :value="r.ng.length">
                    <input type="hidden" :name="`rows[${i}][ada_ng]`" :value="r.ng.length > 0 ? 1 : 0">
                    <template x-for="(n, j) in r.ng" :key="j">
                        <div>
                            <input type="hidden" :name="`rows[${i}][ng][${j}][id_jenis_ng]`" :value="n.id_jenis_ng">
                            <input type="hidden" :name="`rows[${i}][ng][${j}][qty]`" :value="n.qty">
                        </div>
                    </template>
                    <input type="hidden" :name="`rows[${i}][dandori_count]`" :value="r.dandori.length">
                    <input type="hidden" :name="`rows[${i}][ada_dandori]`" :value="r.dandori.length > 0 ? 1 : 0">
                    <input type="hidden" :name="`rows[${i}][dandori_karyawan_id]`" :value="r.dandori_karyawan_id">
                    <template x-for="(d, j) in r.dandori" :key="j">
                        <div>
                            <input type="hidden" :name="`rows[${i}][dandori][${j}][id_losstime]`" :value="d.id_losstime">
                            <input type="hidden" :name="`rows[${i}][dandori][${j}][actual_menit]`" :value="d.actual_menit">
                        </div>
                    </template>
                </div>
            </template>

            <div class="d-flex gap-2 justify-content-end">
                <button type="submit" class="btn btn-success" :disabled="rows.length === 0">
                    <i class="bi bi-upload me-1"></i>SUBMIT DRAFT
                </button>
                <button type="button" class="btn btn-danger" @click="batal()">CANCEL</button>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taKaryawan  = @json($_taKaryawan);
const taMesin     = @json($_taMesin);
const taParts     = @json($_taParts);
const partsData   = @json($_partsData);
const shiftData   = @json($_shiftData);

/* Part memilih part sekaligus mengisi Part No dan daftar prosesnya. */
function partPicker() {
    return {
        query: '', val: null, open: false, idx: -1,
        partNo: '', partName: '', prosesList: [],

        get tersaring() {
            if (!this.query.trim()) return taParts.slice(0, 60);
            const q = this.query.toLowerCase();
            return taParts.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pilih(item) {
            this.query = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part = partsData.find(p => p.id == item.v);
            this.partNo     = part ? part.part_no : '';
            this.partName   = part ? part.part_name : '';
            this.prosesList = part ? part.proses : [];
        },
        tutup() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !taParts.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = ''; this.partNo = ''; this.partName = '';
                    this.prosesList = [];
                }
            }, 180);
        },
        tombol(e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx + 1, this.tersaring.length - 1); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); this.idx = Math.max(this.idx - 1, 0); }
            else if (e.key === 'Enter') { e.preventDefault(); if (this.idx >= 0 && this.tersaring[this.idx]) this.pilih(this.tersaring[this.idx]); }
            else if (e.key === 'Escape') { this.open = false; }
        },
    };
}

function lhpBatch() {
    /* Kunci dipisah per pengguna: komputer di lantai produksi dipakai
       bergantian, dan kunci bersama membuat draft operator A muncul di layar
       operator B. */
    const KUNCI = 'optima_draft_lhp_produksi_u{{ auth()->id() }}';
    let seq = 0;

    return {
        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],

        mulai() {
            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah) {
                    this.rows = JSON.parse(mentah).map(r => ({ ...r, uid: seq++ }));
                    if (this.rows.length) this.pesan = `${this.rows.length} baris draft dipulihkan.`;
                }
            } catch (e) {
                localStorage.removeItem(KUNCI);
            }
            this.$watch('rows', () => localStorage.setItem(KUNCI, JSON.stringify(this.rows)));

            /* Server menolak batch → tandai barisnya, draft JANGAN dibuang. */
            const gagal = @json($errors->keys());
            this.barisGagal = gagal
                .map(k => (k.match(/^rows\.(\d+)\./) || [])[1])
                .filter(v => v !== undefined)
                .map(Number);
        },

        form() { return document.getElementById('formBaris'); },

        nilai(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            return el ? el.value : '';
        },

        teksTerpilih(nama) {
            const el = this.form().querySelector(`[name="${nama}"]`);
            if (!el || el.tagName !== 'SELECT') return '';
            return el.selectedOptions[0] ? el.selectedOptions[0].textContent.trim() : '';
        },

        blok(kelas) {
            const el = this.form().querySelector(kelas);
            return el ? Alpine.$data(el) : null;
        },

        picker() {
            return Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));
        },

        detik(jam) {
            const [h, m] = (jam || '0:0').split(':').map(Number);
            return (h * 3600) + (m * 60);
        },

        durasiDetik(mulai, selesai) {
            const a = this.detik(mulai), b = this.detik(selesai);
            return b >= a ? b - a : (86400 - a) + b;
        },

        /* Rentang mutlak, supaya baris yang melewati tengah malam ikut benar. */
        rentang(r) {
            const mulai = Date.parse(`${r.tanggal_produksi}T${r.jam_start}:00`);
            return { mulai, selesai: mulai + (this.durasiDetik(r.jam_start, r.jam_finish) * 1000) };
        },

        bentrokDengan(calon) {
            for (let i = 0; i < this.rows.length; i++) {
                const r = this.rows[i];
                if (r.id_mesin != calon.id_mesin) continue;
                const a = this.rentang(r), b = this.rentang(calon);
                if (a.mulai < b.selesai && b.mulai < a.selesai) return i;
            }
            return -1;
        },

        simpanKeDraft() {
            this.galat = '';
            const picker = this.picker();

            const calon = {
                uid: seq++,
                tanggal_produksi: this.nilai('tanggal_produksi'),
                id_shift: this.nilai('id_shift'),
                shift_teks: this.teksTerpilih('id_shift'),
                id_karyawan: this.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == this.nilai('id_karyawan')) || {}).t || '',
                id_mesin: this.nilai('id_mesin'),
                mesin_teks: (taMesin.find(m => m.v == this.nilai('id_mesin')) || {}).t || '',
                id_part_proses: this.nilai('id_part_proses'),
                proses_teks: this.teksTerpilih('id_part_proses'),
                part_no: picker.partNo,
                part_name: picker.partName,
                jam_start: this.nilai('jam_start'),
                jam_finish: this.nilai('jam_finish'),
                qty_aktual: this.nilai('qty_aktual'),
                ng: (this.blok('.ng-rows') || { rows: [] }).rows.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
                dandori: (this.blok('.dandori-rows') || { rows: [] }).rows.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
                dandori_karyawan_id: this.nilai('dandori_karyawan_id'),
            };

            const wajib = {
                tanggal_produksi: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
                id_mesin: 'Mesin', id_part_proses: 'Proses', jam_start: 'Jam Start',
                jam_finish: 'Jam Finish', qty_aktual: 'Qty OK',
            };
            for (const [kunci, label] of Object.entries(wajib)) {
                if (!calon[kunci]) { this.galat = `${label} wajib diisi.`; return; }
            }
            if (Number(calon.qty_aktual) < 1) { this.galat = 'Qty OK minimal 1.'; return; }
            if (this.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                this.galat = 'Jam Finish tidak boleh sama dengan Jam Start.'; return;
            }

            const bentrok = this.bentrokDengan(calon);
            if (bentrok >= 0) {
                this.galat = `Jam tumpang tindih dengan baris ${bentrok + 1} pada mesin dan tanggal yang sama.`;
                return;
            }

            /* Pratinjau; server yang menghitung angka yang tersimpan. */
            const jamKerja = this.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_produksi : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;

            this.rows.push(calon);
            this.pesan = '';
            this.barisGagal = [];
            this.resetSebagian();
        },

        /* Tanggal, Shift, Operator, dan Mesin sengaja dipertahankan: keempatnya
           konstan sepanjang satu sesi kerja. */
        resetSebagian() {
            const f = this.form();
            ['jam_start', 'jam_finish', 'qty_aktual'].forEach(n => {
                const el = f.querySelector(`[name="${n}"]`);
                if (el) el.value = '';
            });

            const picker = this.picker();
            picker.query = ''; picker.val = null; picker.partNo = '';
            picker.partName = ''; picker.prosesList = [];

            const proses = f.querySelector('[name="id_part_proses"]');
            if (proses) proses.value = '';

            ['ada_ng', 'ada_dandori'].forEach(id => {
                const cb = document.getElementById(id);
                if (!cb) return;
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                Alpine.$data(cb.closest('[x-data]'))[id === 'ada_ng' ? 'adaNg' : 'adaDandori'] = false;
            });

            const ng = this.blok('.ng-rows'); if (ng) ng.rows = [];
            const dandori = this.blok('.dandori-rows'); if (dandori) dandori.rows = [];
        },

        hapus(i) { this.rows.splice(i, 1); this.barisGagal = []; },

        ringkasNg(r) {
            if (!r.ng.length) return '—';
            return r.ng.map(n => `#${n.id_jenis_ng} ×${n.qty}`).join(', ');
        },

        ringkasDandori(r) {
            if (!r.dandori.length) return '—';
            return r.dandori.map(d => `#${d.id_losstime} ${d.actual_menit}mnt`).join(', ');
        },

        batal() {
            if (this.rows.length && !confirm(`Buang ${this.rows.length} baris draft yang belum dikirim?`)) return;
            localStorage.removeItem(KUNCI);
            window.location = '{{ route('input.produksi.index') }}';
        },
    };
}
</script>
@endpush
