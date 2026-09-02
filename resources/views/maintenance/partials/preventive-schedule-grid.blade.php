@php
    $jumlahHari = $bulan->daysInMonth;
    $namaBulan = mb_strtoupper($bulan->locale('id')->translatedFormat('F Y'));
    $labelKode = $jenis === 'mesin' ? 'ID MESIN' : 'ID DIES';
    $labelNama = $jenis === 'mesin' ? 'NAMA MESIN' : 'NAMA PART/DIES';
@endphp

<div class="erp-panel mt-3">
    <div class="erp-panel-header d-flex justify-content-between align-items-center">
        <span>Jadwal Preventive Bulan Ini — {{ $namaBulan }}</span>
        @if ($schedule)
            <span class="text-muted small">{{ $schedule->nomor_dokumen }}</span>
        @endif
    </div>
    <div class="erp-panel-body" style="padding:0">
        @if (! $schedule)
            <p class="text-muted p-3 mb-0">Belum ada jadwal untuk {{ $namaBulan }}. Klik "Generate Ulang" di toolbar atas.</p>
        @else
            <div style="overflow-x:auto">
                <table class="erp-table psg-table">
                    <thead>
                        <tr>
                            <th>No</th>
                            <th>{{ $labelNama }}</th>
                            <th>{{ $labelKode }}</th>
                            @if ($jenis === 'dies')
                                <th>Proses</th>
                            @endif
                            <th>PLAN</th>
                            @for ($d = 1; $d <= $jumlahHari; $d++)
                                @php $tgl = $bulan->copy()->day($d); @endphp
                                <th class="{{ \App\Services\KalenderKerjaService::hariKerja($tgl) ? '' : 'psg-libur' }}">{{ $d }}</th>
                            @endfor
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($schedule->items as $i => $item)
                            @php
                                $target = $jenis === 'mesin' ? $item->mesin : $item->partProses;
                                $kode = $jenis === 'mesin' ? $target?->id_mesin : $target?->part?->part_no;
                                $nama = $jenis === 'mesin' ? $target?->nama_mesin : $target?->part?->part_name;
                            @endphp
                            <tr data-item-id="{{ $item->id }}" class="{{ $item->is_manual_override ? 'psg-locked' : '' }}">
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $nama }}</td>
                                <td>{{ $kode }}</td>
                                @if ($jenis === 'dies')
                                    <td>{{ $target?->id_proses }} &ndash; {{ $target?->nama_proses }}</td>
                                @endif
                                <td>PLAN
                                    @if ($item->is_manual_override)
                                        <i class="bi bi-lock-fill text-warning ms-1" title="Dikunci manual"></i>
                                    @endif
                                </td>
                                @for ($d = 1; $d <= $jumlahHari; $d++)
                                    @php
                                        $tgl = $bulan->copy()->day($d);
                                        $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl);
                                        $isPlan = $item->tanggal_rencana && $item->tanggal_rencana->day === $d;
                                    @endphp
                                    <td class="psg-cell {{ $isPlan ? 'psg-plan' : ($isLibur ? 'psg-libur' : '') }}"
                                        data-item-id="{{ $item->id }}" data-day="{{ $d }}"></td>
                                @endfor
                            </tr>
                        @endforeach
                        @if ($schedule->items->isEmpty())
                            <tr><td colspan="99" class="text-center text-muted py-3">Nol item eligible bulan ini.</td></tr>
                        @endif
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
