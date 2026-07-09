@extends('layouts.app')
@section('title', 'Edit Breakdown Dies')
@section('content')

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-pencil-square"></i>Edit Breakdown Dies</h1>
    <div class="action-btns">
        <a href="{{ route('maintenance.breakdown-dies.index') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-arrow-left me-1"></i>Kembali
        </a>
    </div>
</div>

@if($errors->any())
<div class="alert alert-danger mb-3" style="font-size:0.92rem">
    <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
</div>
@endif

<div class="erp-panel">
    <div class="erp-panel-header"><i class="bi bi-hammer me-1"></i>{{ $b->no_lkd }}</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('maintenance.breakdown-dies.update', $b) }}" novalidate
              x-data="{ nomor: '{{ old('nomor_urut', (int) explode('/', $b->no_lkd)[0]) }}', tgl: '{{ old('tanggal_breakdown', $b->tanggal_breakdown->format('Y-m-d')) }}',
                        get noLkd() {
                            if (!this.nomor || !this.tgl) return '—';
                            const r = ['','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
                            const d = new Date(this.tgl);
                            return `${this.nomor}/LKD/MTC/KMI/${r[d.getMonth()+1]}/${String(d.getFullYear()).slice(-2)}`;
                        } }">
            @csrf
            @method('PUT')
            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.92rem">Nomor Urut LKD <span class="text-danger">*</span></label>
                    <input type="number" name="nomor_urut" min="1" x-model="nomor"
                           class="form-control @error('nomor_urut') is-invalid @enderror">
                    @error('nomor_urut')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-8">
                    <label class="form-label" style="font-size:0.92rem">No. LKD (otomatis)</label>
                    <div class="form-control" style="background:#f3f4f6;font-family:monospace;color:#111827" x-text="noLkd"></div>
                </div>

                @php
                    $curPartProsesId = old('id_part_proses', $b->id_part_proses);
                    $curPartObj      = $partList->first(fn ($p) => $p->proses->contains('id', (int) $curPartProsesId));
                    $curPartTxt      = $curPartObj ? "{$curPartObj->part_no} – {$curPartObj->part_name}" : '';
                @endphp
                <div class="col-12" x-data="breakdownDiesPartSelector('{{ addslashes($curPartTxt) }}', {{ $curPartObj?->id ?: 'null' }}, {{ $curPartProsesId ?: 'null' }})">
                    <label class="form-label" style="font-size:0.92rem">Part / Dies <span class="text-danger">*</span></label>
                    <div class="ta-wrap" style="margin-bottom:10px">
                        <input type="text" class="form-control ta-input @error('id_part_proses') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik part no atau nama dies...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>

                    <label class="form-label" style="font-size:0.92rem">Proses <span class="text-danger">*</span></label>
                    <select name="id_part_proses" class="form-select @error('id_part_proses') is-invalid @enderror"
                            :disabled="prosesList.length === 0">
                        <option value="">-- Pilih Proses --</option>
                        <template x-for="p in prosesList" :key="p.id">
                            <option :value="p.id" :selected="p.id == selectedProses" x-text="`${p.id_proses} – ${p.nama_proses}`"></option>
                        </template>
                    </select>
                    @error('id_part_proses')<div class="invalid-feedback d-block" style="font-size:0.88rem">{{ $message }}</div>@enderror
                </div>

                @php $pairs = [
                    ['tanggal_breakdown','jam_breakdown','Breakdown'],
                    ['tanggal_mulai_perbaikan','jam_mulai_perbaikan','Mulai Perbaikan'],
                    ['tanggal_selesai_perbaikan','jam_selesai_perbaikan','Selesai Perbaikan'],
                ]; @endphp
                @foreach($pairs as [$dCol,$tCol,$label])
                <div class="col-md-3">
                    <label class="form-label" style="font-size:0.92rem">Tanggal {{ $label }} <span class="text-danger">*</span></label>
                    <input type="date" name="{{ $dCol }}" value="{{ old($dCol, $b->$dCol->format('Y-m-d')) }}"
                           @if($dCol==='tanggal_breakdown') x-model="tgl" @endif
                           class="form-control @error($dCol) is-invalid @enderror">
                    @error($dCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3">
                    <label class="form-label" style="font-size:0.92rem">Jam {{ $label }} <span class="text-danger">*</span></label>
                    <input type="time" name="{{ $tCol }}" value="{{ old($tCol, substr($b->$tCol, 0, 5)) }}"
                           class="form-control @error($tCol) is-invalid @enderror">
                    @error($tCol)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endforeach

                @foreach(['problem_dies'=>'Problem Dies','penyebab_breakdown'=>'Penyebab Breakdown','tindakan_perbaikan'=>'Tindakan Perbaikan'] as $col=>$label)
                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.92rem">{{ $label }} <span class="text-danger">*</span></label>
                    <textarea name="{{ $col }}" rows="2" class="form-control @error($col) is-invalid @enderror">{{ old($col, $b->$col) }}</textarea>
                    @error($col)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endforeach

                <div class="col-md-4" x-data="picField('{{ addslashes(old('pic_maintenance', $b->pic_maintenance)) }}')">
                    <label class="form-label" style="font-size:0.92rem">PIC Maintenance <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" name="pic_maintenance"
                               class="form-control ta-input @error('pic_maintenance') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik atau pilih nama PIC...">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item"></div>
                            </template>
                        </div>
                    </div>
                    @error('pic_maintenance')<div class="invalid-feedback d-block" style="font-size:0.88rem">{{ $message }}</div>@enderror
                </div>
            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Update Breakdown</button>
                <a href="{{ route('maintenance.breakdown-dies.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@php
$_partsProsesData = $partList->map(fn($p) => [
    'id'     => $p->id,
    'proses' => $p->proses->map(fn($pr) => ['id' => $pr->id, 'id_proses' => $pr->id_proses, 'nama_proses' => $pr->nama_proses])->values(),
])->values();
@endphp
@push('scripts')
<script>
const taPart = @json($partList->map(fn($p) => ['v' => $p->id, 't' => $p->part_no.' – '.$p->part_name])->values());
const partsProsesData = @json($_partsProsesData);
const taPic = @json($picMaintenance->pluck('nama_karyawan')->values());

function taField(items, initText = '', initVal = null) {
    return {
        items, query: initText, val: initVal, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() {
            setTimeout(() => {
                this.open = false;
                if (this.val && !this.items.find(i => i.v == this.val && i.t === this.query)) {
                    this.val = null; this.query = '';
                }
            }, 180);
        },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) { this.query = item.t; this.val = item.v; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}

function breakdownDiesPartSelector(initText = '', initVal = null, selectedProses = null) {
    return {
        ...taField(taPart, initText, initVal),
        prosesList: initVal ? (partsProsesData.find(p => p.id == initVal)?.proses ?? []) : [],
        selectedProses,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        pick(item) {
            this.query      = item.t;
            this.val        = item.v;
            this.open       = false;
            this.idx        = -1;
            const part      = partsProsesData.find(p => p.id == item.v);
            this.prosesList = part ? part.proses : [];
            this.selectedProses = null;
        },
    };
}

function picField(initText = '') {
    return {
        items: taPic, query: initText, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() { setTimeout(() => { this.open = false; }, 180); },
        onInput() { this.open = true; this.idx = -1; },
        pick(item) { this.query = item; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}
</script>
@endpush
