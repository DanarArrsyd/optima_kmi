@extends('layouts.app')
@section('title', 'Master Loss Time')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-exclamation-triangle me-2"></i>Master Loss Time</h4>
    <a href="{{ route('master.losstime.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Loss Time
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
                    <th>Kode</th>
                    <th>Jenis Loss Time</th>
                    <th>Std Menit Default</th>
                    <th>Std Menit Progressive</th>
                    <th width="120">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($losstimes as $lt)
                <tr>
                    <td><code>{{ $lt->kode }}</code></td>
                    <td>{{ $lt->jenis_losstime }}</td>
                    <td>{!! $lt->std_menit_default ?? '<span class="text-muted fst-italic">full loss</span>' !!}</td>
                    <td>{!! $lt->std_menit_progressive ?? '<span class="text-muted fst-italic">full loss</span>' !!}</td>
                    <td>
                        <a href="{{ route('master.losstime.edit', $lt) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                        <form method="POST" action="{{ route('master.losstime.destroy', $lt) }}" class="d-inline"
                              onsubmit="return confirm('Hapus loss time ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $losstimes->links() }}</div>
@endsection
