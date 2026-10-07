@extends('layouts.app')
@section('title', 'Edit Dandori')

@section('content')
@php
/* Data buat typeahead Part/Proses — bentuknya sama persis dengan dock filter
   di index.blade.php (partGroupComp, komponen bersama di layouts/app.blade.php)
   supaya nol menyimpang jadi implementasi ketiga. */
$_partsForFilter = $parts->map(fn ($p) => [
    'id' => $p->id, 'part_no' => $p->part_no, 'part_name' => $p->part_name, 'customer' => $p->customer,
    'proses' => $p->proses->map(fn ($pr) => ['id' => $pr->id, 'label' => $pr->id_proses.' – '.$pr->nama_proses])->values(),
])->values();
$_partsTA = $_partsForFilter->map(fn ($x) => ['v' => $x['id'], 't' => $x['part_name'], 'sub' => $x['part_no'], 'pno' => $x['part_no']])->values();
$_prosesTA = $parts->flatMap(fn ($p) => $p->proses)->unique('id')
    ->map(fn ($pr) => ['v' => $pr->id, 't' => $pr->id_proses.' – '.$pr->nama_proses])->values();

$_partIdInit = $dandori->partProses?->id_part ?: 0;
$_partLabelInit = $dandori->partProses?->part
    ? $dandori->partProses->part->part_no.' — '.$dandori->partProses->part->part_name
    : '';
$_prosesIdInit = old('id_part_proses', $dandori->id_part_proses) ?: 0;
$_prosesLabelInit = $dandori->partProses
    ? $dandori->partProses->id_proses.' — '.$dandori->partProses->nama_proses
    : '';
@endphp
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-pencil-square"></i>Edit Dandori</h1>
    <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
