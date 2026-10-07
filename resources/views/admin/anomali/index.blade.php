@extends('layouts.app')
@section('title', 'Anomali LHP Produksi')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════
     Isinya kartu ringkasan + tabel, jadi .erp-scroll dibiarkan
     menggulung tegak. --}}
<div class="erp-ws" x-data="{ filterOpen: false }">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($reason !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    {{-- Ekspor membawa filter yang sedang aktif — isi file = isi tabel. --}}
    <a href="{{ route('admin.anomali.export', request()->query()) }}" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    <a href="{{ route('admin.anomali') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('admin.anomali')">
    <x-filter.grup judul="Find">
        <x-filter.cari name="reason" placeholder="Jenis Anomali" :value="$reason"
                       :items="[['v' => 'high_pct', 't' => 'Achievement >'.config('anomali_lhp.pct_maks').'%'],
                                ['v' => 'short_wkj', 't' => 'Waktu kerja <'.round(config('anomali_lhp.wkj_min') * 60).' menit'],
                                ['v' => 'no_std', 't' => 'SPH standar belum diset']]" />
    </x-filter.grup>

    {{-- Tanpa centang: halaman ini memuat SEMUA baris rentang ke memori untuk
         dihitung — mematikan tanggal = memuat seluruh riwayat produksi. --}}
    <x-filter.tanggal :from="$dateFrom" :to="$dateTo" />
</x-filter.dock>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; jumlah record sudah di status bar. --}}
<div class="erp-scroll erp-scroll-pad">

{{-- ── KPI Summary Cards ───────────────────────────────────────── --}}
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px">
    @php
    $cards = [
        ['label'=>'Total Anomali',           'val'=>$summary['total'],     'icon'=>'bi-exclamation-triangle-fill', 'color'=>$summary['total']>0?'#ca8a04':'#15803d', 'bg'=>$summary['total']>0?'#fefce8':'#f0fdf4'],
        ['label'=>'Achievement >150%',       'val'=>$summary['high_pct'],  'icon'=>'bi-graph-up-arrow',           'color'=>'#b91c1c', 'bg'=>'#fef2f2'],
        ['label'=>'Waktu Kerja <5 Menit',    'val'=>$summary['short_wkj'], 'icon'=>'bi-alarm',                    'color'=>'#b91c1c', 'bg'=>'#fef2f2'],
        ['label'=>'SPH Std Belum Diset',     'val'=>$summary['no_std'],    'icon'=>'bi-question-circle',          'color'=>'#6b7280', 'bg'=>'#f9fafb'],
    ];
    @endphp
    @foreach($cards as $c)
    <div style="background:{{ $c['bg'] }};border:1px solid var(--border);border-radius:2px;padding:12px 14px">
        <div style="font-size:9.5px;font-weight:700;color:var(--text-3);text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
            <i class="bi {{ $c['icon'] }} me-1"></i>{{ $c['label'] }}
        </div>
        <div style="font-size:26px;font-weight:700;color:{{ $c['color'] }};line-height:1">{{ $c['val'] }}</div>
    </div>
    @endforeach
</div>

{{-- ── Tabel ───────────────────────────────────────────────────── --}}
@if($anomalies->isEmpty())
<div class="erp-panel">
    <div style="text-align:center;padding:48px 24px;color:var(--text-4)">
        <i class="bi bi-check-circle-fill" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#86efac"></i>
        <div style="font-weight:700;font-size:1rem;color:#374151;margin-bottom:4px">Tidak ada anomali ditemukan</div>
        <div style="font-size:0.88rem">Semua data LHP Produksi pada rentang tanggal ini terlihat normal.</div>
    </div>
