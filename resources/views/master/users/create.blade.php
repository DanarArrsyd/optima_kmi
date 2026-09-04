@extends('layouts.app')
@section('title', 'Tambah User')
@section('content')
@php
/* Typeahead karyawan — pakai filterSearchComp generik (komponen bersama di
   layouts/app.blade.php), sama seperti dipakai dock filter Mesin/PIC di
   modul lain. <select> native lama nampilin SELURUH karyawan sekaligus. */
$_karyawanTA = $karyawan->map(fn ($k) => ['v' => $k->id, 't' => $k->nik.' — '.$k->nama_karyawan])->values();
$_karyawanIdInit = (int) old('id_karyawan', 0);
$_karyawanLabelInit = $_karyawanIdInit
    ? optional($karyawan->firstWhere('id', $_karyawanIdInit))->nik.' — '.optional($karyawan->firstWhere('id', $_karyawanIdInit))->nama_karyawan
    : '';
@endphp
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-person-gear"></i>Tambah User</h1>
    <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger mb-3">
        <ul class="mb-0">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
    </div>
@endif

<div class="erp-panel" style="max-width:500px">
    <div class="erp-panel-header">Form Tambah User</div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.users.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Nama <span class="text-danger">*</span></label>
                <input type="text" name="name" class="form-control @error('name') is-invalid @enderror"
                       value="{{ old('name') }}">
                @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Email <span class="text-danger">*</span></label>
                <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
                       value="{{ old('email') }}">
                @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Password <span class="text-danger">*</span></label>
                <input type="password" name="password" class="form-control @error('password') is-invalid @enderror">
                @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            {{-- Role tunggal di sini adalah sisa UI SEBELUM migrasi multi-role
                 (PR #135) — StoreMasterUserRequest sekarang mewajibkan
                 `roles[]` array, jadi field `role` lama ini nol pernah lolos
                 validasi. Halaman ini sempat sama sekali nol bisa dipakai
                 buat bikin user baru. Diganti partial yang sama dipakai
                 drawer & edit.blade.php. --}}
            <div class="mb-3">
                @include('master.users.partials.roles-fields', ['selectedRoles' => old('roles', [])])
            </div>
            <div class="mb-4" x-data="filterSearchComp(_karyawanTA, {{ $_karyawanIdInit }}, '{{ addslashes($_karyawanLabelInit) }}')">
                <label class="form-label">Tautkan ke Karyawan (opsional)</label>
                <div @click.outside="open=false" style="position:relative">
                    <input type="hidden" name="id_karyawan" :value="val">
                    <input type="text" x-model="query" @focus="open=true" @input="onInput()"
                           @keydown.escape="open=false" placeholder="Ketik nama/NIK karyawan..."
                           class="form-control @error('id_karyawan') is-invalid @enderror">
                    <button type="button" x-show="val" @mousedown.prevent="clear()" class="erp-clr">&times;</button>
                    <div x-show="open && filtered.length > 0" x-cloak class="erp-dd">
                        <template x-for="item in filtered" :key="item.v">
                            <div @mousedown.prevent="pick(item)" class="erp-dd-item" x-text="item.t"></div>
                        </template>
                    </div>
                </div>
                @error('id_karyawan')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
            </div>
            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan</button>
                <a href="{{ route('master.users.index') }}" class="btn btn-outline-secondary btn-sm">Batal</a>
            </div>
        </form>
    </div>
</div>
@endsection

@push('scripts')
<script>
const _karyawanTA = @json($_karyawanTA);
</script>
@endpush
