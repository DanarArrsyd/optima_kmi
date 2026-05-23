@extends('layouts.app')
@section('title', 'LHP Produksi')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clipboard-plus me-2"></i>Input LHP Produksi</h4>
    <a href="{{ route('input.produksi.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Input Baru
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
        <table class="table table-hover table-sm mb-0">
            <thead class="table-dark">
                <tr>
                    <th>Tanggal</th><th>Shift</th><th>Operator</th><th>Mesin</th>
                    <th>Part / Proses</th><th>Jam</th><th>Qty Aktual</th><th>CT Aktual</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transaksi as $t)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($t->tanggal_produksi)->format('d/m/Y') }}</td>
                    <td>{{ $t->shift->nama_shift }}</td>
                    <td>{{ $t->karyawan->nama_karyawan }}</td>
                    <td>{{ $t->mesin->id_mesin }}</td>
                    <td>{{ $t->partProses->part->part_name }} <small class="text-muted">({{ $t->partProses->nama_proses }})</small></td>
                    <td><small>{{ $t->jam_start }} – {{ $t->jam_finish }}</small></td>
                    <td>{{ number_format($t->qty_aktual) }}</td>
                    <td>{{ $t->actual_cycle_time }}s</td>
                    <td>
                        <form method="POST" action="{{ route('input.produksi.destroy', $t) }}" class="d-inline"
                              onsubmit="return confirm('Hapus data ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="9" class="text-center text-muted py-3">Belum ada data LHP Produksi.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $transaksi->links() }}</div>
@endsection
