@extends('layouts.app')
@section('title', 'Manajemen Users')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-person-gear me-2"></i>Manajemen Users</h4>
    <a href="{{ route('master.users.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah User
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr><th>Nama</th><th>Email</th><th>Role</th><th>Karyawan</th><th width="120">Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($users as $u)
                @php
                    $badgeClass = match($u->role) {
                        'admin'         => 'bg-danger',
                        'staff_produksi'=> 'bg-primary',
                        'staff_qc'      => 'bg-info text-dark',
                        'manager'       => 'bg-success',
                        default         => 'bg-secondary',
                    };
                @endphp
                <tr>
                    <td>{{ $u->name }}</td>
                    <td>{{ $u->email }}</td>
                    <td><span class="badge {{ $badgeClass }}">{{ strtoupper(str_replace('_', ' ', $u->role)) }}</span></td>
                    <td>{!! $u->karyawan?->nama_karyawan ?? '<span class="text-muted">-</span>' !!}</td>
                    <td>
                        <a href="{{ route('master.users.edit', $u) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                        @if($u->id !== auth()->id())
                        <form method="POST" action="{{ route('master.users.destroy', $u) }}" class="d-inline"
                              onsubmit="return confirm('Hapus user ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3">Belum ada user.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $users->links() }}</div>
@endsection
