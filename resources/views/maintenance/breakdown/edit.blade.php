@extends('layouts.app')
@section('title', 'Edit Breakdown Mesin')
@section('content')

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-pencil-square"></i>Edit Breakdown Mesin</h1>
    <div class="action-btns">
        <a href="{{ route('maintenance.breakdown.index') }}" class="btn btn-outline-secondary btn-sm">
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
    <div class="erp-panel-header"><i class="bi bi-tools me-1"></i>{{ $b->no_lkm }}</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('maintenance.breakdown.update', $b) }}" novalidate
              x-data="{ nomor: '{{ old('nomor_urut', (int) explode('/', $b->no_lkm)[0]) }}', tgl: '{{ old('tanggal_breakdown', $b->tanggal_breakdown->format('Y-m-d')) }}',
                        get noLkm() {
                            if (!this.nomor || !this.tgl) return '—';
                            const r = ['','I','II','III','IV','V','VI','VII','VIII','IX','X','XI','XII'];
                            const d = new Date(this.tgl);
                            return `${this.nomor}/LKM/MTC/KMI/${r[d.getMonth()+1]}/${String(d.getFullYear()).slice(-2)}`;
                        } }">
            @csrf
            @method('PUT')
            <div class="row g-3">

                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.92rem">Nomor Urut LKM <span class="text-danger">*</span></label>
                    <input type="number" name="nomor_urut" min="1" x-model="nomor"
                           class="form-control @error('nomor_urut') is-invalid @enderror">
                    @error('nomor_urut')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-8">
                    <label class="form-label" style="font-size:0.92rem">No. LKM (otomatis)</label>
                    <div class="form-control" style="background:var(--surface-3);font-family:monospace;color:var(--text)" x-text="noLkm"></div>
                </div>

                @php
                    $curMesinId  = old('id_mesin', $b->id_mesin);
                    $curMesinObj = $mesinList->firstWhere('id', (int) $curMesinId);
                    $curMesinTxt = $curMesinObj ? "{$curMesinObj->id_mesin} – {$curMesinObj->nama_mesin}" : '';
                @endphp
                <div class="col-12" x-data="taField(taMesin, '{{ addslashes($curMesinTxt) }}', {{ $curMesinId ?: 'null' }})">
                    <label class="form-label" style="font-size:0.92rem">Mesin <span class="text-danger">*</span></label>
                    <div class="ta-wrap">
                        <input type="text" class="form-control ta-input @error('id_mesin') is-invalid @enderror"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Ketik kode atau nama mesin...">
                        <input type="hidden" name="id_mesin" :value="val">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}"
                                     @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                    @error('id_mesin')<div class="invalid-feedback d-block" style="font-size:0.88rem">{{ $message }}</div>@enderror
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

                @foreach(['problem_mesin'=>'Problem Mesin','penyebab_breakdown'=>'Penyebab Breakdown','tindakan_perbaikan'=>'Tindakan Perbaikan'] as $col=>$label)
                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.92rem">{{ $label }} <span class="text-danger">*</span></label>
                    <textarea name="{{ $col }}" rows="2" class="form-control @error($col) is-invalid @enderror">{{ old($col, $b->$col) }}</textarea>
                    @error($col)<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                @endforeach

                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.92rem">PIC Maintenance <span class="text-danger">*</span></label>
                    <input type="text" name="pic_maintenance" value="{{ old('pic_maintenance', $b->pic_maintenance) }}"
                           class="form-control @error('pic_maintenance') is-invalid @enderror">
                    @error('pic_maintenance')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border-2);display:flex;gap:8px">
                <button class="btn btn-primary" type="submit"><i class="bi bi-save me-1"></i>Update Breakdown</button>
                <a href="{{ route('maintenance.breakdown.index') }}" class="btn btn-outline-secondary">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin])->values());

/* taField() kini satu definisi di layouts/app.blade.php. */
</script>
@endpush
