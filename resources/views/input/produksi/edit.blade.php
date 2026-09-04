@extends('layouts.app')
@section('title', 'Edit LHP Produksi')

@php
/*
 * Gunakan old() HANYA jika ini adalah redirect balik dari UPDATE yang gagal.
 * Kalau bukan (misal: user baru buka halaman edit, atau error dari form lain),
 * pakai nilai langsung dari model — tidak akan ter-reset.
 */
$useOld = $errors->updateProduksi->any();

$initKaryawanId  = $useOld ? old('id_karyawan', $produksi->id_karyawan) : $produksi->id_karyawan;
$initKaryawanObj = $karyawan->firstWhere('id', $initKaryawanId);
$initKaryawanTxt = $initKaryawanObj?->nama_karyawan ?? '';

$initMesinId  = $useOld ? old('id_mesin', $produksi->id_mesin) : $produksi->id_mesin;
$initMesinObj = $mesin->firstWhere('id', $initMesinId);
$initMesinTxt = $initMesinObj ? "{$initMesinObj->id_mesin} - {$initMesinObj->nama_mesin} (Line {$initMesinObj->line})" : '';

$initPartProsesId = $useOld ? old('id_part_proses', $produksi->id_part_proses) : $produksi->id_part_proses;
$initPart         = $produksi->partProses?->part;
$initPartTxt      = $initPart ? "{$initPart->part_no} — {$initPart->part_name}" : '';
$initPartId       = $initPart?->id;

$existingNg   = \App\Services\NgDetailService::available()
    ? $produksi->ngDetails->map(fn ($d) => [
        'id_jenis_ng' => $d->id_jenis_ng,
        'qty'         => $d->qty,
    ])->values()->all()
    : [];

/*
 * Baris gaya lama: qty_ng terisi tapi nol baris detail. Render satu baris
 * dengan qty terisi dan jenis kosong supaya operator wajib mengklasifikasi —
 * kalau dibiarkan nol baris, menyimpan akan menulis qty_ng = 0 dan menghapus
 * angka NG yang pernah tercatat tanpa error.
 */
if (empty($existingNg) && (int) $produksi->qty_ng > 0) {
    $existingNg = [['id_jenis_ng' => '', 'qty' => (int) $produksi->qty_ng]];
}

$initNgRows   = $useOld ? (old('ng') ?: $existingNg) : $existingNg;
$initAdaNg    = ! empty($initNgRows) ? '1' : '0';

