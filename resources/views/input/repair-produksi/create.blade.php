@extends('layouts.app')

@section('title', 'Input LHR Produksi')

@php
    $_taKaryawan = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $parts->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->prosesRepairProduksi->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
            'sph_standar_repair_produksi' => (float) $pr->sph_standar_repair_produksi,
        ])->values(),
    ])->values();
@endphp

@push('styles')
<style>
.lhp-baris { display: flex; align-items: center; gap: 12px; margin-bottom: 10px; }
.lhp-baris > label { flex: 0 0 150px; margin: 0; font-size: 0.875rem; color: #374151; }
.lhp-isi { flex: 0 1 640px; min-width: 0; }
.lhp-waktu { display: flex; align-items: center; gap: 10px; }
.lhp-waktu > .form-control { flex: 1 1 0; min-width: 0; }
.lhp-to { flex: 0 0 auto; font-size: 0.875rem; color: #6b7280; }
.lhp-draft { width: auto; max-width: none; }
.lhp-draft th, .lhp-draft td { white-space: nowrap; }
@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }
}
</style>
@endpush

@section('content')
<div class="erp-panel" x-data="batchDraft(konfigRepairProduksi())" x-init="mulai()">
    <div class="erp-panel-header">Input LHR Produksi (Laporan Harian Repair Produksi)</div>
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

        <form id="formBaris" novalidate @submit.prevent="simpanKeDraft()">

            <div class="lhp-baris">
                <label for="f_tanggal">Tanggal Repair <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="date" id="f_tanggal" name="tanggal_repair_produksi" class="form-control"
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
                    <label for="f_proses">Proses Repair Produksi <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <select id="f_proses" name="id_part_proses_repair_produksi" class="form-select"
                                :disabled="prosesList.length === 0">
                            <option value="">-- Pilih Part dulu --</option>
                            <template x-for="p in prosesList" :key="p.id">
                                <option :value="p.id"
                                        x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_repair_produksi})`">
                                </option>
                            </template>
                        </select>
                        <small class="text-muted" x-show="prosesList.length === 0 && partNo" x-cloak>
                            Belum ada proses repair produksi untuk part ini, tambah di Master Data &raquo; Proses Repair Produksi dulu.
                        </small>
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
                <label for="f_qty">Qty Act Repair <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="number" id="f_qty" name="qty_aktual" class="form-control" min="1">
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

        <div class="fw-semibold mb-2">DRAFT TERSIMPAN <span x-text="`(${rows.length})`"></span></div>

        <div class="table-responsive">
            <table class="table table-bordered table-sm align-middle mb-0 lhp-draft">
                <thead class="table-light">
                    <tr>
                        <th>Tanggal</th><th>Shift</th><th>Operator</th>
                        <th>Part No.</th><th>Part Name</th><th>Proses</th><th>Jam</th>
                        <th>Qty Act</th><th>SPH Std</th><th>SPH Act</th><th>W. Kerja</th><th>CT Act</th><th>%</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="14" class="text-center text-muted py-3">
                            Belum ada baris draft. Isi form di atas lalu klik SAVE TO DRAFT.
                        </td></tr>
                    </template>
                    <template x-for="(r, i) in rows" :key="r.uid">
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
                            <td x-text="r.tanggal_repair_produksi"></td>
                            <td x-text="r.shift_teks"></td>
                            <td x-text="r.karyawan_teks"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="`${r.jam_start}–${r.jam_finish}`"></td>
                            <td x-text="r.qty_aktual"></td>
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

        <form method="POST" action="{{ route('input.repair-produksi.batch') }}" id="formBatch" class="mt-3"
              @submit="pindahkanKeBackup()">
            @csrf
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
const taKaryawan = @json($_taKaryawan);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);

function konfigRepairProduksi() {
    const picker = () => Alpine.$data(document.getElementById('partNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_lhr_repair_produksi_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_lhr_repair_produksi_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.repair-produksi.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tanggal_repair_produksi: 'Tanggal', id_shift: 'Shift', id_karyawan: 'Operator',
            id_part_proses_repair_produksi: 'Proses Repair Produksi', jam_start: 'Jam Start',
            jam_finish: 'Jam Finish', qty_aktual: 'Qty Act Repair',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                tanggal_repair_produksi: diri.nilai('tanggal_repair_produksi'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan: diri.nilai('id_karyawan'),
                karyawan_teks: (taKaryawan.find(k => k.v == diri.nilai('id_karyawan')) || {}).t || '',
                id_part_proses_repair_produksi: diri.nilai('id_part_proses_repair_produksi'),
                proses_teks: diri.teksTerpilih('id_part_proses_repair_produksi'),
                part_no: p.partNo,
                part_name: p.partName,
                jam_start: diri.nilai('jam_start'),
                jam_finish: diri.nilai('jam_finish'),
                qty_aktual: diri.nilai('qty_aktual'),
            };
        },

        periksa(calon, rows, diri) {
            if (Number(calon.qty_aktual) < 1) return 'Qty Act Repair minimal 1.';
            if (diri.durasiDetik(calon.jam_start, calon.jam_finish) === 0) {
                return 'Jam Finish tidak boleh sama dengan Jam Start.';
            }

            return null;
        },

        pratinjau(calon, diri) {
            const jamKerja = diri.durasiDetik(calon.jam_start, calon.jam_finish) / 3600;
            const proses = partsData.flatMap(p => p.proses).find(p => p.id == calon.id_part_proses_repair_produksi);
            calon.jam_kerja = jamKerja;
            calon.sph_std = proses ? proses.sph_standar_repair_produksi : 0;
            calon.sph_act = jamKerja > 0 ? Number(calon.qty_aktual) / jamKerja : 0;
            calon.ct_act = Number(calon.qty_aktual) > 0 ? (jamKerja * 3600) / Number(calon.qty_aktual) : 0;
            calon.persen = calon.sph_std > 0 ? (calon.sph_act / calon.sph_std) * 100 : 0;
        },

        resetField: ['jam_start', 'jam_finish', 'qty_aktual'],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses_repair_produksi"]');
            if (proses) proses.value = '';
        },

        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tanggal_repair_produksi"]').value = row.tanggal_repair_produksi;
            f.querySelector('[name="id_shift"]').value = row.id_shift;
            f.querySelector('[name="jam_start"]').value = row.jam_start;
            f.querySelector('[name="jam_finish"]').value = row.jam_finish;
            f.querySelector('[name="qty_aktual"]').value = row.qty_aktual;

            const operator = Alpine.$data(document.getElementById('f_operator').closest('[x-data]'));
            operator.pick({ v: row.id_karyawan, t: row.karyawan_teks });

            const p = picker();
            const dataPart = partsData.find(dp => dp.proses.some(pr => pr.id == row.id_part_proses_repair_produksi));
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses_repair_produksi"]');
                    if (proses) proses.value = row.id_part_proses_repair_produksi;
                });
            } else {
                p.query = ''; p.val = null; p.partNo = ''; p.partName = ''; p.prosesList = [];
                const proses = f.querySelector('[name="id_part_proses_repair_produksi"]');
                if (proses) proses.value = '';
            }
        },

        payload(r) {
            return {
                tanggal_repair_produksi: r.tanggal_repair_produksi,
                id_shift: r.id_shift,
                id_karyawan: r.id_karyawan,
                id_part_proses_repair_produksi: r.id_part_proses_repair_produksi,
                jam_start: r.jam_start,
                jam_finish: r.jam_finish,
                qty_aktual: r.qty_aktual,
            };
        },
    };
}
</script>
@endpush
