@extends('layouts.app')
@section('title', 'Detail LHP Produksi')
@section('content')

@php
    $pp     = $t->partProses;
    $part   = $pp?->part;
    $sphStd = (int) ($pp?->sph_standar_produksi ?? 0);
    $sphAct = $t->sph_aktual;
    $pct    = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
    $wib    = fn ($waktu) => $waktu?->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
    $orang  = fn ($user, $waktu) => $waktu ? trim(($user?->name ?? '—').' · '.$wib($waktu)) : null;

    /* Rincian NG multi-jenis; data lama (sebelum tabel rincian) cuma punya
       satu id_jenis_ng di baris induk. */
    $rincianNg = $t->relationLoaded('ngDetails') ? $t->ngDetails : collect();
    $teksNg = $rincianNg->isNotEmpty()
        ? $rincianNg->map(fn ($d) => trim(($d->jenisNg?->kode ?? '?').' — '.($d->jenisNg?->nama_ng ?? '')).': '.number_format($d->qty).' pcs')->implode("\n")
        : ($t->jenisNg ? trim($t->jenisNg->kode.' — '.$t->jenisNg->nama_ng) : null);

    $bagian = [
        'Identitas' => [
            ['label' => 'Tanggal Produksi', 'nilai' => \Carbon\Carbon::parse($t->tanggal_produksi)->format('d/m/Y')],
            ['label' => 'Shift', 'nilai' => $t->shift?->nama_shift],
            ['label' => 'Operator', 'nilai' => $t->karyawan ? trim($t->karyawan->nama_karyawan.($t->karyawan->nik ? ' ('.$t->karyawan->nik.')' : '')) : null],
            ['label' => 'Mesin', 'nilai' => $t->mesin ? $t->mesin->id_mesin.' — '.$t->mesin->nama_mesin : null],
            ['label' => 'Line', 'nilai' => $t->mesin?->line],
        ],
        'Part & Proses' => [
            ['label' => 'Customer', 'nilai' => $part?->customer],
            ['label' => 'Part No.', 'nilai' => $part?->part_no],
            ['label' => 'Part Name', 'nilai' => $part?->part_name],
            ['label' => 'ID Proses', 'nilai' => $pp?->id_proses],
            ['label' => 'Proses', 'nilai' => $pp?->nama_proses],
        ],
        'Hasil Produksi' => [
            ['label' => 'Jam', 'nilai' => $t->jam_start.' – '.$t->jam_finish],
            ['label' => 'Waktu Kerja', 'nilai' => number_format($t->waktu_kerja_jam, 2).' jam'],
            ['label' => 'Qty Aktual (baik)', 'nilai' => number_format((int) $t->qty_aktual).' pcs'],
            ['label' => 'Qty NG', 'nilai' => number_format((int) $t->qty_ng).' pcs'],
            ['label' => 'SPH Standar', 'nilai' => number_format($sphStd)],
            ['label' => 'SPH Aktual', 'nilai' => number_format($sphAct)],
            ['label' => 'Achievement', 'nilai' => $pct !== null ? $pct.'%' : null],
            ['label' => 'Cycle Time Aktual', 'nilai' => number_format((float) $t->actual_cycle_time, 1).' s'],
            ['label' => 'Rincian NG', 'nilai' => $teksNg, 'panjang' => true],
        ],
    ];

    /* Catatan staff tidak tampil di daftar untuk guest; samakan di sini. */
    if (! auth()->user()->isGuest()) {
        $bagian['Catatan Staff'] = [
            ['label' => 'Catatan', 'nilai' => $t->staff_note, 'panjang' => true],
            ['label' => 'Ditulis', 'nilai' => $orang($t->noteBy, $t->staff_note_at)],
            ['label' => 'Diselesaikan', 'nilai' => $orang($t->noteResolvedBy, $t->staff_note_resolved_at)],
        ];
    }

    $bagian['Riwayat Data'] = [
        ['label' => 'Diinput', 'nilai' => $orang($t->createdBy, $t->created_at)],
        ['label' => 'Terakhir Diubah', 'nilai' => $orang($t->updatedBy, $t->updated_at)],
        ['label' => 'Anomali Direview', 'nilai' => $t->anomaly_reviewed_at ? $wib($t->anomaly_reviewed_at) : null],
    ];
@endphp

@include('partials.detail-record', [
    'judul'   => 'Detail LHP Produksi',
    'ikon'    => 'bi-clipboard-data',
    'kembali' => $kembali,
    'editUrl' => auth()->user()->hasRole('admin') ? route('input.produksi.edit', $t) : null,
    'bagian'  => $bagian,
])

@endsection
