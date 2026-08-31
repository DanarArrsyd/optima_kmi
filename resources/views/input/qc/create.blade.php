@extends('layouts.app')

@section('title', 'Input LHP Quality')

@php
    $_taInspector = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $parts->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->proses->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
            'sph_standar_qc' => (float) $pr->sph_standar_qc,
        ])->values(),
    ])->values();
    $_ngLabel = $jenisNg->mapWithKeys(fn ($j) => [$j->id => $j->nama_ng]);
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
<div class="erp-panel" x-data="batchDraft(konfigQc())" x-init="mulai()">
    <div class="erp-panel-header">Input LHP Quality</div>
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
             field — sama susunannya dengan input/produksi/create.blade.php. --}}
        <form id="formBaris" novalidate @submit.prevent="simpanKeDraft()">

            <div class="lhp-baris">
                <label for="f_tgl_cek">Tanggal Pengecekan <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="date" id="f_tgl_cek" name="tgl_pengecekan" class="form-control"
                           value="{{ now()->format('Y-m-d') }}">
                </div>
            </div>

            <div class="lhp-baris">
                <label for="f_tgl_produksi">Tanggal Produksi Part <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="date" id="f_tgl_produksi" name="tgl_produksi_part" class="form-control"
                           value="{{ now()->subDay()->format('Y-m-d') }}">
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

            <div class="lhp-baris" x-data="taField(taInspector)">
                <label for="f_inspector">QC Inspector <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <div class="ta-wrap">
                        <input type="text" id="f_inspector" class="form-control ta-input" x-model="query"
                               @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik nama QC inspector...">
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
                </div>
            </div>

            {{-- Part, Part No., dan Proses WAJIB satu scope partPicker():
                 prosesList milik komponen itu. QC hanya memeriksa finish good
                 (proses terakhir), jadi tidak ada <select> Proses di sini —
                 hidden input id_part_proses mengambil prosesList[0] langsung. --}}
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
                    <label for="qcPartNoTampil">Part No.</label>
                    <div class="lhp-isi">
                        <input type="text" class="form-control" id="qcPartNoTampil" :value="partNo" readonly
                               placeholder="Terisi otomatis dari Part Name">
                    </div>
                </div>

                <div class="lhp-baris">
                    <label>Proses Finish Good</label>
                    <div class="lhp-isi">
                        <div class="form-control bg-light" style="min-height:38px;">
                            <template x-if="prosesList[0]">
                                <span x-text="`${prosesList[0].id_proses} – ${prosesList[0].nama_proses} (SPH Std QC: ${prosesList[0].sph_standar_qc})`"></span>
                            </template>
                            <template x-if="!prosesList[0]">
                                <span class="text-muted">-- Pilih Part dulu --</span>
                            </template>
                        </div>
                        <input type="hidden" name="id_part_proses" :value="prosesList[0] ? prosesList[0].id : ''">
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
                    <input type="number" id="f_qty" name="qty_ok" class="form-control" min="0">
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
            </div>

            <template x-if="galat">
                <div class="alert alert-danger py-2 mt-3 mb-0" x-text="galat"></div>
            </template>

            <div class="d-flex justify-content-end gap-2 mt-3">
                <button type="button" class="btn btn-outline-secondary" x-show="editIndex !== null" x-cloak
                        @click="batalEdit()">
                    Batal Edit
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="bi me-1" :class="editIndex !== null ? 'bi-pencil-fill' : 'bi-download'"></i>
                    <span x-text="editIndex !== null ? 'UPDATE BARIS' : 'SAVE TO DRAFT'"></span>
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
                        <th>Tgl Cek</th><th>Tgl Produksi</th><th>Shift</th><th>Inspector</th>
                        <th>Part No.</th><th>Part Name</th><th>Proses</th><th>Jam</th>
                        <th>Qty OK</th><th>Qty NG</th><th>Jenis NG</th>
                        <th>SPH Std QC</th><th>SPH Act</th><th>W. Kerja</th><th>CT Act</th><th>%</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="17" class="text-center text-muted py-3">
                            Belum ada baris draft. Isi form di atas lalu klik SAVE TO DRAFT.
                        </td></tr>
                    </template>
                    <template x-for="(r, i) in rows" :key="r.uid">
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
                            <td x-text="r.tgl_pengecekan"></td>
                            <td x-text="r.tgl_produksi_part"></td>
                            <td x-text="r.shift_teks"></td>
                            <td x-text="r.inspector_teks"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="`${r.jam_start}–${r.jam_finish}`"></td>
                            <td x-text="r.qty_ok"></td>
                            <td x-text="r.ng.reduce((s, n) => s + Number(n.qty || 0), 0) || '—'"></td>
                            <td x-text="ringkasNg(r)"></td>
                            <td x-text="r.sph_std.toFixed(0)"></td>
                            <td x-text="r.sph_act.toFixed(0)"></td>
                            <td x-text="r.jam_kerja.toFixed(2)"></td>
                            <td x-text="r.ct_act.toFixed(2)"></td>
                            <td x-text="`${r.persen.toFixed(0)}%`"></td>
                            <td class="text-center text-nowrap">
                                <button type="button" class="btn btn-sm btn-outline-secondary me-1"
                                        :disabled="i === editIndex" @click="edit(i)" title="Edit baris">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger"
                                        :disabled="i === editIndex" @click="hapus(i)" title="Hapus baris">
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
        <form method="POST" action="{{ route('input.qc.batch') }}" id="formBatch" class="mt-3"
              @submit="pindahkanKeBackup()">
            @csrf
            {{-- 50 baris (batas server) × field flat bisa melewati max_input_vars
                 bawaan PHP (1000), yang memotong $_POST diam-diam. Dikirim
                 sebagai satu field JSON supaya jumlah baris tidak lagi terikat
                 batas variabel input sama sekali. --}}
            <input type="hidden" name="rows_json" :value="JSON.stringify(payloadRows())">

            <div class="d-flex gap-2 justify-content-end">
                <span class="text-muted small align-self-center" x-show="editIndex !== null" x-cloak>
                    Selesaikan UPDATE BARIS atau Batal Edit dulu sebelum submit.
                </span>
                <button type="submit" class="btn btn-success" :disabled="rows.length === 0 || editIndex !== null">
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
const taInspector = @json($_taInspector);
const taParts     = @json($_taParts);
const partsData   = @json($_partsData);
const ngLabel     = @json($_ngLabel);