</div>
@else
{{-- Kepala panel dibuang: judul sudah di topbar, jumlah record sudah di
     status bar. Tabel dibungkus overflow-x SENDIRI (bukan ikut .erp-scroll
     luar) supaya kartu KPI di atas tidak ikut tergeser pas tabel digulung
     mendatar — wrapper ini cuma overflow-x, overflow-y tetap default
     (visible), jadi sticky vertikal thead masih pegang .erp-scroll luar. --}}
        <div style="overflow-x:auto">
        <table class="erp-table tbl-freeze ada-aksi" style="min-width:1400px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th class="sc sc-1" style="width:30px;text-align:center">No</th>
                    <th class="sc sc-2" style="width:85px">Tanggal</th>
                    <th class="sc sc-3" style="width:70px">Shift</th>
                    <th class="sc sc-4" style="width:140px">Operator</th>
                    <th class="sc sc-5 sc-sep" style="width:60px">Mesin</th>
                    <th style="width:90px">Part No.</th>
                    <th style="min-width:110px">Part Name</th>
                    <th style="width:100px">Proses</th>
                    <th style="width:110px">Jam</th>
                    <th style="width:70px;text-align:right">W.Kerja</th>
                    <th style="width:65px;text-align:right">Qty Act</th>
                    <th style="width:65px;text-align:right">SPH Std</th>
                    <th style="width:65px;text-align:right">SPH Act</th>
                    <th style="width:90px;text-align:right;white-space:nowrap">%</th>
                    <th style="white-space:nowrap">Alasan Anomali</th>
                    <th style="width:130px;white-space:nowrap">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @foreach($anomalies as $i => $a)
                @php $t = $a->record; $part = $t->partProses?->part; @endphp
                <tr class="anomaly-row" style="background:var(--c-warn-bg)">
                    <td class="sc sc-1" style="text-align:center;color:var(--text-4)">{{ $i + 1 }}</td>
                    <td class="sc sc-2" style="font-weight:600">
                        {{ \Carbon\Carbon::parse($t->tanggal_produksi)->format('d/m/Y') }}
                    </td>
                    <td class="sc sc-3" style="color:var(--text-3);white-space:nowrap">{{ $t->shift?->nama_shift ?? '—' }}</td>
                    <td class="sc sc-4" style="font-weight:600;white-space:nowrap">{{ $t->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td class="sc sc-5 sc-sep" >{{ $t->mesin?->id_mesin ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_no ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $part?->part_name ?? '—' }}</td>
                    <td style="white-space:nowrap">{{ $t->partProses?->nama_proses ?? '—' }}</td>
                    <td style="white-space:nowrap;color:var(--text-3)">
                        {{ $t->jam_start }} – {{ $t->jam_finish }}
                    </td>
                    <td style="text-align:right;font-weight:600;
                               color:{{ $a->wkj < 0.083 ? 'var(--c-danger)' : '#374151' }}">
                        {{ number_format($a->wkj, 2) }}j
                    </td>
                    <td style="text-align:right;font-weight:600">{{ number_format($t->qty_aktual) }}</td>
                    <td style="text-align:right;color:var(--text-3)">{{ number_format($a->sph_std) }}</td>
                    <td style="text-align:right;font-weight:600;color:var(--c-danger)">
                        {{ number_format($a->sph_act) }}
                    </td>
                    <td style="text-align:right;font-weight:600;white-space:nowrap;
                               color:{{ $a->pct === null ? 'var(--text-4)' : ($a->pct > 150 ? 'var(--c-danger)' : '#374151') }}">
                        @if($a->pct !== null)
                            {{ $a->pct }}% <i class="bi bi-exclamation-triangle-fill" style="color:#ca8a04"></i>
                        @else
                            —
                        @endif
                    </td>
                    {{-- Ikut ukuran sel lain; warnanya yang membedakan, bukan
                         ukurannya. Beberapa alasan dirangkai satu baris supaya
                         tinggi barisnya tidak melar sendiri dibanding baris lain. --}}
                    <td style="color:#92400e;white-space:nowrap">
                        {{ implode(' · ', $a->warnings) }}
                    </td>
                    <td>
                        <div style="display:flex;gap:5px;align-items:center">
                            <a href="{{ route('input.produksi.edit', $t) }}"
                               class="btn btn-warning btn-sm"
                               style="font-size:var(--erp-fs-sm);padding:3px 8px;white-space:nowrap">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                            <form method="POST"
                                  action="{{ route('admin.anomali.approve', $t) }}"
                                  data-konfirmasi="Tandai data ini sebagai OK?&#10;&#10;Ini berarti lo sudah review dan yakin nilai SPH / persentase ini memang benar.">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm"
                                        style="font-size:var(--erp-fs-sm);padding:3px 8px;white-space:nowrap">
                                    <i class="bi bi-check-lg me-1"></i>OK
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
        </div>{{-- /overflow-x --}}
@endif

{{-- Ambang dibaca dari config/anomali_lhp.php, bukan ditulis ulang di sini:
     halaman ini dan hitungan badge di LHP Produksi memakai sumber yang sama,
     dan angka yang disalin akan menyimpang diam-diam saat ambangnya diubah. --}}
<div class="erp-meta" style="margin-top:10px;color:var(--text-4)">
    <i class="bi bi-info-circle me-1"></i>
    Ambang: Achievement &gt;{{ config('anomali_lhp.pct_maks') }}% &middot;
    Waktu kerja &lt;{{ round(config('anomali_lhp.wkj_min') * 60) }} menit &middot;
    SPH standar = 0. Semua data ditampilkan tanpa paginasi untuk rentang tanggal yang dipilih.
</div>
</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>Period {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}
          &mdash; {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</span>
    <span><b>{{ number_format($summary['total']) }}</b> record anomali</span>
    @if($reason !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection
