@extends('layouts.app')
@section('title', 'Edit Proses Repair')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-bandaid"></i>Edit Proses Repair</h1>
    <a href="{{ route('master.proses-repair.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php
    $_taParts = $parts->map(fn ($p) => ['v' => $p->id, 't' => "{$p->part_no} — {$p->part_name}", 'c' => $p->customer])->values();
    $_curPartId = old('id_part', $prosesRepair->id_part);
    $_curPart = $parts->firstWhere('id', (int) $_curPartId);
    $_curPartText = $_curPart ? "{$_curPart->part_no} — {$_curPart->part_name}" : '';
@endphp

<div class="erp-panel" style="max-width:480px">
    <div class="erp-panel-header">Form Edit Proses Repair</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.proses-repair.update', $prosesRepair) }}">
            @csrf @method('PUT')
            <div class="mb-3" x-data="taField(taParts, '{{ addslashes($_curPartText) }}', {{ $_curPartId ?: 'null' }})">
                <label class="form-label">Part <span class="text-danger">*</span></label>
                <div class="ta-wrap">
                    <input type="text" class="form-control ta-input @error('id_part') is-invalid @enderror"
                           x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                           placeholder="Ketik part no atau nama...">
                    <input type="hidden" name="id_part" :value="val ?? ''">
                    <div class="ta-dropdown" x-show="open" x-cloak>
                        <template x-if="filtered.length === 0">
                            <div class="ta-empty">Tidak ditemukan</div>
                        </template>
                        <template x-for="(item, i) in filtered" :key="item.v">
                            <div class="ta-item" :class="{ 'ta-active': i === idx }" @mousedown.prevent="pick(item)" x-text="item.t"></div>
                        </template>
                    </div>
                </div>
                @error('id_part')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                <div class="form-text" x-show="val" x-cloak>Customer: <b x-text="(items.find(i => i.v == val) || {}).c || '—'"></b></div>
            </div>
            <div class="mb-3">
                <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                       value="{{ old('id_proses', $prosesRepair->id_proses) }}">
                @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                       value="{{ old('nama_proses', $prosesRepair->nama_proses) }}">
                @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">SPH Standar Repair <span class="text-danger">*</span></label>
                <input type="number" step="0.01" name="sph_standar_repair"
                       class="form-control @error('sph_standar_repair') is-invalid @enderror"
                       value="{{ old('sph_standar_repair', $prosesRepair->sph_standar_repair) }}" min="0">
                @error('sph_standar_repair')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.proses-repair.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taParts = @json($_taParts);
</script>
@endpush
