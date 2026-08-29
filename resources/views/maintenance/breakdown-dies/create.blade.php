@extends('layouts.app')

@section('title', 'Input Breakdown Dies')

@php
    $_taParts = $partList->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
    $_partsData = $partList->map(fn ($p) => [
        'id' => $p->id,
        'part_no' => $p->part_no,
        'part_name' => $p->part_name,
        'proses' => $p->proses->map(fn ($pr) => [
            'id' => $pr->id,
            'id_proses' => $pr->id_proses,
            'nama_proses' => $pr->nama_proses,
        ])->values(),
    ])->values();
@endphp

@push('styles')
<style>
/* Baris form LHP: label kiri, kotak input SATU lebar untuk semua field.
   Susunan sama dengan maintenance/breakdown/create.blade.php dan
   input/dandori/create.blade.php. */
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

/* Tabel draft: NOL pembungkusan teks — sama alasannya dengan modul lain. */
.lhp-draft {
    width: auto;
    max-width: none;
}
.lhp-draft th,
.lhp-draft td {
    white-space: nowrap;
}

@media (max-width: 767.98px) {
    .lhp-baris { flex-direction: column; align-items: stretch; gap: 4px; }
    .lhp-baris > label { flex: none; }
    .lhp-isi { flex: none; }
}
</style>
@endpush

