@extends('layouts.app')
@section('title', 'Backup Sistem')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <form method="POST" action="{{ route('sistem.backup.jalankan') }}" style="margin:0"
          onsubmit="return confirm('Jalankan backup sekarang?')">
        @csrf
        <button type="submit" class="erp-tb-btn is-new">
            <i class="bi bi-download"></i>Backup Now
        </button>
    </form>
    <a href="{{ route('sistem.backup.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; jumlah arsip sudah di status bar. --}}
<div class="erp-scroll erp-scroll-pad">

@php
    $bahaya = $umurJam === null || $umurJam > 48;
@endphp
<div style="background:{{ $bahaya ? '#fef2f2' : '#f0fdf4' }};border:1px solid {{ $bahaya ? '#b91c1c' : '#d1d5db' }};border-radius:2px;padding:12px 14px;margin-bottom:14px">
    <div style="font-size:9.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
        <i class="bi bi-clock-history me-1"></i>Backup Terakhir
    </div>
    <div style="font-size:26px;font-weight:700;line-height:1;color:{{ $bahaya ? '#b91c1c' : '#111827' }}">
        @if($umurJam === null)
            Belum pernah
        @else
            {{ number_format($umurJam, 1) }} jam lalu
        @endif
    </div>
</div>

<table class="erp-table" style="min-width:820px;width:100%">
    <thead style="position:sticky;top:0;z-index:10">
        <tr>
            <th style="width:150px;white-space:nowrap">Waktu (WIB)</th>
            <th style="width:100px;white-space:nowrap">Sumber</th>
            <th style="width:100px;white-space:nowrap">Ukuran</th>
            <th style="width:100px;white-space:nowrap">Jumlah Tabel</th>
            <th>Dipicu Oleh</th>
            <th style="width:180px;white-space:nowrap">Aksi</th>
        </tr>
    </thead>
    <tbody>
    @forelse($daftar as $b)
        <tr>
            <td style="white-space:nowrap">{{ $b['waktu_wib'] ?? '—' }}</td>
            <td style="white-space:nowrap">{{ $b['sumber'] ?? '—' }}</td>
            <td style="white-space:nowrap">{{ number_format($b['ukuran'] / 1048576, 2) }} MB</td>
            <td style="white-space:nowrap">{{ $b['jumlah_tabel'] ?? '—' }}</td>
            <td>
                {{ $b['dipicu_oleh_nama'] ?? '—' }}
                <div style="font-family:monospace;font-size:10.5px;color:#9ca3af">{{ $b['nama'] }}</div>
            </td>
            <td style="white-space:nowrap">
                <div class="action-btns" style="flex-wrap:nowrap">
                    <a href="{{ route('sistem.backup.unduh', $b['nama']) }}" class="btn btn-primary btn-sm">Unduh</a>
                    <form method="POST" action="{{ route('sistem.backup.hapus', $b['nama']) }}"
                          onsubmit="return confirm('Hapus arsip {{ $b['nama'] }}? Tidak bisa dibatalkan.')">
                        @csrf @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                    </form>
                </div>
            </td>
        </tr>
    @empty
        <tr><td colspan="6" style="text-align:center;color:#888;padding:20px">Belum ada arsip backup.</td></tr>
    @endforelse
    </tbody>
</table>

</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ count($daftar) }}</b> arsip</span>
    <span>Retensi 30 terbaru</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection
