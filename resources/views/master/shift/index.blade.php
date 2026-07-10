@extends('layouts.app')
@section('title', 'Master Shift')
@section('suppress_layout_flash', 'yes')
@section('content')

{{-- ══ ALPINE SCOPE ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }} }">

{{-- ── DRAWER BACKDROP ──────────────────────────────────────────── --}}
<div x-show="drawerOpen"
     x-cloak
     @click="drawerOpen = false"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0"
     x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100"
     x-transition:leave-end="opacity-0"
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040">
</div>

{{-- ── DRAWER PANEL ─────────────────────────────────────────────── --}}
<div x-show="drawerOpen"
     x-cloak
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="transform translate-x-full"
     x-transition:enter-end="transform translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-x-0"
     x-transition:leave-end="transform translate-x-full"
     style="position:fixed;top:0;right:0;height:100dvh;width:700px;max-width:100vw;z-index:1050;">
    <div style="display:flex;flex-direction:column;height:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-calendar-week me-2"></i>Tambah Shift
        </div>
        <button type="button" @click="drawerOpen = false"
                style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;padding:0;line-height:1">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>

    {{-- Body (scrollable) --}}
    <div style="flex:1;min-height:0;overflow-y:auto;overflow-x:hidden;padding:24px;overscroll-behavior:contain">

        @if($errors->any())
        <div class="alert alert-danger mb-3" style="font-size:0.82rem">
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ route('master.shift.store') }}"
              x-data="shiftForm()" x-init="init()" novalidate>
            @csrf

            {{-- Nama Shift --}}
            <div class="mb-3">
                <label class="form-label" style="font-size:0.8rem">Nama Shift <span class="text-danger">*</span></label>
                <input type="text" name="nama_shift"
                       class="form-control form-control-sm @error('nama_shift') is-invalid @enderror"
                       value="{{ old('nama_shift') }}"
                       placeholder="Contoh: Shift Pagi">
                @error('nama_shift')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>

            {{-- Senin – Kamis --}}
            <div class="erp-panel mb-3">
                <div class="erp-panel-header" style="background:#1a6b7c;color:#fff">
                    <i class="bi bi-calendar3 me-1"></i>Senin – Kamis
                </div>
                <div class="erp-panel-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Masuk</label>
                            <input type="time" name="jam_start"
                                   class="form-control form-control-sm @error('jam_start') is-invalid @enderror"
                                   value="{{ old('jam_start', '07:00') }}">
                            @error('jam_start')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Pulang</label>
                            <input type="time" name="jam_finish"
                                   class="form-control form-control-sm @error('jam_finish') is-invalid @enderror"
                                   value="{{ old('jam_finish', '15:00') }}">
                            @error('jam_finish')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div style="font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:6px">
                        <i class="bi bi-cup-hot me-1"></i>Jam Istirahat
                    </div>
                    <template x-for="(row, idx) in days['senin_kamis']" :key="idx">
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-md-3">
                                <input type="time"
                                       :name="`istirahat[senin_kamis][${idx}][jam_start]`"
                                       x-model="row.jam_start"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-auto" style="color:#888;font-size:0.8rem">s/d</div>
                            <div class="col-md-3">
                                <input type="time"
                                       :name="`istirahat[senin_kamis][${idx}][jam_finish]`"
                                       x-model="row.jam_finish"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        @click="removeRow('senin_kamis', idx)"
                                        title="Hapus istirahat ini">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-1"
                            @click="addRow('senin_kamis')">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Jam Istirahat
                    </button>
                </div>
            </div>

            {{-- Jumat --}}
            <div class="erp-panel mb-3">
                <div class="erp-panel-header" style="background:#7a5c00;color:#fff">
                    <i class="bi bi-calendar3 me-1"></i>Jum'at
                </div>
                <div class="erp-panel-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Masuk</label>
                            <input type="time" name="jam_start_jumat"
                                   class="form-control form-control-sm @error('jam_start_jumat') is-invalid @enderror"
                                   value="{{ old('jam_start_jumat', '07:00') }}">
                            @error('jam_start_jumat')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Pulang</label>
                            <input type="time" name="jam_finish_jumat"
                                   class="form-control form-control-sm @error('jam_finish_jumat') is-invalid @enderror"
                                   value="{{ old('jam_finish_jumat', '15:30') }}">
                            @error('jam_finish_jumat')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div style="font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:6px">
                        <i class="bi bi-cup-hot me-1"></i>Jam Istirahat
                    </div>
                    <template x-for="(row, idx) in days['jumat']" :key="idx">
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-md-3">
                                <input type="time"
                                       :name="`istirahat[jumat][${idx}][jam_start]`"
                                       x-model="row.jam_start"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-auto" style="color:#888;font-size:0.8rem">s/d</div>
                            <div class="col-md-3">
                                <input type="time"
                                       :name="`istirahat[jumat][${idx}][jam_finish]`"
                                       x-model="row.jam_finish"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        @click="removeRow('jumat', idx)"
                                        title="Hapus istirahat ini">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-1"
                            @click="addRow('jumat')">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Jam Istirahat
                    </button>
                </div>
            </div>

            {{-- Sabtu --}}
            <div class="erp-panel mb-3">
                <div class="erp-panel-header" style="background:#5a3ea0;color:#fff">
                    <i class="bi bi-calendar3 me-1"></i>Sabtu
                </div>
                <div class="erp-panel-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Masuk</label>
                            <input type="time" name="jam_start_sabtu"
                                   class="form-control form-control-sm @error('jam_start_sabtu') is-invalid @enderror"
                                   value="{{ old('jam_start_sabtu', '07:00') }}">
                            @error('jam_start_sabtu')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" style="font-size:0.8rem;font-weight:600">Jam Pulang</label>
                            <input type="time" name="jam_finish_sabtu"
                                   class="form-control form-control-sm @error('jam_finish_sabtu') is-invalid @enderror"
                                   value="{{ old('jam_finish_sabtu', '12:15') }}">
                            @error('jam_finish_sabtu')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        </div>
                    </div>
                    <div style="font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:6px">
                        <i class="bi bi-cup-hot me-1"></i>Jam Istirahat
                    </div>
                    <template x-for="(row, idx) in days['sabtu']" :key="idx">
                        <div class="row g-2 mb-2 align-items-center">
                            <div class="col-md-3">
                                <input type="time"
                                       :name="`istirahat[sabtu][${idx}][jam_start]`"
                                       x-model="row.jam_start"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-auto" style="color:#888;font-size:0.8rem">s/d</div>
                            <div class="col-md-3">
                                <input type="time"
                                       :name="`istirahat[sabtu][${idx}][jam_finish]`"
                                       x-model="row.jam_finish"
                                       class="form-control form-control-sm">
                            </div>
                            <div class="col-auto">
                                <button type="button" class="btn btn-outline-danger btn-sm"
                                        @click="removeRow('sabtu', idx)"
                                        title="Hapus istirahat ini">
                                    <i class="bi bi-trash3"></i>
                                </button>
                            </div>
                        </div>
                    </template>
                    <button type="button" class="btn btn-outline-secondary btn-sm mt-1"
                            @click="addRow('sabtu')">
                        <i class="bi bi-plus-lg me-1"></i>Tambah Jam Istirahat
                    </button>
                </div>
            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan Shift
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary btn-sm">
                    Batal
                </button>
            </div>
        </form>
    </div>{{-- /drawer body --}}
    </div>{{-- /inner flex container --}}
