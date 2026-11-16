@extends('layouts.app')
@section('title', 'Detail Breakdown Dies')
@section('content')

@php
    $pp   = $b->partProses;
    $part = $pp?->part;
    $tgl = fn ($d) => $d ? \Carbon\Carbon::parse($d)->format('d/m/Y') : null;
    $jam = fn ($j) => $j ? (string) \Illuminate\Support\Str::of($j)->substr(0, 5) : null;
    $tglJam = fn ($d, $j) => $d ? trim($tgl($d).' '.$jam($j)) : null;
    $wib = fn ($waktu) => $waktu ? $waktu->copy()->timezone('Asia/Jakarta')->format('d/m/Y H:i').' WIB' : null;

    $bagian = [
        'Identitas' => [
            ['label' => 'No. LKD', 'nilai' => $b->no_lkd],
            ['label' => 'Status', 'nilai' => $b->status_breakdown],
            ['label' => 'PIC Maintenance', 'nilai' => $b->pic_maintenance],
        ],
        'Part / Dies' => [
            ['label' => 'Customer', 'nilai' => $part?->customer],
            ['label' => 'Part No.', 'nilai' => $part?->part_no],
            ['label' => 'Nama Dies', 'nilai' => $part?->part_name],
            ['label' => 'ID Proses', 'nilai' => $pp?->id_proses],
            ['label' => 'Proses', 'nilai' => $pp?->nama_proses],
        ],
        'Waktu' => [
            ['label' => 'Breakdown', 'nilai' => $tglJam($b->tanggal_breakdown, $b->jam_breakdown)],
            ['label' => 'Mulai Perbaikan', 'nilai' => $tglJam($b->tanggal_mulai_perbaikan, $b->jam_mulai_perbaikan)],
            ['label' => 'Selesai Perbaikan', 'nilai' => $tglJam($b->tanggal_selesai_perbaikan, $b->jam_selesai_perbaikan)],
            ['label' => 'Repair Time', 'nilai' => number_format((float) $b->repair_time, 2).' jam'],
            ['label' => 'Total Downtime', 'nilai' => number_format((float) $b->total_downtime, 2).' jam'],
        ],
        'Uraian' => [
            ['label' => 'Problem Dies', 'nilai' => $b->problem_dies, 'panjang' => true],
            ['label' => 'Penyebab Breakdown', 'nilai' => $b->penyebab_breakdown, 'panjang' => true],
            ['label' => 'Tindakan Perbaikan', 'nilai' => $b->tindakan_perbaikan, 'panjang' => true],
        ],
        'Riwayat Data' => [
            ['label' => 'Diinput', 'nilai' => $wib($b->created_at)],
            ['label' => 'Terakhir Diubah', 'nilai' => $wib($b->updated_at)],
        ],
    ];
@endphp

@include('partials.detail-record', [
    'judul'   => 'Detail Breakdown Dies',
    'ikon'    => 'bi-hammer',
    'kembali' => $kembali,
    'editUrl' => auth()->user()->hasRole('admin') ? route('maintenance.breakdown-dies.edit', $b) : null,
    'bagian'  => $bagian,
])

@endsection
