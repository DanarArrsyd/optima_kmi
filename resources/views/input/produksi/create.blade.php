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
    $_ngLabel = $jenisNg->mapWithKeys(fn ($j) => [$j->id => $j->nama_ng]);
    $_ltLabel = $losstimes->mapWithKeys(fn ($l) => [$l->id => $l->jenis_losstime]);
@endphp

@push('styles')
<style>
/* Baris form LHP: label kiri, kotak input SATU lebar untuk semua field.
   Lebar seragam disengaja — Part Name di pabrik ini panjang, dan kotak
   yang lebarnya berbeda-beda membuat mata operator melompat tiap baris. */
.lhp-baris {
    display: flex;
    align-items: center;
    gap: 12px;
    margin-bottom: 10px;
}
.lhp-baris > label {
    flex: 0 0 150px;
    margin: 0;
    font-size: 0.875rem;
    color: #374151;
}
.lhp-isi {
    flex: 0 1 640px;
    min-width: 0;
}
.lhp-waktu {
    display: flex;
    align-items: center;
    gap: 10px;
}
.lhp-waktu > .form-control { flex: 1 1 0; min-width: 0; }
.lhp-to { flex: 0 0 auto; font-size: 0.875rem; color: #6b7280; }

/* Di layar sempit label naik ke atas kotaknya, bukan menggencet kotaknya. */
@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }
}
</style>
@endpush

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

        {{-- ══ FORM SATU BARIS ══
             Satu kolom ke bawah, label kiri, kotak input SATU lebar untuk semua
             field. Susunan empat kolom sebelumnya memotong Part Name yang panjang
             (nama part di pabrik ini bisa "BRACE COMP LEG SHIELD ..."), dan lebar
             kotak yang berbeda-beda bikin mata operator melompat tiap baris. --}}
        <form id="formBaris" novalidate @submit.prevent="simpanKeDraft()">

            <div class="lhp-baris">
                <label for="f_tanggal">Tanggal Produksi <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="date" id="f_tanggal" name="tanggal_produksi" class="form-control"
                           value="{{ now()->format('Y-m-d') }}">
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_shift">Shift <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <select id="f_shift" name="id_shift" class="form-select">
                        <option value="">-- Pilih Shift --</option>
                        @foreach ($shifts as $s)
                            <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div class="lhp-baris" x-data="taField(taKaryawan)">
                <label for="f_operator">Operator <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <div class="ta-wrap">
                        <input type="text" id="f_operator" class="form-control ta-input" x-model="query"
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
            </div>

            <div class="lhp-baris" x-data="taField(taMesin)">
                <label for="f_mesin">Mesin <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <div class="ta-wrap">
                        <input type="text" id="f_mesin" class="form-control ta-input" x-model="query"
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
            </div>

            {{-- Part, Part No., dan Proses WAJIB satu scope partPicker():
                 prosesList milik komponen itu. Pernah dipisah jadi bersebelahan —
                 daftar proses tak pernah terisi dan nol baris bisa disimpan. --}}
            <div x-data="partPicker()">
                <div class="lhp-baris">
                    <label for="f_part">Part Name <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <div class="ta-wrap">
                            <input type="text" id="f_part" class="form-control ta-input" x-model="query"
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
                    </div>
                </div>

                <div class="lhp-baris">
                    <label for="partNoTampil">Part No.</label>
                    <div class="lhp-isi">
                        <input type="text" class="form-control" id="partNoTampil" :value="partNo" readonly
                               placeholder="Terisi otomatis dari Part Name">
                    </div>
                </div>

                <div class="lhp-baris">
                    <label for="f_proses">Proses <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <select id="f_proses" name="id_part_proses" class="form-select"
                                :disabled="prosesList.length === 0">
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
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_jam_start">Waktu Kerja <span class="text-danger">*</span></label>
                <div class="lhp-isi lhp-waktu">
                    <input type="time" id="f_jam_start" name="jam_start" class="form-control">
                    <span class="lhp-to">To</span>
                    <input type="time" id="f_jam_finish" name="jam_finish" class="form-control">
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_qty">Qty OK <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="number" id="f_qty" name="qty_aktual" class="form-control" min="1">
                </div>
            </div>

            <div class="row g-3 mt-1">
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
        <form method="POST" action="{{ route('input.produksi.batch') }}" id="formBatch" class="mt-3"
              @submit="pindahkanKeBackup()">
            @csrf
            {{-- 50 baris (batas server) × field flat (13 pokok + hingga 4 NG
                 + 4 dandori) bisa melewati max_input_vars bawaan PHP (1000).
                 PHP memotong $_POST diam-diam kalau itu terjadi — cuma
                 menulis warning ke log, sementara batch tampak ditolak tanpa
                 alasan yang bisa ditindaklanjuti operator, atau malah
                 diterima tapi terpotong pas di batas baris. Dikirim sebagai
                 satu field JSON supaya jumlah baris tidak lagi terikat
                 batas variabel input sama sekali. --}}
            <input type="hidden" name="rows_json" :value="JSON.stringify(payloadRows())">

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
const ngLabel     = @json($_ngLabel);
const ltLabel     = @json($_ltLabel);

/* Halaman yang dipulihkan dari bfcache (tombol Back setelah submit sukses)
   masih menampilkan baris-baris yang SUDAH tersimpan di server — x-init
   "mulai()" tidak jalan lagi karena Alpine tidak di-reinit, dan tombol
   SUBMIT tetap aktif. Mengirim ulang baris yang sama menulis LHP dobel;
   tidak ada unique constraint di transaksi_produksi yang mencegahnya. Muat
   ulang supaya mulai() benar-benar jalan lagi dan memutuskan nasib draft
   dari kondisi server yang sebenarnya. */
