<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 15px; }
    body { font-family: Arial, sans-serif; font-size: 9px; color: #111; }
    .header { display: table; width: 100%; margin-bottom: 8px; }
    .header .logo { display: table-cell; width: 230px; vertical-align: top; }
    .header .logo img { width: 210px; }
    .header .doc-no { display: table-cell; text-align: right; vertical-align: top; }
    .header .doc-no span { border: 1px solid #000; padding: 3px 8px; font-size: 9px; font-weight: bold; }
    h1 { text-align: center; font-size: 16px; margin: 4px 0 10px; }

    table.sched { width: 100%; border-collapse: collapse; table-layout: fixed; }
    table.sched th, table.sched td { border: 1px solid #333; padding: 2px 3px; text-align: center; overflow: hidden; }
    table.sched th.bulan-head { background: #bfdbfe; }
    table.sched td.hari-libur, table.sched th.hari-libur { background: #fca5a5; }
    table.sched td.plan-cell { background: #facc15; }
    table.sched td.act-cell { background: #86efac; }
    table.sched td.nama-col { text-align: left; }
    table.sched .col-no { width: 3%; }
    table.sched .col-nama { width: 13%; }
    table.sched .col-id { width: 6%; }
    table.sched .col-std { width: 6%; }
    table.sched .col-act { width: 6%; }
    table.sched .col-sched { width: 5%; }

    .ketentuan { margin-top: 18px; font-size: 9px; line-height: 1.5; }
    .ketentuan ol { margin: 8px 0 0 16px; padding: 0; }
    .ketentuan li { margin-bottom: 7px; }

    .footer-sign { margin-top: 24px; font-size: 9px; }
    .footer-sign .tempat-tgl { margin-bottom: 10px; }
    table.sign { width: 100%; border-collapse: collapse; }
    table.sign th, table.sign td { border: 0; padding: 3px 6px; }
    table.sign thead th { text-align: center; font-weight: normal; }
    table.sign .sign-space td { height: 46px; }
    table.sign .sign-name td { text-align: left; vertical-align: top; }

    .printed-at { position: fixed; bottom: 0; left: 0; font-size: 7px; color: #666; }
</style>
</head>
<body>

@if (! $schedule)
    <p>Belum ada jadwal untuk {{ $bulan->locale('id')->translatedFormat('F Y') }}. Klik "Generate Ulang" di halaman Control Stroke.</p>
@else

<div class="header">
    <div class="logo"><img src="{{ public_path('img/kmi-lockup.png') }}"></div>
    <div class="doc-no"><span>Nomor Dokumen: {{ $schedule->nomor_dokumen }}</span></div>
</div>

<h1>SCHEDULE PREVENTIVE MAINTENANCE</h1>

@php
    $jumlahHari = $bulan->daysInMonth;
    $namaBulan = mb_strtoupper($bulan->locale('id')->translatedFormat('F Y'));
    $labelKode = $jenis === 'mesin' ? 'ID MESIN' : 'ID DIES';
    $labelNama = $jenis === 'mesin' ? 'NAMA MESIN' : 'NAMA PART/DIES';
    // Kolom label (NO+NAMA+ID+STD+ACT+SCHED) total 39% lebar tabel (lihat .col-* di
    // style), sisanya dibagi RATA ke tiap kolom tanggal — supaya "9" dan "16" sama
    // lebar, bukan angka 1-digit dikempesin.
    $lebarHari = round((100 - 39) / $jumlahHari, 3);
@endphp

<table class="sched">
    <thead>
        <tr>
            <th rowspan="2" class="col-no">NO</th>
            <th rowspan="2" class="col-nama">{{ $labelNama }}</th>
            <th rowspan="2" class="col-id">{{ $labelKode }}</th>
            <th rowspan="2" class="col-std">STANDARD<br>STROKE</th>
            <th rowspan="2" class="col-act">ACTUAL<br>STROKE</th>
            <th rowspan="2" class="col-sched">MASTER<br>SCHED</th>
            <th colspan="{{ $jumlahHari }}" class="bulan-head">{{ $namaBulan }}</th>
        </tr>
        <tr>
            @for ($d = 1; $d <= $jumlahHari; $d++)
                @php $tgl = $bulan->copy()->day($d); @endphp
                <th style="width:{{ $lebarHari }}%" class="{{ \App\Services\KalenderKerjaService::hariKerja($tgl) ? '' : 'hari-libur' }}">{{ $d }}</th>
            @endfor
        </tr>
    </thead>
    <tbody>
        @foreach ($schedule->items as $i => $item)
            @php
                $target = $jenis === 'mesin' ? $item->mesin : $item->partProses;
                $kode = $jenis === 'mesin' ? $target?->id_mesin : $target?->part?->part_no;
                $nama = $jenis === 'mesin' ? $target?->nama_mesin : $target?->part?->part_name;
                $actualStroke = $target ? $svc->currentStrokeFor($target) : $item->stroke_snapshot;
                $logBulanIni = $target?->preventiveLogs->firstWhere(fn ($l) => $l->tanggal_preventive->isSameMonth($bulan));
            @endphp
            <tr>
                <td rowspan="2" class="col-no">{{ $i + 1 }}</td>
                <td rowspan="2" class="col-nama nama-col">{{ $nama }}</td>
                <td rowspan="2" class="col-id">{{ $kode }}</td>
                <td rowspan="2" class="col-std">{{ number_format($item->threshold_snapshot, 0, ',', '.') }}<br>STROKE</td>
                <td rowspan="2" class="col-act">{{ number_format($actualStroke, 0, ',', '.') }}</td>
                <td class="col-sched">PLAN</td>
                @for ($d = 1; $d <= $jumlahHari; $d++)
                    @php $tgl = $bulan->copy()->day($d); $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl); $isPlan = $item->tanggal_rencana->day === $d; @endphp
                    <td class="{{ $isPlan ? 'plan-cell' : ($isLibur ? 'hari-libur' : '') }}"></td>
                @endfor
            </tr>
            <tr>
                <td class="col-sched">ACT</td>
                @for ($d = 1; $d <= $jumlahHari; $d++)
                    @php $tgl = $bulan->copy()->day($d); $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl); $isDone = $logBulanIni && $logBulanIni->tanggal_preventive->day === $d; @endphp
                    <td class="{{ $isDone ? 'act-cell' : ($isLibur ? 'hari-libur' : '') }}"></td>
                @endfor
            </tr>
        @endforeach
    </tbody>
</table>

<div class="ketentuan">
    <strong><em>KETENTUAN PREVENTIVE MAINTENANCE*</em></strong>
    <ol>
        <li>Selama Preventive Maintenance (PM) berlangsung, status {{ $jenis === 'mesin' ? 'mesin' : 'dies' }} adalah&nbsp;<strong><em>NON-OPERASIONAL</em></strong>.</li>
        <li>{{ ucfirst($jenis === 'mesin' ? 'Mesin' : 'Dies') }} tidak diperkenankan digunakan untuk aktivitas produksi dalam bentuk apa pun.</li>
        <li>Departemen Maintenance memiliki kewenangan penuh atas {{ $jenis === 'mesin' ? 'mesin' : 'dies' }} selama preventive berlangsung.</li>
        <li>Departemen Produksi, PPIC, Quality, dan departemen terkait lainnya wajib menghentikan seluruh aktivitas yang melibatkan {{ $jenis === 'mesin' ? 'mesin' : 'dies' }} tersebut.</li>
        <li>{{ ucfirst($jenis === 'mesin' ? 'Mesin' : 'Dies') }} harus dalam kondisi aman (lock-out/tag-out).</li>
        <li>{{ ucfirst($jenis === 'mesin' ? 'Mesin' : 'Dies') }} hanya boleh dioperasikan kembali setelah:
            <br>- Preventive dinyatakan selesai oleh Maintenance.
            <br>- Dilakukan pengecekan akhir (final check).
        </li>
    </ol>
</div>

@php
    $tandaTangan = ['MTC Mesin', 'Produksi', 'PPIC', 'Quality', 'MTC Dies', 'Engineering', 'Supervisor', 'Direktur'];
    $blankTtd = str_repeat('&nbsp;', 16);
@endphp
<div class="footer-sign">
    <div class="tempat-tgl">Bekasi, {{ now()->timezone('Asia/Jakarta')->locale('id')->translatedFormat('d F Y') }}</div>
    <table class="sign">
        <thead>
            <tr>
                <th>Dibuat</th>
                <th colspan="5">Mengetahui</th>
                <th>Diperiksa</th>
                <th>Disetujui</th>
            </tr>
        </thead>
        <tbody>
            <tr class="sign-space"><td colspan="8"></td></tr>
            <tr class="sign-name">
                @foreach ($tandaTangan as $peran)
                    <td>{{ $peran }}<br>({!! $blankTtd !!})</td>
                @endforeach
            </tr>
        </tbody>
    </table>
</div>

@endif

<div class="printed-at">Printed on OPTIMA, at {{ now()->timezone('Asia/Jakarta')->format('d.m.Y H:i:s') }}</div>

</body>
</html>