<div class="alert alert-danger">
    <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<div class="erp-panel" style="max-width:700px">
    <div class="erp-panel-header">
        Form Edit Dandori
        <span style="font-size:0.75rem;font-weight:400;color:var(--text-4);margin-left:8px">
            ID #{{ $dandori->id }}
        </span>
    </div>

    <form method="POST" action="{{ route('input.dandori.update', $dandori) }}" style="padding:24px">
        @csrf @method('PUT')
        {{-- Dibawa dari query string link Edit di index — dipakai controller
             buat balik ke rentang tanggal/filter yang sama setelah simpan,
             bukan reset ke default bulan berjalan. --}}
        <input type="hidden" name="back_query" value="{{ request()->query('back_query', '') }}">

        <div class="row g-3">

            {{-- Tanggal --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Tanggal <span class="text-danger">*</span></label>
                <input type="date" name="tanggal" class="form-control @error('tanggal') is-invalid @enderror"
                       value="{{ old('tanggal', $dandori->tanggal?->format('Y-m-d')) }}" required>
                @error('tanggal')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Shift --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Shift <span class="text-danger">*</span></label>
                <select name="id_shift" class="form-select @error('id_shift') is-invalid @enderror" required>
                    <option value="">— Pilih Shift —</option>
                    @foreach($shifts as $s)
                    <option value="{{ $s->id }}" {{ old('id_shift', $dandori->id_shift) == $s->id ? 'selected' : '' }}>
                        {{ $s->nama_shift }} ({{ $s->jam_start }}–{{ $s->jam_finish }})
                    </option>
                    @endforeach
                </select>
                @error('id_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Operator --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Operator <span class="text-danger">*</span></label>
                <select name="id_karyawan" class="form-select @error('id_karyawan') is-invalid @enderror" required>
                    <option value="">— Pilih Operator —</option>
                    @foreach($karyawan as $k)
                    <option value="{{ $k->id }}" {{ old('id_karyawan', $dandori->id_karyawan) == $k->id ? 'selected' : '' }}>
                        {{ $k->nama_karyawan }}
                    </option>
                    @endforeach
                </select>
                @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Mesin --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Mesin <span class="text-danger">*</span></label>
                <select name="id_mesin" class="form-select @error('id_mesin') is-invalid @enderror" required>
                    <option value="">— Pilih Mesin —</option>
                    @foreach($mesin as $m)
                    <option value="{{ $m->id }}" {{ old('id_mesin', $dandori->id_mesin) == $m->id ? 'selected' : '' }}>
                        {{ $m->id_mesin }} — {{ $m->nama_mesin }} (Line {{ $m->line }})
                    </option>
                    @endforeach
                </select>
                @error('id_mesin')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Part & Proses — typeahead ketik-cari, komponen bersama (partGroupComp)
                 yang sama dipakai dock filter di halaman index. Dulu <select> native
                 nampilin RATUSAN part sekaligus (dropdown OS mentah, nol bisa dicari),
                 sekarang ketik nama part buat nyaring. --}}
            <div class="col-12">
            <div class="row g-3" x-data="partGroupComp({{ $_partIdInit }}, '{{ addslashes($_partLabelInit) }}', {{ $_prosesIdInit }}, '{{ addslashes($_prosesLabelInit) }}')">
                <div class="col-6">
                    <label class="form-label" style="font-size:0.92rem">Part <span class="text-danger">*</span></label>
                    <div @click.outside="partOpen=false" style="position:relative">
                        <input type="text" x-model="partQuery" @focus="partOpen=true"
                               @input="partOpen=true;partVal='';prosesVal='';prosesQuery=''"
                               @keydown.escape="partOpen=false" placeholder="Ketik nama part..."
                               class="form-control">
                        <button type="button" x-show="partVal"
                                @mousedown.prevent="partVal='';partQuery='';prosesVal='';prosesQuery=''"
                                class="erp-clr">&times;</button>
                        <div x-show="partOpen && filteredParts.length > 0" x-cloak class="erp-dd">
                            <template x-for="item in filteredParts" :key="item.v">
                                <div @mousedown.prevent="pickPart(item)" class="erp-dd-item" :title="item.t">
                                    <span style="font-weight:700" x-text="item.t"></span>
                                    <span class="sub" x-text="item.sub"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                    <div style="font-size:0.78rem;color:var(--text-3);margin-top:4px" x-show="partNo" x-text="'Part No: ' + partNo"></div>
                </div>

                <div class="col-6">
                    <label class="form-label" style="font-size:0.92rem">Proses <span class="text-danger">*</span></label>
                    <div @click.outside="prosesOpen=false" style="position:relative">
                        <input type="hidden" name="id_part_proses" :value="prosesVal">
                        <input type="text" x-model="prosesQuery" @focus="prosesOpen=true"
                               @input="prosesOpen=true;prosesVal=''" @keydown.escape="prosesOpen=false"
                               placeholder="Ketik / pilih proses..."
                               class="form-control @error('id_part_proses') is-invalid @enderror">
                        <button type="button" x-show="prosesVal" @mousedown.prevent="prosesVal='';prosesQuery=''" class="erp-clr">&times;</button>
                        <div x-show="prosesOpen && filteredProses.length > 0" x-cloak class="erp-dd">
                            <template x-for="item in filteredProses" :key="item.v">
                                <div @mousedown.prevent="prosesVal=item.v;prosesQuery=item.t;prosesOpen=false"
                                     class="erp-dd-item" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_part_proses')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>
            </div>
            </div>

            {{-- Loss Time --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Loss Time <span class="text-danger">*</span></label>
                <select name="id_losstime" class="form-select @error('id_losstime') is-invalid @enderror" required>
                    <option value="">— Pilih Loss Time —</option>
                    @foreach($losstimes as $lt)
                    <option value="{{ $lt->id }}" {{ old('id_losstime', $dandori->id_losstime) == $lt->id ? 'selected' : '' }}>
                        {{ $lt->kode }} — {{ $lt->jenis_losstime }}
                    </option>
                    @endforeach
                </select>
                @error('id_losstime')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Actual Menit --}}
            <div class="col-6">
                <label class="form-label" style="font-size:0.92rem">Actual Menit <span class="text-danger">*</span></label>
                <input type="number" name="actual_menit" step="0.01" min="0"
                       class="form-control @error('actual_menit') is-invalid @enderror"
                       value="{{ old('actual_menit', $dandori->actual_menit) }}" required>
                @error('actual_menit')<div class="invalid-feedback">{{ $message }}</div>@enderror
                @if($dandori->std_menit > 0)
                <div style="font-size:0.78rem;color:var(--text-3);margin-top:4px">
                    Std: {{ number_format($dandori->std_menit, 1) }} mnt ·
                    Over sekarang: {{ number_format($dandori->over_menit, 1) }} mnt
                </div>
                @endif
            </div>

        </div>

        @if ($dandori->staff_note && !$dandori->staff_note_resolved_at)
            @include('input.partials.note-resolve-box', [
                'note'   => $dandori->staff_note,
                'noteBy' => $dandori->noteBy?->name,
                'noteAt' => $dandori->staff_note_at,
            ])
        @endif

        <hr style="margin:24px 0 20px">
        <div style="display:flex;gap:10px">
            <button type="submit" class="btn btn-primary">
                <i class="bi bi-check-lg me-1"></i>Simpan Perubahan
            </button>
            <a href="{{ route('input.dandori.index') }}" class="btn btn-outline-secondary">Batal</a>
        </div>
    </form>
</div>

@endsection

@push('scripts')
<script>
/* Data buat typeahead Part/Proses (partGroupComp, definisi di layouts/app.blade.php). */
const _partsTA = @json($_partsTA);
const _partsFullData = @json($_partsForFilter);
const _prosesTA = @json($_prosesTA);
</script>
@endpush
