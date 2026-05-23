@extends('layouts.app')
@section('title', 'Master Karyawan')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Master Karyawan</h4>
    <a href="{{ route('master.karyawan.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Karyawan
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr><th>NIK</th><th>Nama</th><th>Departemen</th><th width="120">Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($karyawan as $k)
                <tr>
                    <td>{{ $k->nik }}</td>
                    <td>{{ $k->nama_karyawan }}</td>
                    <td><span class="badge {{ $k->departemen === 'QC' ? 'bg-info' : 'bg-primary' }}">{{ $k->departemen }}</span></td>
                    <td>
                        <a href="{{ route('master.karyawan.edit', $k) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                        <form method="POST" action="{{ route('master.karyawan.destroy', $k) }}" class="d-inline"
                              onsubmit="return confirm('Hapus karyawan ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $karyawan->links() }}</div>
@endsection
