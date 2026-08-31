@extends('layouts.app')

@section('title', 'Input Dandori')

@php
    $_taOperator = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
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
        ])->values(),
    ])->values();
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
<div class="erp-panel" x-data="batchDraft(konfigDandori())" x-init="mulai()">
    <div class="erp-panel-header">Input Dandori</div>
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
                <label for="f_tanggal">Tanggal <span class="text-danger">*</span></label>
                <div class="lhp-isi">
                    <input type="date" id="f_tanggal" name="tanggal" class="form-control"
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

            <div class="lhp-baris" x-data="taField(taOperator)">
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
                 prosesList milik komponen itu. Dandori memakai SEMUA proses
                 (bukan cuma finish good seperti QC), jadi Proses di sini
                 tetap <select> yang dipilih manual — sama seperti
                 input/produksi/create.blade.php, bukan kotak info seperti QC. --}}
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
                    <label for="dandoriPartNoTampil">Part No.</label>
                    <div class="lhp-isi">
                        <input type="text" class="form-control" id="dandoriPartNoTampil" :value="partNo" readonly
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
                                {{-- data-progressive dibaca dandori-rows.blade.php untuk
                                     memilih std_menit_progressive. Penanda, bukan teks
                                     pilihan — lihat komentar init()/syncProgressive() di
                                     partial itu. --}}
                                <option :value="p.id"
                                        :data-progressive="p.nama_proses.trim().toUpperCase() === 'PROGRESSIVE' ? '1' : '0'"
                                        x-text="`${p.id_proses} – ${p.nama_proses}`">
                                </option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>

            {{-- ══ RINCIAN LOSS TIME ══
                 Dandori WAJIB minimal 1 rincian, jadi blok ini SELALU tampil
                 — beda dari blok NG di QC/Produksi yang dibungkus checkbox
                 opsional. periksa() di konfigDandori() yang menolak SAVE TO
                 DRAFT kalau rincian masih kosong. --}}
            <div class="row g-3 mt-1">
                <div class="col-12">
                    <div class="input-panel">
                        <div class="fw-semibold mb-2" style="font-size:0.875rem;color:#374151">
                            <i class="bi bi-tools me-1 text-secondary"></i>Rincian Loss Time
                            <span class="text-danger">*</span>
                        </div>
                        @include('input.partials.dandori-rows', [
                            'losstimes' => $losstimes,
                            'karyawan' => $karyawan,
                        ])
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
                        <th>Tanggal</th><th>Shift</th><th>Operator</th><th>Mesin</th>
                        <th>Part No.</th><th>Part Name</th><th>Proses</th>
                        <th>Rincian</th><th>Total Menit</th>
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
                        <tr :class="i === editIndex ? 'table-warning' : (barisGagal.includes(i) ? 'table-danger' : '')">
                            <td x-text="r.tanggal"></td>
                            <td x-text="r.shift_teks"></td>
                            <td x-text="r.karyawan_teks"></td>
                            <td x-text="r.mesin_teks"></td>
                            <td x-text="r.part_no"></td>
                            <td x-text="r.part_name"></td>
                            <td x-text="r.proses_teks"></td>
                            <td x-text="ringkasRincian(r)"></td>
                            <td x-text="r.total_menit.toFixed(1)"></td>
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
            Total Menit di atas adalah pratinjau. Std/Over per rincian dan angka yang tersimpan dihitung ulang di server.
        </div>

        {{-- ══ SUBMIT ══ --}}
        <form method="POST" action="{{ route('input.dandori.batch') }}" id="formBatch" class="mt-3"
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
const taOperator = @json($_taOperator);
const taMesin    = @json($_taMesin);
const taParts    = @json($_taParts);
const partsData  = @json($_partsData);
const ltLabel    = @json($_ltLabel);

/* dandori-rows.blade.php (blok Rincian Loss Time, field opsional "Operator
   Dandori") memakai global taKaryawan — nama tetap dari halaman-halaman lain
   yang menyertakan partial itu (produksi/create, produksi/edit, qc/edit,
   dandori/index). Di halaman INI, taOperator sudah memetakan $karyawan
   dengan cara yang sama persis, jadi taKaryawan cukup alias ke taOperator
   alih-alih dipetakan ulang dari server — jangan biarkan keduanya jadi
   definisi terpisah yang bisa mencar kalau salah satu diubah nanti. */
const taKaryawan = taOperator;

