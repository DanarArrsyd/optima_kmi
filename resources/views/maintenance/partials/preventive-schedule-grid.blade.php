<style>
    /* table-layout AUTO (bukan fixed) — kolom label (No/Nama/ID/Proses/PLAN) ngikut
       lebar konten aslinya (nowrap, nol kepotong), kolom tanggal DIPAKSA sama lebar
       lewat width+min-width+max-width px yang identik di tiap <th>/<td> (auto layout
       tetap hormatin width kalau kontennya nggak pernah lebih lebar dari itu — tombol
       "→" kecil font 9px muat). Total lebar tabel otomatis lebih dari lebar panel
       begitu bulan panjang/nama part panjang → wrapper overflow-x:auto DI BAWAH ini
       yang munculin scrollbar, bukan tabel maksa nyusut kayak table-layout:fixed. */
    .psg-table { table-layout: auto; }
    .psg-table th, .psg-table td { text-align: center; vertical-align: middle; white-space: nowrap; font-size: 11px; }
    .psg-table th:nth-child(2), .psg-table td:nth-child(2) { text-align: left; }
    .psg-day-col { width: 34px; min-width: 34px; max-width: 34px; overflow: hidden; }
    .psg-libur { background: #fca5a5; }
    .psg-plan { background: #facc15; cursor: pointer; }
    .psg-cell:not(.psg-libur):not(.psg-readonly) { cursor: pointer; }
    .psg-cell:hover:not(.psg-libur):not(.psg-readonly) { outline: 2px solid #111827; outline-offset: -2px; }
    .psg-locked td { background: #fef9c3; }
</style>

@php
    $jumlahHari = $bulan->daysInMonth;
    $namaBulan = mb_strtoupper($bulan->locale('id')->translatedFormat('F Y'));
    $labelKode = $jenis === 'mesin' ? 'ID MESIN' : 'ID DIES';
    $labelNama = $jenis === 'mesin' ? 'NAMA MESIN' : 'NAMA PART/DIES';

    $tanggalValid = collect(range(1, $jumlahHari))
        ->map(fn ($d) => $bulan->copy()->day($d))
        ->filter(fn ($tgl) => \App\Services\KalenderKerjaService::hariKerja($tgl) && in_array($tgl->isoWeekday(), app(\App\Services\PreventiveScheduleService::class)->hariDiizinkan($jenis), true))
        ->map(fn ($tgl) => $tgl->toDateString())
        ->values();
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
                                <th class="psg-day-col {{ \App\Services\KalenderKerjaService::hariKerja($tgl) ? '' : 'psg-libur' }}">{{ $d }}</th>
                            @endfor
                        </tr>
                    </thead>
                        @foreach ($schedule->items as $i => $item)
                            @php
                                $target = $jenis === 'mesin' ? $item->mesin : $item->partProses;
                                $kode = $jenis === 'mesin' ? $target?->id_mesin : $target?->part?->part_no;
                                $nama = $jenis === 'mesin' ? $target?->nama_mesin : $target?->part?->part_name;
                            @endphp
                            {{-- tbody per item (HTML5 valid, banyak <tbody> per <table>) —
                                 x-data di sini biar baris error di bawah IKUT dalam scope
                                 Alpine yang sama dengan baris utama, bukan sibling di luar
                                 scope (x-data di <tr> nol nyebar ke <tr> sebelah). --}}
                            <tbody x-data="scheduleItemEdit(
                                    {{ $item->id }},
                                    {{ $item->tanggal_rencana ? \Illuminate\Support\Js::from($item->tanggal_rencana->toDateString()) : 'null' }},
                                    {{ $item->is_manual_override ? 'true' : 'false' }},
                                    {{ \Illuminate\Support\Js::from(route("maintenance.control-stroke-{$jenis}.schedule-item.update", $item)) }},
                                    {{ \Illuminate\Support\Js::from(route("maintenance.control-stroke-{$jenis}.schedule-item.reset", $item)) }},
                                    {{ \Illuminate\Support\Js::from($tanggalValid) }}
                                )">
                            <tr class="{{ $item->is_manual_override ? 'psg-locked' : '' }}">
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $nama }}</td>
                                <td>{{ $kode }}</td>
                                @if ($jenis === 'dies')
                                    <td>{{ $target?->id_proses }} &ndash; {{ $target?->nama_proses }}</td>
                                @endif
                                <td>
                                    PLAN
                                    <template x-if="isLocked">
                                        @if ($bolehEdit)
                                            <i class="bi bi-lock-fill text-warning ms-1" title="Dikunci manual" @click="reset()" style="cursor:pointer"></i>
                                        @else
                                            <i class="bi bi-lock-fill text-warning ms-1" title="Dikunci manual"></i>
                                        @endif
                                    </template>
                                </td>
                                @for ($d = 1; $d <= $jumlahHari; $d++)
                                    @php
                                        $tgl = $bulan->copy()->day($d);
                                        $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl);
                                        $tglStr = $tgl->toDateString();
                                    @endphp
                                    <td class="psg-cell psg-day-col {{ $isLibur ? 'psg-libur' : '' }} {{ $bolehEdit ? '' : 'psg-readonly' }}"
                                        :class="tanggal === '{{ $tglStr }}' ? 'psg-plan' : ''"
                                        @if (! $isLibur && $bolehEdit) @click="open()" @endif>
                                        <template x-if="editing && tanggal !== '{{ $tglStr }}' && validDates.includes('{{ $tglStr }}')">
                                            <button type="button" class="btn btn-sm btn-outline-dark py-0 px-1" style="font-size:9px" @click.stop="simpan('{{ $tglStr }}')" :disabled="saving">→</button>
                                        </template>
                                    </td>
                                @endfor
                            </tr>
                            <tr x-show="error" x-cloak>
                                <td colspan="99" class="text-danger small py-1" x-text="error"></td>
                            </tr>
                            </tbody>
                        @endforeach
                        @if ($schedule->items->isEmpty())
                            <tbody><tr><td colspan="99" class="text-center text-muted py-3">Nol item eligible bulan ini.</td></tr></tbody>
                        @endif
                </table>
            </div>
        @endif
    </div>
</div>
