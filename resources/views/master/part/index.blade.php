@extends('layouts.app')
@section('title', 'Master Part / Dies')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>Master Part / Dies</h4>
    <a href="{{ route('master.part.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Part
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
                <tr><th>Part No</th><th>Part Name</th><th>Jml Proses</th><th width="140">Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($parts as $part)
                <tr>
                    <td><code>{{ $part->part_no }}</code></td>
                    <td>{{ $part->part_name }}</td>
                    <td><span class="badge bg-secondary">{{ $part->proses_count }} proses</span></td>
                    <td>
                        <a href="{{ route('master.part.edit', $part) }}" class="btn btn-sm btn-outline-warning">Edit & Proses</a>
                        <form method="POST" action="{{ route('master.part.destroy', $part) }}" class="d-inline"
                              onsubmit="return confirm('Hapus part ini beserta semua proses?')">
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
<div class="mt-3">{{ $parts->links() }}</div>
@endsection
