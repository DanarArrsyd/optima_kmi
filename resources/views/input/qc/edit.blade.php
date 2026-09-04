@extends('layouts.app')
@section('title', 'Edit LHP Quality')

@php
$useOld = $errors->updateQc->any();

$initKaryawanId  = $useOld ? old('id_karyawan_qc', $qc->id_karyawan_qc) : $qc->id_karyawan_qc;
$initKaryawanObj = $karyawan->firstWhere('id', $initKaryawanId);
$initKaryawanTxt = $initKaryawanObj?->nama_karyawan ?? '';

$initPartProsesId = $useOld ? old('id_part_proses', $qc->id_part_proses) : $qc->id_part_proses;
$initPart         = $qc->partProses?->part;
$initPartTxt      = $initPart ? "{$initPart->part_no} — {$initPart->part_name}" : '';

$initProses = $qc->partProses ? [
    'id'             => $qc->partProses->id,
    'id_proses'      => $qc->partProses->id_proses,
    'nama_proses'    => $qc->partProses->nama_proses,
    'sph_standar_qc' => $qc->partProses->sph_standar_qc,
] : null;

$existingNg = \App\Services\NgDetailService::available()
    ? $qc->ngDetails->map(fn ($d) => [
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
if (empty($existingNg) && (int) $qc->qty_ng > 0) {
    $existingNg = [['id_jenis_ng' => '', 'qty' => (int) $qc->qty_ng]];
}

$initNgRows = $useOld ? (old('ng') ?: $existingNg) : $existingNg;
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-pencil-square"></i>Edit LHP Quality</h1>
    <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->updateQc->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->updateQc->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:750px">
    <div class="erp-panel-header">Form Edit LHP Quality
        <span style="font-size:0.75rem;font-weight:400;color:#9ca3af;margin-left:8px">
            ID #{{ $qc->id }} · Dibuat oleh: {{ $qc->creator?->name ?? '—' }}
        </span>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('input.qc.update', $qc) }}">
            @csrf @method('PUT')
            {{-- Dibawa dari query string link Edit di index — dipakai controller
                 buat balik ke rentang tanggal/filter yang sama setelah simpan,
                 bukan reset ke default bulan berjalan. --}}
            <input type="hidden" name="back_query" value="{{ request()->query('back_query', '') }}">
            <div class="row g-3">

                {{-- Tanggal --}}
                <div class="col-md-6">
                    <label class="form-label">Tanggal Pengecekan <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_pengecekan"
                           class="form-control @error('tgl_pengecekan', 'updateQc') is-invalid @enderror"
                           value="{{ $useOld ? old('tgl_pengecekan', $qc->tgl_pengecekan->format('Y-m-d')) : $qc->tgl_pengecekan->format('Y-m-d') }}">
                    @error('tgl_pengecekan', 'updateQc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Tanggal Produksi Part <span class="text-danger">*</span></label>
                    <input type="date" name="tgl_produksi_part"
                           class="form-control @error('tgl_produksi_part', 'updateQc') is-invalid @enderror"
                           value="{{ $useOld ? old('tgl_produksi_part', $qc->tgl_produksi_part->format('Y-m-d')) : $qc->tgl_produksi_part->format('Y-m-d') }}">
                    @error('tgl_produksi_part', 'updateQc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Shift --}}
                <div class="col-md-6">
                    <label class="form-label">Shift <span class="text-danger">*</span></label>
                    <select name="id_shift" class="form-select @error('id_shift', 'updateQc') is-invalid @enderror">
                        <option value="">-- Pilih Shift --</option>
                        @foreach($shifts as $shift)
                            @php $selShift = $useOld ? old('id_shift', $qc->id_shift) : $qc->id_shift; @endphp
                            <option value="{{ $shift->id }}" {{ $selShift == $shift->id ? 'selected' : '' }}>
                                {{ $shift->nama_shift }} ({{ $shift->jam_start }} – {{ $shift->jam_finish }})
                            </option>
                        @endforeach
                    </select>
                    @error('id_shift', 'updateQc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- QC Inspector — typeahead --}}
                <div class="col-md-6"
                     x-data="taField(taKaryawan, '{{ addslashes($initKaryawanTxt) }}', {{ $initKaryawanId ?: 'null' }})">
                    <label class="form-label">QC Inspector <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text"
                               class="form-control ta-input @error('id_karyawan_qc', 'updateQc') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik nama inspector...">
                        <input type="hidden" name="id_karyawan_qc" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_karyawan_qc', 'updateQc')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Part — typeahead, auto finish-good proses --}}
                <div class="col-12" x-data="qcPartSelectorEdit('{{ addslashes($initPartTxt) }}', {{ $initPartProsesId ?: 'null' }})">
                    <label class="form-label">Part (Finish Good) <span class="text-danger">*</span></label>
                    <div class="ta-wrap" style="margin-bottom:10px">
                        <input type="text"
                               class="form-control ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()"
                               @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik part no atau nama part...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length === 0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item, i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{ 'ta-active': i === idx }"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    <div x-show="proses !== null" x-cloak class="mb-2 p-2"
                         style="background:#f0f4f8;border:1px solid #d4dbe3;border-radius:2px;font-size:0.8rem">
                        <i class="bi bi-check2-circle text-success me-1"></i>
                        <strong>Proses Finish Good:</strong>
                        <span x-text="proses ? `${proses.id_proses} – ${proses.nama_proses}` : ''"></span>
                        <span style="color:#5d7283" x-text="proses ? ` (SPH Std QC: ${proses.sph_standar_qc})` : ''"></span>
                    </div>
                    <input type="hidden" name="id_part_proses" :value="proses ? proses.id : ''">
                    @error('id_part_proses', 'updateQc')<div class="text-danger mt-1" style="font-size:0.875rem">{{ $message }}</div>@enderror
                </div>

                {{-- Jam --}}
                <div class="col-md-6">
                    <label class="form-label">Jam Start <span class="text-danger">*</span></label>
                    <input type="time" name="jam_start"
                           class="form-control @error('jam_start', 'updateQc') is-invalid @enderror"
                           value="{{ $useOld ? old('jam_start', substr($qc->jam_start,0,5)) : substr($qc->jam_start,0,5) }}">
                    @error('jam_start', 'updateQc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-6">
                    <label class="form-label">Jam Finish <span class="text-danger">*</span></label>
                    <input type="time" name="jam_finish"
                           class="form-control @error('jam_finish', 'updateQc') is-invalid @enderror"
                           value="{{ $useOld ? old('jam_finish', substr($qc->jam_finish,0,5)) : substr($qc->jam_finish,0,5) }}">
                    @error('jam_finish', 'updateQc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Qty OK --}}
                <div class="col-md-6">
                    <label class="form-label">Qty OK <span class="text-danger">*</span></label>
                    <input type="number" name="qty_ok"
                           class="form-control @error('qty_ok', 'updateQc') is-invalid @enderror"
                           value="{{ $useOld ? old('qty_ok', $qc->qty_ok) : $qc->qty_ok }}" min="0">
                    @error('qty_ok', 'updateQc')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Jenis NG --}}
                <div class="col-12">
                    <label class="form-label fw-semibold">Jenis NG</label>
                    @include('input.partials.ng-rows', [
                        'jenisNg' => $jenisNg,
                        'initial' => $initNgRows,
                    ])
                </div>

            </div>

            @if ($qc->staff_note && !$qc->staff_note_resolved_at)
                @include('input.partials.note-resolve-box', [
                    'note'   => $qc->staff_note,
                    'noteBy' => $qc->noteBy?->name,
                    'noteAt' => $qc->staff_note_at,
                ])
            @endif

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="bi bi-save me-1"></i>Update
                </button>
                <a href="{{ route('input.qc.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@php
$_taKaryawan = $karyawan->map(fn($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();
$_taParts    = $parts->map(fn($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}"])->values();
$_partsData  = $parts->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => [
        'id'             => $pr->id,
        'id_proses'      => $pr->id_proses,
        'nama_proses'    => $pr->nama_proses,
        'sph_standar_qc' => $pr->sph_standar_qc,
    ])->last(),
])->values();
$_taJenisNg  = $jenisNg->map(fn($j) => ['v' => $j->id, 't' => "{$j->kode} — {$j->nama_ng}"])->values();
@endphp
@push('scripts')
<script>
const taKaryawan       = @json($_taKaryawan);
const taParts          = @json($_taParts);
const partsData        = @json($_partsData);
const taJenisNg        = @json($_taJenisNg);
const initProsesQcEdit = @json($initProses);

/* taField() kini satu definisi di layouts/app.blade.php. */

function qcPartSelectorEdit(initText, initProsesId) {
    return {
        ...taField(taParts, initText, initProsesId ? (partsData.find(p => p.proses && p.proses.id == initProsesId)?.id ?? null) : null),
        proses: initProsesQcEdit,
        get filtered() { return taFieldFiltered(this.query, this.items); },
        pick(item) {
            this.query  = item.t; this.val = item.v; this.open = false; this.idx = -1;
            const part  = partsData.find(p => p.id == item.v);
            this.proses = part ? part.proses : null;
        },
    };
}
</script>
@endpush
