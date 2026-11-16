@extends('layouts.app')
@section('title', 'Detail LHP Quality')
@section('content')

@php
    $pp       = $t->partProses;
    $part     = $pp?->part;
    $totalQty = (int) ($t->qty_ok ?? 0) + (int) ($t->qty_ng ?? 0);
    $sphStd   = (int) ($pp?->sph_standar_qc ?? 0);
    $sphAct   = $t->sph_aktual;
    $pct      = $sphStd > 0 ? round($sphAct / $sphStd * 100, 1) : null;
    $wib      = fn ($waktu) => $waktu?->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB';
    $orang    = fn ($user, $waktu) => $waktu ? trim(($user?->name ?? '—').' · '.$wib($waktu)) : null;

    $rincianNg = $t->relationLoaded('ngDetails') ? $t->ngDetails : collect();
    $teksNg = $rincianNg->isNotEmpty()
        ? $rincianNg->map(fn ($d) => trim(($d->jenisNg?->kode ?? '?').' — '.($d->jenisNg?->nama_ng ?? '')).': '.number_format($d->qty).' pcs')->implode("\n")
        : ($t->jenisNg ? trim($t->jenisNg->kode.' — '.$t->jenisNg->nama_ng) : null);

    $bagian = [
        'Identitas' => [
            ['label' => 'Tanggal Pengecekan', 'nilai' => \Carbon\Carbon::parse($t->tgl_pengecekan)->format('d/m/Y')],
            ['label' => 'Tanggal Produksi Part', 'nilai' => $t->tgl_produksi_part ? \Carbon\Carbon::parse($t->tgl_produksi_part)->format('d/m/Y') : null],
            ['label' => 'Shift', 'nilai' => $t->shift?->nama_shift],
            ['label' => 'Inspector', 'nilai' => $t->karyawanQc ? trim($t->karyawanQc->nama_karyawan.($t->karyawanQc->nik ? ' ('.$t->karyawanQc->nik.')' : '')) : null],
        ],
        'Part & Proses' => [
            ['label' => 'Customer', 'nilai' => $part?->customer],
            ['label' => 'Part No.', 'nilai' => $part?->part_no],
            ['label' => 'Part Name', 'nilai' => $part?->part_name],
            ['label' => 'ID Proses', 'nilai' => $pp?->id_proses],
            ['label' => 'Proses', 'nilai' => $pp?->nama_proses],
        ],
        'Hasil Pengecekan' => [
            ['label' => 'Jam', 'nilai' => $t->jam_start.' – '.$t->jam_finish],
            ['label' => 'Waktu Kerja', 'nilai' => number_format($t->waktu_kerja_jam, 2).' jam'],
            ['label' => 'Qty OK', 'nilai' => number_format((int) $t->qty_ok).' pcs'],
            ['label' => 'Qty NG', 'nilai' => number_format((int) $t->qty_ng).' pcs'.($totalQty > 0 && $t->qty_ng > 0 ? ' ('.round($t->qty_ng / $totalQty * 100, 1).'%)' : '')],
            ['label' => 'SPH Standar QC', 'nilai' => number_format($sphStd)],
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
    ];
@endphp

@include('partials.detail-record', [
    'judul'   => 'Detail LHP Quality',
    'ikon'    => 'bi-patch-check',
    'kembali' => $kembali,
    'editUrl' => auth()->user()->hasRole('admin') ? route('input.qc.edit', $t) : null,
    'bagian'  => $bagian,
])

@endsection
