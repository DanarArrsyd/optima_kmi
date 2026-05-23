@extends('layouts.app')
@section('title', 'Master Mesin')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-cpu me-2"></i>Master Mesin</h4>
    <a href="{{ route('master.mesin.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Mesin
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        {{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr>
                    <th>ID Mesin</th>
                    <th>Nama</th>
                    <th>Line</th>
                    <th>Tipe</th>
                    <th>Kapasitas (stroke/jam)</th>
                    <th width="120">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($mesin as $m)
                <tr>
                    <td><code>{{ $m->id_mesin }}</code></td>
                    <td>{{ $m->nama_mesin }}</td>
                    <td>{{ $m->line }}</td>
                    <td>
                        <span class="badge {{ $m->tipe_mesin === 'PROGRESSIVE' ? 'bg-success' : 'bg-warning text-dark' }}">
                            {{ $m->tipe_mesin }}
                        </span>
                    </td>
                    <td>{{ $m->kapasitas_max_stroke ?? '-' }}</td>
                    <td>
                        <a href="{{ route('master.mesin.edit', $m) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                        <form method="POST" action="{{ route('master.mesin.destroy', $m) }}" class="d-inline"
                              onsubmit="return confirm('Hapus mesin ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $mesin->links() }}</div>
@endsection