@section('content')
<div class="erp-panel" x-data="batchDraft(konfigBreakdownDies())" x-init="mulai()">
    <div class="erp-panel-header">Input Breakdown Dies</div>
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

            <div class="lhp-baris">
                <label for="f_nomor_urut">Nomor Urut LKD <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="number" id="f_nomor_urut" name="nomor_urut" min="1" class="form-control"
                           value="{{ $nextNomor }}">
                </div>
            </div>

            {{-- Part, Part No., dan Proses WAJIB satu scope partPicker():
                 prosesList milik komponen itu. Pemilih Proses WAJIB berada di
                 DALAM scope ini — kalau dipindah keluar, Alpine tidak bisa
                 menjangkau prosesList dan halaman tidak bisa menyimpan satu
                 baris pun di peramban walau server-rendered test tetap lulus.
                 Dies memakai SEMUA proses (bukan cuma finish good seperti
                 QC), jadi Proses di sini tetap <select> yang dipilih manual
                 — sama pola dengan input/dandori/create.blade.php. --}}
            <div x-data="partPicker(taParts, partsData)">
                <div class="lhp-baris">
                    <label for="f_part">Part / Dies <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <div class="ta-wrap">
                            <input type="text" id="f_part" class="form-control ta-input" x-model="query"
                                   @focus="open = true; idx = -1" @blur="tutup()" @keydown="tombol($event)"
                                   @input="val = null; open = true; idx = -1"
                                   placeholder="Ketik part no atau nama dies...">
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
                    <label for="diesPartNoTampil">Part No.</label>
                    <div class="lhp-isi">
                        <input type="text" class="form-control" id="diesPartNoTampil" :value="partNo" readonly
                               placeholder="Terisi otomatis dari Part / Dies">
                    </div>
                </div>

                <div class="lhp-baris">
                    <label for="f_proses">Proses <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <select id="f_proses" name="id_part_proses" class="form-select"
                                :disabled="prosesList.length === 0">
                            <option value="">-- Pilih Part dulu --</option>
                            <template x-for="p in prosesList" :key="p.id">
                                <option :value="p.id" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>

            @php $pasangan = [
                ['tanggal_breakdown', 'jam_breakdown', 'Breakdown'],
                ['tanggal_mulai_perbaikan', 'jam_mulai_perbaikan', 'Mulai Perbaikan'],
                ['tanggal_selesai_perbaikan', 'jam_selesai_perbaikan', 'Selesai Perbaikan'],
            ]; @endphp
            @foreach ($pasangan as [$dCol, $tCol, $label])
                <div class="lhp-baris">
                    <label for="f_{{ $dCol }}">Tanggal {{ $label }} <span class="text-danger">*</span></label>
                    <div class="lhp-isi lhp-waktu">
                        <input type="date" id="f_{{ $dCol }}" name="{{ $dCol }}" class="form-control"
                               @if ($dCol === 'tanggal_breakdown') value="{{ now()->format('Y-m-d') }}" @endif>
                        <span class="lhp-to">Jam</span>
                        <input type="time" id="f_{{ $tCol }}" name="{{ $tCol }}" class="form-control">
                    </div>
                </div>
            @endforeach

            @foreach (['problem_dies' => 'Problem Dies', 'penyebab_breakdown' => 'Penyebab Breakdown', 'tindakan_perbaikan' => 'Tindakan Perbaikan'] as $col => $label)
                <div class="lhp-baris">
                    <label for="f_{{ $col }}">{{ $label }} <span class="text-danger">*</span></label>
                    <div class="lhp-isi">
                        <textarea id="f_{{ $col }}" name="{{ $col }}" rows="2" class="form-control"></textarea>
                    </div>
                </div>
            @endforeach

            <div class="lhp-baris">
                <label for="f_pic">PIC Maintenance <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="text" id="f_pic" name="pic_maintenance" class="form-control">
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
                        <th>No LKD</th><th>Part No.</th><th>Part Name</th><th>Proses</th>
                        <th>Tgl/Jam Breakdown</th><th>Tgl/Jam Selesai</th>
                        <th>Repair (j)</th><th>Downtime (j)</th><th>PIC</th>
                        <th style="width:36px"></th>
                    </tr>
                </thead>
                <tbody>
                    <template x-if="rows.length === 0">
                        <tr><td colspan="10" class="text-center text-muted py-3">
                            Belum ada baris draft. Isi form di atas lalu klik SAVE TO DRAFT.
                        </td></tr>
                    </template>
                    <template x-for="(r, i) in rows" :key="r.uid">
                        <tr :class="barisGagal.includes(i) ? 'table-danger' : ''">
                            <td x-text="r.no_lkd_preview"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="`${r.tanggal_breakdown} ${r.jam_breakdown}`"></td>
                            <td x-text="`${r.tanggal_selesai_perbaikan} ${r.jam_selesai_perbaikan}`"></td>
                            <td x-text="Number(r.repair_jam || 0).toFixed(2)"></td>
                            <td x-text="Number(r.downtime_jam || 0).toFixed(2)"></td>
                            <td x-text="r.pic_maintenance"></td>
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
            No LKD, Repair (j), dan Downtime (j) di atas adalah pratinjau. Angka yang tersimpan dihitung ulang di server.
        </div>

        {{-- ══ SUBMIT ══ --}}
        <form method="POST" action="{{ route('maintenance.breakdown-dies.batch') }}" id="formBatch" class="mt-3"
              @submit="pindahkanKeBackup()">
            @csrf
            {{-- 50 baris (batas server) × field flat bisa melewati max_input_vars
                 bawaan PHP (1000), yang memotong $_POST diam-diam. Dikirim
                 sebagai satu field JSON supaya jumlah baris tidak lagi terikat
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
const taParts   = @json($_taParts);
const partsData = @json($_partsData);

/* Sama persis dengan BreakdownDiesService::generateNoLkd() di server —
   dipakai HANYA untuk pratinjau tabel draft dan pemeriksaan tabrakan No LKD
   antar baris di dalam draft (periksa()). Angka yang tersimpan tetap
   dirakit ulang oleh server; kalau dua sisi ini pernah menyimpang, server
   yang menang. */
const ROMAN_BULAN = { 1: 'I', 2: 'II', 3: 'III', 4: 'IV', 5: 'V', 6: 'VI', 7: 'VII', 8: 'VIII', 9: 'IX', 10: 'X', 11: 'XI', 12: 'XII' };

function noLkdPreview(nomorUrut, tanggalBreakdown) {
    const d = new Date(`${tanggalBreakdown}T00:00:00`);
    const roman = ROMAN_BULAN[d.getMonth() + 1] || 'I';
    const yy = String(d.getFullYear()).slice(-2);

    return `${nomorUrut}/LKD/MTC/KMI/${roman}/${yy}`;
}

/* Selisih jam antara dua titik waktu (tanggal+jam), dibulatkan ke bawah 0
   kalau negatif — pratinjau saja, server yang menghitung angka tersimpan. */
function jamAntara(tglA, jamA, tglB, jamB) {
    const a = new Date(`${tglA}T${jamA}`).getTime();
    const b = new Date(`${tglB}T${jamB}`).getTime();

    return Math.max(0, (b - a) / 3600000);
}

/* Dipakai resetLain() (setelah SAVE TO DRAFT) DAN setelahPulih() (setelah
   draft dipulihkan dari localStorage) — dua momen yang sama-sama butuh
   nomor_urut di form menaik dari nomor TERTINGGI yang sudah ada di draft,
   bukan cuma dari nilai yang dirender server saat halaman dimuat. Lihat
   komentar setelahPulih di konfigBreakdownDies() untuk skenario lengkapnya. */
function majukanNomorUrut(diri) {
    if (!diri.rows.length) return;

    const maks = diri.rows.reduce((m, r) => Math.max(m, Number(r.nomor_urut) || 0), 0);
    const el = diri.form().querySelector('[name="nomor_urut"]');
    if (el) el.value = maks + 1;
}

function konfigBreakdownDies() {
    const picker = () => Alpine.$data(document.getElementById('diesPartNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_breakdown_dies_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_breakdown_dies_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('maintenance.breakdown-dies.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            nomor_urut: 'Nomor Urut LKD',
            id_part_proses: 'Proses',
            tanggal_breakdown: 'Tanggal Breakdown',
            jam_breakdown: 'Jam Breakdown',
            tanggal_mulai_perbaikan: 'Tanggal Mulai Perbaikan',
            jam_mulai_perbaikan: 'Jam Mulai Perbaikan',
            tanggal_selesai_perbaikan: 'Tanggal Selesai Perbaikan',
            jam_selesai_perbaikan: 'Jam Selesai Perbaikan',
            problem_dies: 'Problem Dies',
            penyebab_breakdown: 'Penyebab Breakdown',
            tindakan_perbaikan: 'Tindakan Perbaikan',
            pic_maintenance: 'PIC Maintenance',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                nomor_urut: diri.nilai('nomor_urut'),
                id_part_proses: diri.nilai('id_part_proses'),
                proses_teks: diri.teksTerpilih('id_part_proses'),
                part_no: p.partNo,
                part_name: p.partName,
                tanggal_breakdown: diri.nilai('tanggal_breakdown'),
                jam_breakdown: diri.nilai('jam_breakdown'),
                tanggal_mulai_perbaikan: diri.nilai('tanggal_mulai_perbaikan'),
                jam_mulai_perbaikan: diri.nilai('jam_mulai_perbaikan'),
                tanggal_selesai_perbaikan: diri.nilai('tanggal_selesai_perbaikan'),
                jam_selesai_perbaikan: diri.nilai('jam_selesai_perbaikan'),
                problem_dies: diri.nilai('problem_dies'),
                penyebab_breakdown: diri.nilai('penyebab_breakdown'),
                tindakan_perbaikan: diri.nilai('tindakan_perbaikan'),
                pic_maintenance: diri.nilai('pic_maintenance'),
            };
        },

        periksa(calon, rows, diri) {
            /* Sama dengan aturan server: tiap tahap tidak boleh mundur dari
               tahap sebelumnya. Server juga menolaknya
               (StoreBreakdownDiesBatchRequest), tapi menangkapnya di
               peramban menghemat operator satu putaran penuh
               submit-galat-perbaiki.

               Nol pemeriksaan tabrakan JAM: dua dies berbeda boleh rusak
               bersamaan — beda dari produksi, yang satu mesin cuma bisa
               menjalankan satu baris sekaligus. */
            const tsBreakdown = new Date(`${calon.tanggal_breakdown}T${calon.jam_breakdown}`).getTime();
            const tsMulai = new Date(`${calon.tanggal_mulai_perbaikan}T${calon.jam_mulai_perbaikan}`).getTime();
            const tsSelesai = new Date(`${calon.tanggal_selesai_perbaikan}T${calon.jam_selesai_perbaikan}`).getTime();

            if (tsMulai < tsBreakdown) {
                return 'Tanggal/Jam Mulai Perbaikan tidak boleh lebih awal dari Tanggal/Jam Breakdown.';
            }
            if (tsSelesai < tsMulai) {
                return 'Tanggal/Jam Selesai Perbaikan tidak boleh lebih awal dari Tanggal/Jam Mulai Perbaikan.';
            }

            /* No LKD kembar DI DALAM draft ini — arah yang tidak ada di
               jalur lama (satu baris per submit). Server memeriksa arah ini
               DAN arah lawan data yang sudah ada di database; di sini cukup
               arah draft supaya operator tidak perlu submit dulu untuk tahu. */
            const noLkdBaru = noLkdPreview(calon.nomor_urut, calon.tanggal_breakdown);
            const kembar = rows.find(r => noLkdPreview(r.nomor_urut, r.tanggal_breakdown) === noLkdBaru);
            if (kembar) {
                return `No LKD ${noLkdBaru} sudah dipakai baris lain di draft ini.`;
            }

            return null;
        },

        /* Pratinjau; server (BreakdownDiesService::computeDerived) yang
           menghitung angka yang tersimpan. */
        pratinjau(calon, diri) {
            calon.no_lkd_preview = noLkdPreview(calon.nomor_urut, calon.tanggal_breakdown);
            calon.repair_jam = jamAntara(
                calon.tanggal_mulai_perbaikan, calon.jam_mulai_perbaikan,
                calon.tanggal_selesai_perbaikan, calon.jam_selesai_perbaikan
            );
            calon.downtime_jam = jamAntara(
                calon.tanggal_breakdown, calon.jam_breakdown,
                calon.tanggal_selesai_perbaikan, calon.jam_selesai_perbaikan
            );
        },

        /* Tanggal dan PIC sengaja TIDAK di sini — satu kejadian breakdown
           biasanya diketik berturut-turut oleh PIC yang sama pada tanggal
           yang sama; mengetik ulang tiap baris bikin operator berhenti
           memakai halaman ini. Part/Proses BUKAN field bernama statis
           (di-reset lewat resetLain di bawah), jadi tidak masuk daftar ini. */
        resetField: [
            'jam_breakdown', 'jam_mulai_perbaikan', 'jam_selesai_perbaikan',
            'problem_dies', 'penyebab_breakdown', 'tindakan_perbaikan',
        ],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses"]');
            if (proses) proses.value = '';

            majukanNomorUrut(diri);
        },

        /* Seam cfg.setelahPulih (batchDraft, layouts/app.blade.php): dipanggil
           di UJUNG mulai(), setelah draft dipulihkan dari localStorage. Tanpa
           ini, nomor_urut di form tetap nilai yang dirender server saat
           halaman dimuat (dari DATABASE) walau draft yang baru dipulihkan
           sudah memakai nomor-nomor sesudahnya — baris berikutnya mengusulkan
           nomor yang sudah terpakai di draft, dan tabrakan itu BARU ketahuan
           setelah SUBMIT DRAFT menolak seluruh batch, sementara form di layar
           terlihat benar. */
        setelahPulih(diri) {
            majukanNomorUrut(diri);
        },

        payload(r) {
            return {
                nomor_urut: r.nomor_urut,
                id_part_proses: r.id_part_proses,
                tanggal_breakdown: r.tanggal_breakdown,
                jam_breakdown: r.jam_breakdown,
                tanggal_mulai_perbaikan: r.tanggal_mulai_perbaikan,
                jam_mulai_perbaikan: r.jam_mulai_perbaikan,
                tanggal_selesai_perbaikan: r.tanggal_selesai_perbaikan,
                jam_selesai_perbaikan: r.jam_selesai_perbaikan,
                problem_dies: r.problem_dies,
                penyebab_breakdown: r.penyebab_breakdown,
                tindakan_perbaikan: r.tindakan_perbaikan,
                pic_maintenance: r.pic_maintenance,
            };
        },
    };
}
</script>
@endpush