</div>{{-- /drawer panel --}}

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-calendar-week"></i>Master Shift</h1>
    <div class="action-btns">
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Tambah
        </button>
        <button class="btn btn-outline-success btn-sm" onclick="document.getElementById('import-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3" role="alert">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
        {{ session('error') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Shift dari Excel</span>
        <a href="{{ route('master.shift.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.shift.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label mb-1" style="font-size:0.8rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control form-control-sm @error('file') is-invalid @enderror"
                           accept=".xlsx,.xls,.csv">
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Daftar Shift</div>
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 320px);min-height:200px">
        <table class="erp-table" style="min-width:820px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th style="width:140px">Nama Shift</th>
                    <th style="min-width:200px">
                        <span style="color:#1a6b7c">Senin – Kamis</span>
                    </th>
                    <th style="min-width:200px">
                        <span style="color:#7a5c00">Jum'at</span>
                    </th>
                    <th style="min-width:180px">
                        <span style="color:#5a3ea0">Sabtu</span>
                    </th>
                    <th style="width:100px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($shifts as $s)
                @php
                    $iSK = $s->istirahat->where('kelompok_hari','senin_kamis');
                    $iJm = $s->istirahat->where('kelompok_hari','jumat');
                    $iSb = $s->istirahat->where('kelompok_hari','sabtu');
                @endphp
                <tr>
                    <td style="font-weight:700">{{ $s->nama_shift }}</td>

                    {{-- Senin–Kamis --}}
                    <td>
                        <div style="font-weight:600;color:#1a6b7c">
                            {{ substr($s->jam_start,0,5) }} – {{ substr($s->jam_finish,0,5) }}
                        </div>
                        @foreach($iSK as $ist)
                            <div style="font-size:0.75rem;color:#6b7280">
                                <i class="bi bi-cup-hot" style="color:#bbb"></i>
                                {{ substr($ist->jam_start,0,5) }} – {{ substr($ist->jam_finish,0,5) }}
                            </div>
                        @endforeach
                    </td>

                    {{-- Jumat --}}
                    <td>
                        @if($s->jam_start_jumat)
                            <div style="font-weight:600;color:#7a5c00">
                                {{ substr($s->jam_start_jumat,0,5) }} – {{ substr($s->jam_finish_jumat,0,5) }}
                            </div>
                            @foreach($iJm as $ist)
                                <div style="font-size:0.75rem;color:#6b7280">
                                    <i class="bi bi-cup-hot" style="color:#bbb"></i>
                                    {{ substr($ist->jam_start,0,5) }} – {{ substr($ist->jam_finish,0,5) }}
                                </div>
                            @endforeach
                        @else
                            <span style="color:#ccc;font-size:0.8rem">—</span>
                        @endif
                    </td>

                    {{-- Sabtu --}}
                    <td>
                        @if($s->jam_start_sabtu)
                            <div style="font-weight:600;color:#5a3ea0">
                                {{ substr($s->jam_start_sabtu,0,5) }} – {{ substr($s->jam_finish_sabtu,0,5) }}
                            </div>
                            @foreach($iSb as $ist)
                                <div style="font-size:0.75rem;color:#6b7280">
                                    <i class="bi bi-cup-hot" style="color:#bbb"></i>
                                    {{ substr($ist->jam_start,0,5) }} – {{ substr($ist->jam_finish,0,5) }}
                                </div>
                            @endforeach
                        @else
                            <span style="color:#ccc;font-size:0.8rem">—</span>
                        @endif
                    </td>

                    <td>
                        <div class="action-btns">
                            <a href="{{ route('master.shift.edit', $s) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('master.shift.destroy', $s) }}"
                                  onsubmit="return confirm('Hapus shift {{ $s->nama_shift }}?')">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" style="text-align:center;color:#888;padding:20px">
                        Belum ada data shift.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $shifts->links() }}</div>

