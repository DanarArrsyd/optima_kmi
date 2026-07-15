@extends('layouts.app')
@section('title', 'Edit Shift')
@section('suppress_layout_flash', 'yes')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-calendar-week"></i>Edit Shift — {{ $shift->nama_shift }}</h1>
    <a href="{{ route('master.shift.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

@php
    // Group existing istirahat by kelompok_hari for JS pre-fill
    $istirahatByKel = $shift->istirahat->groupBy('kelompok_hari')->map(fn($g) =>
        $g->map(fn($r) => ['jam_start' => substr($r->jam_start,0,5), 'jam_finish' => substr($r->jam_finish,0,5)])->values()
    );
@endphp

<form method="POST" action="{{ route('master.shift.update', $shift) }}"
      x-data="shiftForm()" x-init="init()">
    @csrf @method('PUT')

    {{-- Nama Shift --}}
    <div class="erp-panel mb-3" style="max-width:480px">
        <div class="erp-panel-header">Identitas Shift</div>
        <div class="erp-panel-body">
            <div class="mb-0">
                <label class="form-label">Nama Shift <span class="text-danger">*</span></label>
                <input type="text" name="nama_shift"
                       class="form-control @error('nama_shift') is-invalid @enderror"
                       value="{{ old('nama_shift', $shift->nama_shift) }}">
                @error('nama_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
        </div>
    </div>

    {{-- Jadwal per kelompok hari --}}
    @foreach([
        ['key' => 'senin_kamis', 'label' => 'Senin – Kamis', 'color' => '#1a6b7c',
         'start_name' => 'jam_start',       'finish_name' => 'jam_finish',
         'start_val'  => old('jam_start',  substr($shift->jam_start,0,5)),
         'finish_val' => old('jam_finish', substr($shift->jam_finish,0,5))],
        ['key' => 'jumat', 'label' => 'Jum\'at', 'color' => '#7a5c00',
         'start_name' => 'jam_start_jumat', 'finish_name' => 'jam_finish_jumat',
         'start_val'  => old('jam_start_jumat',  substr($shift->jam_start_jumat ?? '07:00',0,5)),
         'finish_val' => old('jam_finish_jumat', substr($shift->jam_finish_jumat ?? '15:30',0,5))],
        ['key' => 'sabtu', 'label' => 'Sabtu', 'color' => '#5a3ea0',
         'start_name' => 'jam_start_sabtu', 'finish_name' => 'jam_finish_sabtu',
         'start_val'  => old('jam_start_sabtu',  substr($shift->jam_start_sabtu ?? '07:00',0,5)),
         'finish_val' => old('jam_finish_sabtu', substr($shift->jam_finish_sabtu ?? '12:15',0,5))],
    ] as $day)
    <div class="erp-panel mb-3" style="max-width:680px">
        <div class="erp-panel-header" style="background:{{ $day['color'] }};color:#fff">
            <i class="bi bi-calendar3 me-1"></i>{{ $day['label'] }}
        </div>
        <div class="erp-panel-body">
            {{-- Jam Kerja --}}
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Masuk</label>
                    <input type="time" name="{{ $day['start_name'] }}"
                           class="form-control @error($day['start_name']) is-invalid @enderror"
                           value="{{ $day['start_val'] }}">
                    @error($day['start_name'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Pulang</label>
                    <input type="time" name="{{ $day['finish_name'] }}"
                           class="form-control @error($day['finish_name']) is-invalid @enderror"
                           value="{{ $day['finish_val'] }}">
                    @error($day['finish_name'])<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            {{-- Istirahat rows --}}
            <div style="font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:6px">
                <i class="bi bi-cup-hot me-1"></i>Jam Istirahat
            </div>
            <template x-for="(row, idx) in days['{{ $day['key'] }}']" :key="idx">
                <div class="row g-2 mb-2 align-items-center">
                    <div class="col-md-3">
                        <input type="time"
                               :name="`istirahat[{{ $day['key'] }}][${idx}][jam_start]`"
                               x-model="row.jam_start"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-auto" style="color:#888;font-size:0.8rem">s/d</div>
                    <div class="col-md-3">
                        <input type="time"
                               :name="`istirahat[{{ $day['key'] }}][${idx}][jam_finish]`"
                               x-model="row.jam_finish"
                               class="form-control form-control-sm">
                    </div>
                    <div class="col-auto">
                        <button type="button" class="btn btn-outline-danger btn-sm"
                                @click="removeRow('{{ $day['key'] }}', idx)"
                                title="Hapus istirahat ini">
                            <i class="bi bi-trash3"></i>
                        </button>
                    </div>
                </div>
            </template>
            <button type="button" class="btn btn-outline-secondary btn-sm mt-1"
                    @click="addRow('{{ $day['key'] }}')">
                <i class="bi bi-plus-lg me-1"></i>Tambah Jam Istirahat
            </button>
        </div>
    </div>
    @endforeach

    <div class="d-flex gap-2 mb-4">
        <button type="submit" class="btn btn-primary btn-sm">
            <i class="bi bi-save me-1"></i>Simpan Perubahan
        </button>
        <a href="{{ route('master.shift.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
    </div>
</form>

@push('scripts')
<script>
function shiftForm() {
    return {
        days: { senin_kamis: [], jumat: [], sabtu: [] },
        init() {
            // Load existing data dari DB (via Blade PHP → JS)
            const existing = @json($istirahatByKel);
            // Fallback dari old() jika ada validation error
            @foreach(['senin_kamis','jumat','sabtu'] as $kel)
            @if(old("istirahat.{$kel}"))
            this.days['{{ $kel }}'] = @json(array_values(old("istirahat.{$kel}")));
            @else
            this.days['{{ $kel }}'] = existing['{{ $kel }}'] ?? [];
            @endif
            @endforeach
        },
        addRow(kel) {
            this.days[kel].push({ jam_start: '', jam_finish: '' });
        },
        removeRow(kel, idx) {
            this.days[kel].splice(idx, 1);
        },
    };
}
</script>
@endpush
@endsection
