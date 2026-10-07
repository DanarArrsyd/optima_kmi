@extends('layouts.app')
@section('title', 'Manajemen Users')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

{{-- ══ ALPINE SCOPE ══ --}}
<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.users.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterRole !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    {{-- Ekspor membawa filter yang sedang aktif — isi file = isi tabel. --}}
    <a href="{{ route('master.users.export', request()->query()) }}" class="erp-tb-btn">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </a>
    <a href="{{ route('master.users.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('master.users.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks :value="$search" placeholder="Nama atau Email" />
        <x-filter.cari name="filter_role" placeholder="Role" :value="$filterRole"
                       :items="collect($roleList)->map(fn ($label, $kode) => ['v' => $kode, 't' => $label])" />
    </x-filter.grup>
</x-filter.dock>

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
                <th style="width:90px">Status</th>
                <th style="width:220px">Aksi</th>
            </tr>
        </thead>
        <tbody>
        @forelse($users as $u)
            <tr style="{{ !$u->is_aktif ? 'opacity:0.55;background:#f9fafb' : '' }}">
                <td>{{ $u->name }}</td>
                <td>{{ $u->email }}</td>
                <td>{{ collect($u->roles ?? [])->map(fn ($r) => strtoupper(str_replace('_', ' ', $r)))->implode(', ') }}</td>
                <td>{{ $u->karyawan?->nama_karyawan ?? '—' }}</td>
                <td>
                    @if($u->is_aktif)
                        <span class="badge bg-success">Aktif</span>
                    @else
                        <span class="badge bg-secondary">Nonaktif</span>
                    @endif
                </td>
                <td>
                    <div class="action-btns">
                        <a href="{{ route('master.users.edit', $u) }}" class="btn btn-warning btn-sm">Edit</a>
                        @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('master.users.toggle-aktif', $u) }}"
                              onsubmit="return confirm('{{ $u->is_aktif ? 'Nonaktifkan' : 'Aktifkan kembali' }} user ini?')">
                            @csrf @method('PATCH')
                            <button class="btn btn-sm {{ $u->is_aktif ? 'btn-outline-secondary' : 'btn-outline-success' }}">
                                {{ $u->is_aktif ? 'Nonaktifkan' : 'Aktifkan' }}
                            </button>
                        </form>
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
            <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Belum ada user.</td></tr>
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