</div>{{-- /alpine outer scope --}}

@push('scripts')
<script>
function shiftForm() {
    return {
        days: { senin_kamis: [], jumat: [], sabtu: [] },
        init() {
            @if(old('istirahat.senin_kamis'))
            this.days.senin_kamis = {!! json_encode(array_values(old('istirahat.senin_kamis'))) !!};
            @else
            this.days.senin_kamis = [
                { jam_start: '10:00', jam_finish: '10:15' },
                { jam_start: '12:00', jam_finish: '12:45' },
            ];
            @endif

            @if(old('istirahat.jumat'))
            this.days.jumat = {!! json_encode(array_values(old('istirahat.jumat'))) !!};
            @else
            this.days.jumat = [
                { jam_start: '10:00', jam_finish: '10:15' },
                { jam_start: '11:45', jam_finish: '13:00' },
            ];
            @endif

            @if(old('istirahat.sabtu'))
            this.days.sabtu = {!! json_encode(array_values(old('istirahat.sabtu'))) !!};
            @else
            this.days.sabtu = [
                { jam_start: '10:00', jam_finish: '10:15' },
            ];
            @endif
        },
        addRow(kel) { this.days[kel].push({ jam_start: '', jam_finish: '' }); },
        removeRow(kel, idx) { this.days[kel].splice(idx, 1); },
    };
}
</script>
@endpush
@endsection
