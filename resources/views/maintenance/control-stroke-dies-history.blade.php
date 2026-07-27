@extends('layouts.app')
@section('title', 'Riwayat Preventive Dies')

@php
    $part     = $partProses->part;
    $totalPrev = $logs->count();
    $lastPrev  = $logs->first();
@endphp

@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-clock-history"></i>Riwayat Preventive Dies</h1>
    <a href="{{ route('maintenance.control-stroke-dies') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

{{-- Identitas dies + kondisi sekarang --}}
<div class="row g-3 mb-4">
    <div class="col-md-7">
        <div class="erp-panel h-100">
            <div class="erp-panel-header">Dies</div>
            <div class="erp-panel-body">
                <div style="font-size:1.15rem;font-weight:700">{{ $part?->part_no }}</div>
                <div class="text-muted">{{ $part?->part_name }}</div>
                <div class="mt-2 d-flex gap-4 flex-wrap" style="font-size:13px">
                    <div><span class="text-muted">Proses:</span> <strong>{{ $partProses->nama_proses }}</strong></div>
                    <div><span class="text-muted">Customer:</span> <strong>{{ $part?->customer ?? '—' }}</strong></div>
                </div>
                <div class="mt-3">
                    @include('maintenance.partials.stroke-summary', [
                        'currentStroke' => $currentStroke,
                        'threshold'     => $threshold,
                    ])
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-5">
        <div class="erp-panel h-100">
            <div class="erp-panel-header">Ringkasan Preventive</div>
            <div class="erp-panel-body">
                <div class="mb-3">
                    <div class="text-muted small">Total Preventive</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1">{{ $totalPrev }}</div>
                    <div class="text-muted" style="font-size:11px">kali sejak dies dipakai</div>
                </div>
                <div>
                    <div class="text-muted small">Preventive Terakhir</div>
                    @if($lastPrev)
                        <div style="font-weight:700">{{ $lastPrev->tanggal_preventive->format('d M Y') }}</div>
                        <div class="text-muted" style="font-size:12px">
                            oleh {{ $lastPrev->karyawan?->nama_karyawan ?? '—' }} ·
                            di {{ number_format($lastPrev->stroke_at_preventive, 0, ',', '.') }} stroke
                        </div>
                    @else
                        <div class="text-muted">Belum pernah dipreventive</div>
                    @endif
                </div>
                @if(in_array(auth()->user()->role, ['admin', 'staff_maintenance']))
                    <a href="{{ route('maintenance.control-stroke-dies.preventive.create', $partProses) }}"
                       class="btn btn-primary btn-sm mt-3">
                        <i class="bi bi-tools me-1"></i>Input Preventive Baru
                    </a>
                @endif
            </div>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Riwayat Preventive</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Tanggal</th><th>PIC / Petugas</th>
                        <th class="text-end">Stroke Tercapai</th>
                        <th>Catatan</th><th>Checksheet</th><th>Diinput Oleh</th>
                    </tr>
                </thead>
                <tbody>
                @forelse($logs as $log)
                    <tr>
                        <td style="white-space:nowrap;font-weight:600">{{ $log->tanggal_preventive->format('d M Y') }}</td>
                        <td style="white-space:nowrap">{{ $log->karyawan?->nama_karyawan ?? '—' }}</td>
                        <td class="text-end" style="white-space:nowrap">{{ number_format($log->stroke_at_preventive, 0, ',', '.') }}</td>
                        <td>{{ $log->catatan ?? '—' }}</td>
                        <td style="white-space:nowrap">
                            @if($log->checksheet_path && ! auth()->user()->isGuest())
                                <a href="{{ route('maintenance.control-stroke-dies.preventive.checksheet', $log) }}" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-pdf"></i> Lihat PDF
                                </a>
                            @else
                                —
                            @endif
                        </td>
                        <td style="white-space:nowrap">{{ $log->createdBy?->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            <i class="bi bi-clock-history d-block mb-2" style="font-size:1.6rem;color:#9ca3af"></i>
                            Belum ada riwayat preventive untuk dies ini.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
