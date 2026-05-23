@extends('layouts.app')
@section('title', 'Master Shift')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clock me-2"></i>Master Shift</h4>
    <a href="{{ route('master.shift.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Shift
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
                    <th>Nama Shift</th>
                    <th>Jam Start</th>
                    <th>Jam Finish</th>
                    <th width="120">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($shifts as $s)
                <tr>
                    <td>{{ $s->nama_shift }}</td>
                    <td>{{ $s->jam_start }}</td>
                    <td>{{ $s->jam_finish }}</td>
                    <td>
                        <a href="{{ route('master.shift.edit', $s) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                        <form method="POST" action="{{ route('master.shift.destroy', $s) }}" class="d-inline"
                              onsubmit="return confirm('Hapus shift ini?')">
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
<div class="mt-3">{{ $shifts->links() }}</div>
@endsection