window.addEventListener('pageshow', (e) => {
    if (e.persisted) window.location.reload();
});

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

    /* Kunci cadangan: SUBMIT DRAFT adalah POST form biasa yang berakhir di
       redirect server, jadi tidak ada kesempatan JS menghapus KUNCI tepat
       saat sukses. Sebaliknya, submit memindahkan isi KUNCI ke sini dulu
       (lihat pindahkanKeBackup()) lalu mengosongkan KUNCI.
       Draft di sini hanya hidup di browser, jadi hanya boleh dibuang kalau
       server sudah MEMBUKTIKAN baris-barisnya tersimpan — bukan sekadar
       ketiadaan galat validasi di halaman ini. Bukti itu adalah flash
       batch_tersimpan yang dibaca dan dieksekusi di index.blade.php
       (lihat skrip di sana): index-lah yang menghapus KUNCI_BACKUP ini.
       Kalau operator kembali ke SINI dan backup masih ada — baik karena
       galat validasi, 419 sesi habis, 500 di server, maupun jaringan
       putus sebelum POST-nya mendarat — submit itu belum terbukti sukses,
       jadi mulai() memulihkannya, bukan membuangnya. */
    const KUNCI_BACKUP = 'optima_draft_lhp_produksi_terkirim_u{{ auth()->id() }}';
    let seq = 0;

    return {
        rows: [],
        pesan: '',
        galat: '',
        barisGagal: [],

        mulai() {
            /* Selesaikan nasib KUNCI_BACKUP (kalau ada) SEBELUM membaca KUNCI,
               supaya restorasi normal di bawah otomatis mengambil hasilnya.
               Sampai di SINI berarti index.blade.php belum sempat membuktikan
               sukses (lihat komentar di deklarasi KUNCI_BACKUP) — jadi backup
               SELALU dipulihkan, tanpa syarat ada/tidaknya galat validasi. */
            try {
                const backupMentah = localStorage.getItem(KUNCI_BACKUP);
                if (backupMentah !== null) {
                    localStorage.setItem(KUNCI, backupMentah);
                    localStorage.removeItem(KUNCI_BACKUP);
                }
            } catch (e) {
                // localStorage tak bisa dibaca/ditulis; lewati saja.
            }

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

        /* Bentuk field yang dikirim ke server (rows_json) — sama persis
           dengan yang dulu dirakit lewat input tersembunyi per baris,
           termasuk ng_count/dandori_count yang dihitung terpisah dari
           panjang array ng/dandori (lihat komentar periksaHitungan() di
           StoreProduksiBatchRequest soal kenapa itu penting). */
        payloadRows() {
            return this.rows.map(r => ({
                tanggal_produksi: r.tanggal_produksi,
                id_shift: r.id_shift,
                id_karyawan: r.id_karyawan,
                id_mesin: r.id_mesin,
                id_part_proses: r.id_part_proses,
                jam_start: r.jam_start,
                jam_finish: r.jam_finish,
                qty_aktual: r.qty_aktual,
                ng_count: r.ng.length,
                ada_ng: r.ng.length > 0 ? 1 : 0,
                ng: r.ng.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
                dandori_count: r.dandori.length,
                ada_dandori: r.dandori.length > 0 ? 1 : 0,
                dandori_karyawan_id: r.dandori_karyawan_id,
                dandori: r.dandori.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
            }));
        },

        ringkasNg(r) {
            if (!r.ng.length) return '—';
            return r.ng.map(n => `${ngLabel[n.id_jenis_ng] ?? n.id_jenis_ng} ×${n.qty}`).join(', ');
        },

        ringkasDandori(r) {
            if (!r.dandori.length) return '—';
            return r.dandori.map(d => `${ltLabel[d.id_losstime] ?? d.id_losstime} ${d.actual_menit}mnt`).join(', ');
        },

        batal() {
            if (this.rows.length && !confirm(`Buang ${this.rows.length} baris draft yang belum dikirim?`)) return;
            localStorage.removeItem(KUNCI);
            window.location = '{{ route('input.produksi.index') }}';
        },

        /* Dipasang di @submit #formBatch. Form ini POST biasa (bukan
           @submit.prevent) — begitu handler ini selesai, browser lanjut
           mengirim form dan halaman ini hilang sebelum JS lain sempat jalan.
           Jadi draft TIDAK bisa langsung dihapus di sini: kalau server nanti
           menolak batch (galat validasi), operator akan diarahkan balik ke
           halaman ini dan berhak mendapatkan ketikannya kembali. Sebagai
           gantinya draft dipindah ke KUNCI_BACKUP dan KUNCI dikosongkan;
           mulai() yang membereskan nasib akhirnya saat halaman ini dibuka
           lagi (lihat komentar di sana). */
        pindahkanKeBackup() {
            try {
                const mentah = localStorage.getItem(KUNCI);
                if (mentah !== null) {
                    /* Kalau setItem(KUNCI_BACKUP) di bawah gagal (mis. kuota
                       localStorage penuh), KUNCI tetap WAJIB dikosongkan di
                       finally — kalau tidak, draft yang baru saja berhasil
                       disimpan ke server akan ditawarkan lagi ke operator dan
                       terkirim dobel. */
                    try {
                        localStorage.setItem(KUNCI_BACKUP, mentah);
                    } finally {
                        localStorage.removeItem(KUNCI);
                    }
                }
            } catch (e) {
                // localStorage tak tersedia; biarkan submit tetap jalan apa adanya.
            }
        },
    };
}
</script>
@endpush
