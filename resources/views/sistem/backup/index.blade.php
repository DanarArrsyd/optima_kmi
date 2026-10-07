@extends('layouts.app')
@section('title', 'Backup Sistem')

{{--
    SENGAJA BUKAN halaman .erp-ws.

    Pola .erp-ws dipakai 27 halaman daftar/analitik: ruang kerja padat untuk
    operator menyisir ratusan baris tiap hari. Halaman ini genrenya lain —
    setelan admin yang jarang dibuka, isinya paling banyak 30 baris, dan yang
    dibutuhkan pembacanya bukan kepadatan melainkan konteks: apa isi berkas
    ini, dan apa yang tidak boleh dilakukan padanya.

    Karena itu ia tidak terdaftar di ErpWorkspaceMarkupTest. Keseimbangan
    <div>-nya dijaga terpisah di BackupHalamanTest.
--}}

@section('content')

@php
    $bahaya = $umurJam === null || $umurJam > 48;
@endphp

{{-- ══ KEPALA HALAMAN ═══════════════════════════════════════════ --}}
<div style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin-bottom:18px">
    <div>
        <h1 style="font-size:22px;font-weight:700;color:var(--text);margin:0 0 4px">Backup Database</h1>
        <div style="font-size:12.5px;color:var(--text-3)">
            Salinan seluruh basis data OPTIMA, termasuk data karyawan dan akun pengguna.
        </div>
        <div style="font-size:12.5px;margin-top:6px;color:{{ $bahaya ? 'var(--c-danger)' : 'var(--text-3)' }};font-weight:{{ $bahaya ? '700' : '400' }}">
            <i class="bi bi-clock-history me-1"></i>Backup terakhir:
            @if($umurJam === null)
                Belum pernah
            @else
                {{ number_format($umurJam, 1) }} jam lalu
            @endif
        </div>
    </div>

    <form method="POST" action="{{ route('sistem.backup.jalankan') }}" style="margin:0"
          data-konfirmasi="Jalankan backup sekarang?">
        @csrf
        <button type="submit" class="btn btn-primary">
            <i class="bi bi-download me-1"></i>Buat backup sekarang
        </button>
    </form>
</div>

{{--
    Aturan penanganannya ditulis DI HALAMAN, bukan cuma di dokumen — yang
    mengunduh berkas ini adalah orang yang perlu tahu, dan dia ada di sini,
    bukan di repo.

    Tidak ada butir "setiap unduhan tercatat di jejak audit": OPTIMA belum
    mencatatnya. Menulisnya di layar akan jadi klaim palsu yang justru
    membuat orang lebih berani.
--}}
<div style="background:var(--surface);border:1px solid var(--border);border-left:4px solid var(--c-danger);padding:14px 16px;margin-bottom:18px">
    <h2 style="font-size:13px;font-weight:700;color:var(--c-danger);margin:0">Satu berkas ini memuat seluruh database</h2>
    <ul style="margin:8px 0 0;padding-left:18px;font-size:12px;color:var(--text-3);line-height:1.7">
        <li>Seluruh data karyawan, seluruh LHP, dan tabel <code>users</code> berikut hash kata sandinya.</li>
        <li>Disimpan di disk privat; berkasnya tidak pernah punya URL publik.</li>
        <li><span style="font-weight:700">Jangan pernah dikirim lewat email</span> atau ditaruh di folder yang bisa diakses web.</li>
        <li>Halaman ini sengaja <span style="font-weight:700">tidak punya tombol Restore</span> — pemulihan dilakukan manual lewat phpMyAdmin, supaya tidak ada satu klik pun yang bisa menimpa produksi.</li>
        <li>Backup yang cuma tersimpan di server yang sama dengan datanya belum bisa disebut backup — <span style="font-weight:700">salin ke luar server</span>.</li>
    </ul>
</div>

{{-- ══ DAFTAR ARSIP ═════════════════════════════════════════════ --}}
<div style="border:1px solid var(--border);background:var(--surface);overflow-x:auto">
    <table class="erp-table" style="width:100%;min-width:820px">
        <thead>
            <tr>
                <th style="white-space:nowrap">Nama Berkas</th>
                <th style="width:150px;white-space:nowrap">Dibuat (WIB)</th>
                <th style="width:100px;white-space:nowrap">Sumber</th>
                <th style="width:100px;white-space:nowrap;text-align:right">Ukuran</th>
                <th style="width:110px;white-space:nowrap;text-align:right">Jumlah Tabel</th>
                <th style="width:170px;white-space:nowrap">Dipicu Oleh</th>
                <th style="width:150px;white-space:nowrap"></th>
            </tr>
        </thead>
        <tbody>
        @forelse($daftar as $b)
            <tr>
                <td style="font-family:monospace;white-space:nowrap">{{ $b['nama'] }}</td>
                <td style="white-space:nowrap;color:var(--text-3)">{{ $b['waktu_wib'] ?? '—' }}</td>
                <td style="white-space:nowrap">{{ $b['sumber'] ?? '—' }}</td>
                <td style="white-space:nowrap;text-align:right">{{ number_format($b['ukuran'] / 1048576, 2) }} MB</td>
                <td style="white-space:nowrap;text-align:right">{{ $b['jumlah_tabel'] ?? '—' }}</td>
                <td style="white-space:nowrap">{{ $b['dipicu_oleh_nama'] ?? '—' }}</td>
                <td style="white-space:nowrap;text-align:right">
                    <div class="action-btns" style="flex-wrap:nowrap;justify-content:flex-end">
                        <a href="{{ route('sistem.backup.unduh', $b['nama']) }}" class="btn btn-primary btn-sm">Unduh</a>
                        <form method="POST" action="{{ route('sistem.backup.hapus', $b['nama']) }}"
                              data-konfirmasi="Hapus arsip {{ $b['nama'] }}? Tidak bisa dibatalkan.">
                            @csrf @method('DELETE')
                            <button type="submit" class="btn btn-danger btn-sm">Hapus</button>
                        </form>
                    </div>
                </td>
            </tr>
        @empty
            <tr>
                <td colspan="7" style="text-align:center;color:var(--text-3);padding:26px 20px;line-height:1.7">
                    <div style="font-weight:700;color:var(--text);margin-bottom:4px">Belum ada arsip backup</div>
                    Buat yang pertama dengan tombol di atas. Sesudah itu backup berjalan sendiri
                    tiap malam pukul 23:00 WIB.
                </td>
            </tr>
        @endforelse
        </tbody>
    </table>
</div>

<div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:10px;font-size:11.5px;color:var(--text-3)">
    <span><b>{{ count($daftar) }}</b> arsip tersimpan &middot; retensi 30 terbaru, sisanya dipangkas otomatis</span>
    <span>Jadwal harian 23:00 WIB</span>
</div>

@endsection
