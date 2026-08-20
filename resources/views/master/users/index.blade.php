@extends('layouts.app')
@section('title', 'Manajemen Users')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

{{-- ══ ALPINE SCOPE ══ --}}
<div x-data="{ drawerOpen: {{ $errors->any() ? 'true' : 'false' }}, filterOpen: false }">

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
     style="position:fixed;top:0;right:0;height:100dvh;width:480px;max-width:100vw;z-index:1050;">
    <div style="display:flex;flex-direction:column;height:100%;background:#fff;box-shadow:-4px 0 24px rgba(0,0,0,.18)">

    {{-- Header --}}
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem;letter-spacing:.02em">
            <i class="bi bi-person-plus me-2"></i>Tambah User
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

        <form method="POST" action="{{ route('master.users.store') }}" novalidate>
            @csrf
            <div class="row g-3">

                {{-- Nama --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Nama <span class="text-danger">*</span></label>
                    <input type="text" name="name"
                           class="form-control form-control-sm @error('name') is-invalid @enderror"
                           value="{{ old('name') }}"
                           placeholder="Nama lengkap">
                    @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Email --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Email <span class="text-danger">*</span></label>
                    <input type="email" name="email"
                           class="form-control form-control-sm @error('email') is-invalid @enderror"
                           value="{{ old('email') }}"
                           placeholder="email@perusahaan.com">
                    @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Password --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Password <span class="text-danger">*</span></label>
                    <input type="password" name="password"
                           class="form-control form-control-sm @error('password') is-invalid @enderror"
                           placeholder="Minimal 8 karakter">
                    @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Role --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Role <span class="text-danger">*</span></label>
                    <select name="role" class="form-select form-select-sm @error('role') is-invalid @enderror">
                        <option value="">-- Pilih Role --</option>
                        <option value="admin"          {{ old('role') === 'admin'          ? 'selected' : '' }}>Admin</option>
                        <option value="staff_produksi"    {{ old('role') === 'staff_produksi'    ? 'selected' : '' }}>Staff Produksi</option>
                        <option value="staff_qc"          {{ old('role') === 'staff_qc'          ? 'selected' : '' }}>Staff QC</option>
                        <option value="staff_maintenance" {{ old('role') === 'staff_maintenance' ? 'selected' : '' }}>Staff Maintenance</option>
                        <option value="manager"           {{ old('role') === 'manager'           ? 'selected' : '' }}>Manager</option>
                        <option value="guest"             {{ old('role') === 'guest'             ? 'selected' : '' }}>Guest (Read-Only)</option>
                    </select>
                    @error('role')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

                {{-- Tautkan ke Karyawan (optional) --}}
                <div class="col-12">
                    <label class="form-label" style="font-size:0.8rem">Tautkan ke Karyawan <span class="text-muted">(opsional)</span></label>
                    <select name="id_karyawan" class="form-select form-select-sm @error('id_karyawan') is-invalid @enderror">
                        <option value="">-- Tidak ditautkan --</option>
                        @foreach($karyawan as $k)
                            <option value="{{ $k->id }}" {{ old('id_karyawan') == $k->id ? 'selected' : '' }}>
                                {{ $k->nama_karyawan }}
                            </option>
                        @endforeach
                    </select>
                    @error('id_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>

            </div>

            <div style="margin-top:20px;padding-top:16px;border-top:1px solid #e5e7eb;display:flex;gap:8px">
                <button type="submit" class="btn btn-primary btn-sm" style="flex:1">
                    <i class="bi bi-save me-1"></i>Simpan User
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

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn is-new" @click="drawerOpen = true">
        <i class="bi bi-plus-lg"></i>Tambah
    </button>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterRole !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <a href="{{ route('master.users.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" x-show="filterOpen" x-cloak>
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" @click="filterOpen = false" title="Tutup filter">&times;</button>
    </div>
    <form method="GET" action="{{ route('master.users.index') }}" class="erp-dock-body">
        <div class="erp-grp">
            <b>Find</b>
            <input type="text" name="q" value="{{ $search }}" class="erp-in @if($search !== '') is-set @endif"
                   placeholder="Nama atau Email">
            <select name="filter_role" class="erp-in @if($filterRole !== '') is-set @endif">
                <option value="">Semua Role</option>
                @foreach($roleList as $o)
                <option value="{{ $o }}" @selected($filterRole === $o)>{{ $o }}</option>
                @endforeach
            </select>
        </div>

        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-funnel"></i>Tampilkan</button>
            <a href="{{ route('master.users.index') }}" class="erp-btn">Reset</a>
        </div>
    </form>
</aside>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">



    <table class="erp-table">
        <thead>
            <tr>
                <th>Nama</th>
                <th>Email</th>
                <th>Role</th>
                <th>Karyawan</th>
                <th style="width:130px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($users as $u)
            <tr>
                <td>{{ $u->name }}</td>
                <td>{{ $u->email }}</td>
                <td>{{ strtoupper(str_replace('_', ' ', $u->role)) }}</td>
                <td>{{ $u->karyawan?->nama_karyawan ?? '—' }}</td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('master.users.edit', $u) }}" class="btn btn-warning btn-sm">Edit</a>
                        @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('master.users.destroy', $u) }}"
                              onsubmit="return confirm('Hapus user ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                        @endif
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="text-align:center;color:#888;padding:20px">Belum ada user.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $users->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($users->total()) }}</b> akun</span>
    @if($search !== '' || $filterRole !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine outer scope --}}
@endsection