$initProsesListPhp = $initPart
    ? $initPart->proses->map(fn($pr) => [
        'id'                   => $pr->id,
        'id_proses'            => $pr->id_proses,
        'nama_proses'          => $pr->nama_proses,
        'sph_standar_produksi' => $pr->sph_standar_produksi,
    ])->values()
    : collect();
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-pencil-square"></i>Edit LHP Produksi</h1>
    <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->updateProduksi->any())
    <div class="alert alert-danger">
        <ul class="mb-0">@foreach($errors->updateProduksi->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:750px">
    <div class="erp-panel-header">Form Edit LHP Produksi
        <span style="font-size:0.75rem;font-weight:400;color:#9ca3af;margin-left:8px">
            ID #{{ $produksi->id }} · Dibuat oleh: {{ $produksi->creator?->name ?? '—' }}
        </span>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.produksi.update', $produksi) }}">
            @csrf @method('PUT')
            {{-- Dibawa dari query string link Edit di index — dipakai controller
                 buat balik ke rentang tanggal/filter yang sama setelah simpan,
                 bukan reset ke default bulan berjalan. --}}
            <input type="hidden" name="back_query" value="{{ request()->query('back_query', '') }}">
            <div class="row g-3">

                {{-- Tanggal --}}
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi <span class="text-danger">*</span></label>
                    <input type="date" name="tanggal_produksi"
                           class="form-control @error('tanggal_produksi', 'updateProduksi') is-invalid @enderror"
                           value="{{ $useOld ? old('tanggal_produksi', $produksi->tanggal_produksi->format('Y-m-d')) : $produksi->tanggal_produksi->format('Y-m-d') }}">
                    @error('tanggal_produksi', 'updateProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Shift --}}
                <div class="col-md-6">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift', 'updateProduksi') is-invalid @enderror">
                        <option value="">-- Pilih Shift --</option>
                        @foreach($shifts as $shift)
                            @php $selShift = $useOld ? old('id_shift', $produksi->id_shift) : $produksi->id_shift; @endphp
                            <option value="{{ $shift->id }}" {{ $selShift == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }} ({{ $shift->jam_start }} – {{ $shift->jam_finish }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift', 'updateProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Operator — typeahead --}}
                <div class="col-md-6"
                     x-data="taField(taKaryawan, '{{ addslashes($initKaryawanTxt) }}', {{ $initKaryawanId ?: 'null' }})">
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
                    @error('id_karyawan', 'updateProduksi')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Mesin — typeahead --}}
                <div class="col-md-6"
                     x-data="taField(taMesin, '{{ addslashes($initMesinTxt) }}', {{ $initMesinId ?: 'null' }})">
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
                    @error('id_mesin', 'updateProduksi')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Part + Proses --}}
                <div class="col-12"
                     x-data="produksiPartSelectorEdit('{{ addslashes($initPartTxt) }}', {{ $initPartId ?: 'null' }}, initProsesListEdit)">
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
                                    :selected="p.id == {{ $initPartProsesId }}"
                                    x-text="`${p.id_proses} – ${p.nama_proses} (SPH Std: ${p.sph_standar_produksi})`">
                            </option>
                        </template>
                    </select>
                    @error('id_part_proses', 'updateProduksi')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Jam Start & Finish --}}
                <div class="col-md-4">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start"
                           class="form-control @error('jam_start', 'updateProduksi') is-invalid @enderror"
                           value="{{ $useOld ? old('jam_start', substr($produksi->jam_start,0,5)) : substr($produksi->jam_start,0,5) }}">
                    @error('jam_start', 'updateProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish"
                           class="form-control @error('jam_finish', 'updateProduksi') is-invalid @enderror"
                           value="{{ $useOld ? old('jam_finish', substr($produksi->jam_finish,0,5)) : substr($produksi->jam_finish,0,5) }}">
                    @error('jam_finish', 'updateProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Qty Aktual <span class="text-danger">*</span></label>
                    <input type="number" name="qty_aktual"
                           class="form-control @error('qty_aktual', 'updateProduksi') is-invalid @enderror"
                           value="{{ $useOld ? old('qty_aktual', $produksi->qty_aktual) : $produksi->qty_aktual }}" min="1">
                    @error('qty_aktual', 'updateProduksi')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- NG Proses Toggle --}}
                <div class="col-12" x-data="{ adaNg: {{ $initAdaNg == '1' ? 'true' : 'false' }} }">
                    <div class="input-panel">
                        <div class="form-check mb-0">
                            <input class="form-check-input" type="checkbox" name="ada_ng" id="ada_ng"
                                   value="1" x-model="adaNg"
                                   {{-- Un-tick harus benar-benar mengosongkan rows Alpine di partial
                                        ng-rows (bukan cuma menyembunyikannya lewat x-show), supaya
                                        ng_count ikut jadi 0 dan konsisten dengan ng=[] yang dikirim
                                        prepareForValidation(). Tanpa ini ng_count bisa membawa sisa
                                        hitungan baris lama saat checkbox dilepas. --}}
                                   @change="if (!adaNg) { const w = $el.closest('[x-data]').querySelector('.ng-rows'); if (w) Alpine.$data(w).rows = []; }"
                                   {{ $initAdaNg ? 'checked' : '' }}>
                            <label class="form-check-label fw-semibold" for="ada_ng"
                                   style="font-size:0.875rem;color:#374151">
                                <i class="bi bi-exclamation-triangle me-1 text-warning"></i>Ada NG Proses?
                            </label>
                        </div>

                        <div x-show="adaNg" x-cloak class="mt-2">
                            @include('input.partials.ng-rows', [
                                'jenisNg' => $jenisNg,
                                'initial' => $initNgRows,
                            ])
                        </div>
                    </div>
                </div>

            </div>

            {{-- Note staff yang belum ditangani. Ditampilkan di sini supaya admin membaca
                 yang diminta sambil mengedit — sebelumnya isi note cuma ada di halaman index,
                 jadi harus diingat-ingat. Checkbox menyatukan simpan + tandai selesai. --}}
            @if ($produksi->staff_note && !$produksi->staff_note_resolved_at)
                @include('input.partials.note-resolve-box', [
                    'note'   => $produksi->staff_note,
                    'noteBy' => $produksi->noteBy?->name,
                    'noteAt' => $produksi->staff_note_at,
                ])
            @endif

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Update
                </button>
                <a href="{{ route('input.produksi.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@php
$_taKaryawan     = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taMesin        = $mesin->map(fn($m) => ['v' => $m->id, 't' => "{$m->id_mesin} - {$m->nama_mesin} (Line {$m->line})"])->values();
$_taParts        = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData      = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'                   => $pr->id,
        'id_proses'            => $pr->id_proses,
        'nama_proses'          => $pr->nama_proses,
        'sph_standar_produksi' => $pr->sph_standar_produksi,
    ])->values(),
])->values();
$_taJenisNg      = $jenisNg->map(fn($j) => ['v' => $j->id, 't' => "{$j->kode} — {$j->nama_ng}"])->values();
@endphp
@push('scripts')
<script>
const taKaryawan      = @json($_taKaryawan);
const taMesin         = @json($_taMesin);
const taParts         = @json($_taParts);
const partsData       = @json($_partsData);
const taJenisNg       = @json($_taJenisNg);
const initProsesListEdit = @json($initProsesListPhp);

/* taField() kini satu definisi di layouts/app.blade.php. */

/* ── Part selector edit (accepts initial proses list) ──── */
function produksiPartSelectorEdit(initText, initVal, initProses) {
    return {
        ...taField(taParts, initText, initVal),
        prosesList: initProses || [],
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
