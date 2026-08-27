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

/* Tabel draft: NOL pembungkusan teks. Lebar tiap kolom mengikuti isi
   terpanjangnya, dan kelebihannya digulir mendatar oleh .table-responsive.
   Bootstrap menyetel .table ke width:100%, yang memaksa kolom saling
   menggencet lalu memotong "BASE JACK BRACKET" jadi dua baris — operator
   memakai tabel ini untuk memeriksa apa yang tadi diketik sebelum SUBMIT,
   jadi isinya harus terbaca dalam satu baris. */
.lhp-draft {
    width: auto;
    max-width: none;
}
.lhp-draft th,
.lhp-draft td {
    white-space: nowrap;
}

/* Di layar sempit label naik ke atas kotaknya, bukan menggencet kotaknya. */
@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }
}
</style>
@endpush

@section('content')
<div class="erp-panel" x-data="batchDraft(konfigLhp())" x-init="mulai()">
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
            <div x-data="partPicker(taParts, partsData)">
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
            <table class="table table-bordered table-sm align-middle mb-0 lhp-draft">
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

function konfigLhp() {
    /* Rentang mutlak, supaya baris yang melewati tengah malam ikut benar.
       durasiDetik() diambil dari batchDraft lewat `diri`, tidak disalin ke
       sini — menyalin pembantu waktu adalah awal dari dua definisi yang
       pelan-pelan menyimpang. */
    const rentang = (diri, r) => {
        const mulai = Date.parse(`${r.tanggal_produksi}T${r.jam_start}:00`);
        return { mulai, selesai: mulai + (diri.durasiDetik(r.jam_start, r.jam_finish) * 1000) };
    };

    const picker = () => Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhp_produksi_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhp_produksi_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.produksi.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tanggal_produksi: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
            id_mesin: 'Mesin', id_part_proses: 'Proses', jam_start: 'Jam Start',
            jam_finish: 'Jam Finish', qty_aktual: 'Qty OK',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                tanggal_produksi: diri.nilai('tanggal_produksi'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan: diri.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == diri.nilai('id_karyawan')) || {}).t || '',
                id_mesin: diri.nilai('id_mesin'),
                mesin_teks: (taMesin.find(m => m.v == diri.nilai('id_mesin')) || {}).t || '',
                id_part_proses: diri.nilai('id_part_proses'),
                proses_teks: diri.teksTerpilih('id_part_proses'),
                part_no: p.partNo,
                part_name: p.partName,
                jam_start: diri.nilai('jam_start'),
                jam_finish: diri.nilai('jam_finish'),
                qty_aktual: diri.nilai('qty_aktual'),
                ng: (diri.blok('.ng-rows') || { rows: [] }).rows.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
                dandori: (diri.blok('.dandori-rows') || { rows: [] }).rows.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
                dandori_karyawan_id: diri.nilai('dandori_karyawan_id'),
            };
        },

        periksa(calon, rows, diri) {
            if (Number(calon.qty_aktual) < 1) return 'Qty OK minimal 1.';
            if (diri.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                return 'Jam Finish tidak boleh sama dengan Jam Start.';
            }

            for (let i = 0; i < rows.length; i++) {
                if (rows[i].id_mesin != calon.id_mesin) continue;
                const a = rentang(diri, rows[i]), b = rentang(diri, calon);
                if (a.mulai < b.selesai && b.mulai < a.selesai) {
                    return `Jam tumpang tindih dengan baris ${i + 1} pada mesin dan tanggal yang sama.`;
                }
            }

            return null;
        },

        /* Pratinjau; server yang menghitung angka yang tersimpan. */
        pratinjau(calon, diri) {
            const jamKerja = diri.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_produksi : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        /* Tanggal, Shift, Operator, dan Mesin sengaja TIDAK di sini:
           keempatnya konstan sepanjang satu sesi kerja. */
        resetField: ['jam_start', 'jam_finish', 'qty_aktual'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses"]');
            if (proses) proses.value = '';

            ['ada_ng', 'ada_dandori'].forEach(id => {
                const cb = document.getElementById(id);
                if (!cb) return;
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                Alpine.$data(cb.closest('[x-data]'))[id === 'ada_ng' ? 'adaNg' : 'adaDandori'] = false;
            });

            const ng = diri.blok('.ng-rows'); if (ng) ng.rows = [];
            const dandori = diri.blok('.dandori-rows'); if (dandori) dandori.rows = [];
        },

        /* Bentuk field yang dikirim ke server (rows_json) — sama persis
           dengan yang dulu dirakit lewat input tersembunyi per baris,
           termasuk ng_count/dandori_count yang dihitung terpisah dari
           panjang array ng/dandori (lihat komentar periksaHitungan() di
           StoreProduksiBatchRequest soal kenapa itu penting). */
        payload(r) {
            return {
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
            };
        },

        metode: {
            ringkasNg(r) {
                if (!r.ng.length) return '—';
                return r.ng.map(n => `${ngLabel[n.id_jenis_ng] ?? n.id_jenis_ng} ×${n.qty}`).join(', ');
            },

            ringkasDandori(r) {
                if (!r.dandori.length) return '—';
                return r.dandori.map(d => `${ltLabel[d.id_losstime] ?? d.id_losstime} ${d.actual_menit}mnt`).join(', ');
            },
        },
    };
}
</script>
@endpush