function konfigDandori() {
    const picker = () => Alpine.$data(document.getElementById('dandoriPartNoTampil').closest('[x-data]'));

    return {
        kunci:       'optima_draft_dandori_u{{ auth()->id() }}',
        kunciBackup: 'optima_draft_dandori_terkirim_u{{ auth()->id() }}',
        formId: 'formBaris',
        indexUrl: '{{ route('input.dandori.index') }}',
        galatServer: @json($errors->keys()),

        wajib: {
            tanggal: 'Tanggal',
            id_shift: 'Shift',
            id_karyawan: 'Operator',
            id_mesin: 'Mesin',
            id_part_proses: 'Proses',
        },

        kumpulkan(diri) {
            const p = picker();

            return {
                tanggal: diri.nilai('tanggal'),
                id_shift: diri.nilai('id_shift'),
                shift_teks: diri.teksTerpilih('id_shift'),
                id_karyawan: diri.nilai('id_karyawan'),
                karyawan_teks: (taOperator.find(k => k.v == diri.nilai('id_karyawan')) || {}).t || '',
                id_mesin: diri.nilai('id_mesin'),
                mesin_teks: (taMesin.find(m => m.v == diri.nilai('id_mesin')) || {}).t || '',
                id_part_proses: diri.nilai('id_part_proses'),
                proses_teks: diri.teksTerpilih('id_part_proses'),
                part_no: p.partNo,
                part_name: p.partName,
                rincian: (diri.blok('.dandori-rows') || { rows: [] }).rows
                    .map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
            };
        },

        periksa(calon, rows, diri) {
            if (calon.rincian.length === 0) {
                return 'Minimal harus ada 1 aktivitas loss time.';
            }
            for (const r of calon.rincian) {
                if (r.id_losstime === '' || r.id_losstime === null) {
                    return 'Setiap rincian wajib memilih Jenis Loss Time dari daftar.';
                }
                if (r.actual_menit === '' || r.actual_menit === null || Number(r.actual_menit) <= 0) {
                    return 'Setiap rincian wajib punya waktu aktual lebih dari 0 menit.';
                }
            }

            /* Nol pemeriksaan tabrakan jam: Dandori tidak punya jam
               start/finish sama sekali, beda dari produksi yang satu mesin
               tidak bisa menjalankan dua baris sekaligus. */
            return null;
        },

        /* Pratinjau; server yang menghitung angka yang tersimpan. Std/Over
           per rincian sudah dipratinjau di dalam blok dandori-rows itu
           sendiri (kolom Std/Over di tabelnya, dengan penanda jelas kalau
           itu cuma pratinjau) — di sini cukup total menit untuk kolom
           "Total Menit" tabel draft. */
        pratinjau(calon, diri) {
            calon.total_menit = calon.rincian.reduce((s, r) => s + Number(r.actual_menit || 0), 0);
        },

        /* Tanggal, Shift, Operator, dan Mesin sengaja TIDAK di sini: keempatnya
           konstan sepanjang satu sesi kerja. Part, Proses, dan rincian BUKAN
           field bernama statis (Proses & rincian di-reset lewat resetLain di
           bawah), jadi cfg.resetField sengaja kosong — tidak ada satu pun
           <input name> polos yang perlu dikosongkan lewat mekanisme ini. */
        resetField: [],

        resetLain(diri) {
            const p = picker();
            p.query = ''; p.val = null; p.partNo = '';
            p.partName = ''; p.prosesList = [];

            const proses = diri.form().querySelector('[name="id_part_proses"]');
            if (proses) proses.value = '';

            const dandori = diri.blok('.dandori-rows');
            if (dandori) dandori.rows = [];
        },

        /* Kebalikan cfg.kumpulkan. Blok Rincian Loss Time selalu tampil (nol
           checkbox), jadi langsung isi dandori.rows tanpa toggle apa pun. */
        sebar(row, diri) {
            const f = diri.form();
            f.querySelector('[name="tanggal"]').value = row.tanggal;
            f.querySelector('[name="id_shift"]').value = row.id_shift;

            const operator = Alpine.$data(document.getElementById('f_operator').closest('[x-data]'));
            operator.pick({ v: row.id_karyawan, t: row.karyawan_teks });

            const mesin = Alpine.$data(document.getElementById('f_mesin').closest('[x-data]'));
            mesin.pick({ v: row.id_mesin, t: row.mesin_teks });

            const p = picker();
            const dataPart = partsData.find(pp => pp.part_no === row.part_no && pp.part_name === row.part_name);
            if (dataPart) {
                p.pilih({ v: dataPart.id, t: `${dataPart.part_no} — ${dataPart.part_name}` });
                Alpine.nextTick(() => {
                    const proses = f.querySelector('[name="id_part_proses"]');
                    if (proses) proses.value = row.id_part_proses;
                });
            }

            Alpine.nextTick(() => {
                const dandori = diri.blok('.dandori-rows');
                if (dandori) {
                    dandori.rows = row.rincian.map((r, i) => ({
                        uid: `e${Date.now()}_${i}`, id_losstime: r.id_losstime, actual_menit: r.actual_menit,
                        std_menit: 0, over_menit: 0,
                    }));
                    /* Dispatch change supaya dandori-rows.syncProgressive() (didengarkan di
                       form) menyinkron ulang flag progressive dari id_part_proses yang baru
                       saja diisi sebar(), lalu recompute Std/Over semua baris sendiri — proses.value
                       yang diisi lewat DOM mentah di atas TIDAK memancarkan change secara alami. */
                    const proses = f.querySelector('[name="id_part_proses"]');
                    if (proses) proses.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
        },

        payload(r) {
            return {
                tanggal: r.tanggal,
                id_shift: r.id_shift,
                id_karyawan: r.id_karyawan,
                id_mesin: r.id_mesin,
                id_part_proses: r.id_part_proses,
                rincian: r.rincian.map(d => ({ id_losstime: d.id_losstime, actual_menit: d.actual_menit })),
            };
        },

        metode: {
            ringkasRincian(r) {
                if (!r.rincian.length) return '—';
                return r.rincian
                    .map(d => `${ltLabel[d.id_losstime] ?? d.id_losstime} ×${d.actual_menit} mnt`)
                    .join(', ');
            },
        },
    };
}
</script>
@endpush
