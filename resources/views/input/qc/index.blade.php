@extends('layouts.app')
@section('title', 'LHP Quality')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-patch-check me-2"></i>Input LHP Quality</h4>
    <a href="{{ route('input.qc.create') }}" class="btn btn-primary btn-sm">
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
                    <th>Tgl Cek</th><th>Tgl Produksi</th><th>Shift</th><th>Inspector</th>
                    <th>Part / Proses</th><th>Jam</th><th>Qty OK</th><th>Qty NG</th><th>CT Aktual</th><th>Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($transaksi as $t)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($t->tgl_pengecekan)->format('d/m/Y') }}</td>
                    <td>{{ \Carbon\Carbon::parse($t->tgl_produksi_part)->format('d/m/Y') }}</td>
                    <td>{{ $t->shift->nama_shift }}</td>
                    <td>{{ $t->karyawanQc->nama_karyawan }}</td>
                    <td>{{ $t->partProses->part->part_name }} <small class="text-muted">({{ $t->partProses->nama_proses }})</small></td>
                    <td><small>{{ $t->jam_start }} – {{ $t->jam_finish }}</small></td>
                    <td class="text-success fw-semibold">{{ number_format($t->qty_ok) }}</td>
                    <td class="text-danger fw-semibold">{{ number_format($t->qty_ng) }}</td>
                    <td>{{ $t->actual_cycle_time }}s</td>
                    <td>
                        <form method="POST" action="{{ route('input.qc.destroy', $t) }}" class="d-inline"
                              onsubmit="return confirm('Hapus data ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="10" class="text-center text-muted py-3">Belum ada data LHP Quality.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $transaksi->links() }}</div>
@endsection