function konfigQc() {
    const picker = () => Alpine.$data(document.getElementById('qcPartNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhp_qc_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhp_qc_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.qc.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tgl_pengecekan: 'Tanggal Pengecekan',
            tgl_produksi_part: 'Tanggal Produksi Part',
            id_shift: 'Shift',
            id_karyawan_qc: 'QC Inspector',
            id_part_proses: 'Part',
            jam_start: 'Jam Start',
            jam_finish: 'Jam Finish',
            qty_ok: 'Qty OK',
        },

        kumpulkan(diri) {
            const p = picker();
            const proses = p.prosesList[0] || null;

            return {
                tgl_pengecekan: diri.nilai('tgl_pengecekan'),
                tgl_produksi_part: diri.nilai('tgl_produksi_part'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan_qc: diri.nilai('id_karyawan_qc'),
                inspector_teks: (taInspector.find(k => k.v == diri.nilai('id_karyawan_qc')) || {}).t || '',
                id_part_proses: proses ? proses.id : '',
                proses_teks: proses ? `${proses.id_proses} – ${proses.nama_proses}` : '',
                sph_std: proses ? Number(proses.sph_standar_qc) : 0,
                part_no: p.partNo,
                part_name: p.partName,
                jam_start: diri.nilai('jam_start'),
                jam_finish: diri.nilai('jam_finish'),
                qty_ok: diri.nilai('qty_ok'),
                ng: (diri.blok('.ng-rows') || { rows: [] }).rows.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
            };
        },

        periksa(calon, rows, diri) {
            /* Aturan yang sama dengan server: total qty check harus > 0.
               Qty OK boleh 0 ASAL ada NG — barang diperiksa lalu semuanya
               ditolak adalah kejadian normal, bukan salah ketik. */
            const qtyNg = calon.ng.reduce((s, n) => s + Number(n.qty || 0), 0);
            if (Number(calon.qty_ok || 0) + qtyNg === 0) {
                return 'Total qty check (OK + NG) harus lebih dari 0.';
            }
            if (diri.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                return 'Jam Finish tidak boleh sama dengan Jam Start.';
            }

            /* Nol pemeriksaan tabrakan jam: dua inspector boleh memeriksa part
               yang sama di jam yang sama. Beda dari produksi, yang satu mesin
               tidak bisa menjalankan dua baris sekaligus. */
            return null;
        },

        /* Pratinjau; server yang menghitung angka yang tersimpan. */
        pratinjau(calon, diri) {
            const jamKerja = diri.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const qtyNg = calon.ng.reduce((s, n) => s + Number(n.qty || 0), 0);
            const total = Number(calon.qty_ok || 0) + qtyNg;

            calon.jam_kerja = jamKerja;
            calon.sph_act = jamKerja > 0 ? total / jamKerja : 0;
            calon.ct_act = total > 0 ? (jamKerja * 3600) / total : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        /* Tanggal, Shift, dan Inspector sengaja TIDAK di sini: ketiganya
           konstan sepanjang satu sesi pengecekan. */
        resetField: ['jam_start', 'jam_finish', 'qty_ok'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const cb = document.getElementById('ada_ng');
            if (cb) {
                if (cb.checked) { cb.checked = false; cb.dispatchEvent(new Event('change', { bubbles: true })); }
                Alpine.$data(cb.closest('[x-data]')).adaNg = false;
            }

            const ng = diri.blok('.ng-rows'); if (ng) ng.rows = [];
        },

        /* Kebalikan cfg.kumpulkan. Beda dari produksi: QC nol <select> Proses
           manual — id_part_proses hidden input reaktif ke prosesList[0], jadi
           cukup p.pilih() dan proses ikut terisi sendiri tanpa Alpine.nextTick. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tgl_pengecekan"]').value = row.tgl_pengecekan;
            f.querySelector('[name="tgl_produksi_part"]').value = row.tgl_produksi_part;
            f.querySelector('[name="id_shift"]').value = row.id_shift;
            f.querySelector('[name="jam_start"]').value = row.jam_start;
            f.querySelector('[name="jam_finish"]').value = row.jam_finish;
            f.querySelector('[name="qty_ok"]').value = row.qty_ok;

            const inspector = Alpine.$data(document.getElementById('f_inspector').closest('[x-data]'));
            inspector.pick({ v: row.id_karyawan_qc, t: row.inspector_teks });

            const p = picker();
            const dataPart = partsData.find(p => p.proses.some(pr => pr.id == row.id_part_proses));
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
            } else {
                p.query = ''; p.val = null; p.partNo = ''; p.partName = ''; p.prosesList = [];
            }

            const ngCb = document.getElementById('ada_ng');
            Alpine.$data(ngCb.closest('[x-data]')).adaNg = row.ng.length > 0;
            Alpine.nextTick(() => {
                const ng = diri.blok('.ng-rows');
                if (ng) ng.setRows(row.ng);
            });
        },

        payload(r) {
            return {
                tgl_pengecekan: r.tgl_pengecekan,
                tgl_produksi_part: r.tgl_produksi_part,
                id_shift: r.id_shift,
                id_karyawan_qc: r.id_karyawan_qc,
                id_part_proses: r.id_part_proses,
                jam_start: r.jam_start,
                jam_finish: r.jam_finish,
                qty_ok: r.qty_ok,
                ng_count: r.ng.length,
                ng: r.ng.map(n => ({ id_jenis_ng: n.id_jenis_ng, qty: n.qty })),
            };
        },

        metode: {
            ringkasNg(r) {
                if (!r.ng.length) return '—';
                return r.ng.map(n => `${ngLabel[n.id_jenis_ng] ?? n.id_jenis_ng} ×${n.qty}`).join(', ');
            },
        },
    };
}
</script>
@endpush
