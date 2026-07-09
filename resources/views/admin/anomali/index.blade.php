@extends('layouts.app')
@section('title', 'Anomali LHP Produksi')
@section('suppress_layout_flash', 'yes')
@section('content')

<div class="page-header">
    <h1 class="page-title"><i class="bi bi-exclamation-triangle"></i>Anomali LHP Produksi</h1>
</div>

@if(session('success'))
<div class="alert alert-success alert-dismissible fade show mb-3" style="font-size:0.88rem">
    <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
@endif

{{-- ── Filter ──────────────────────────────────────────────────── --}}
<div class="filter-bar mb-3">
    <form method="GET" action="{{ route('admin.anomali') }}"
          style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;width:100%">
        <div class="filter-group">
            <label>Dari Tanggal</label>
            <input type="date" name="date_from" class="form-control form-control-sm" value="{{ $dateFrom }}">
        </div>
        <div class="filter-group">
            <label>Sampai Tanggal</label>
            <input type="date" name="date_to" class="form-control form-control-sm" value="{{ $dateTo }}">
        </div>
        <div class="filter-group">
            <label>Jenis Anomali</label>
            <select name="reason" class="form-select form-select-sm" style="min-width:200px">
                <option value=""         {{ $reason===''          ? 'selected':'' }}>Semua Jenis</option>
                <option value="high_pct" {{ $reason==='high_pct'  ? 'selected':'' }}>Achievement &gt;150%</option>
                <option value="short_wkj"{{ $reason==='short_wkj' ? 'selected':'' }}>Waktu kerja &lt;5 menit</option>
                <option value="no_std"   {{ $reason==='no_std'    ? 'selected':'' }}>SPH standar belum diset</option>
            </select>
        </div>
        <div class="filter-group" style="flex-direction:row;gap:6px;align-items:flex-end">
            <button type="submit" class="btn btn-secondary btn-sm">
                <i class="bi bi-funnel me-1"></i>Tampilkan
            </button>
            <a href="{{ route('admin.anomali') }}" class="btn btn-outline-secondary btn-sm">Reset</a>
        </div>
        <div style="margin-left:auto;display:flex;align-items:flex-end">
            <span style="font-size:0.78rem;color:#5d7283;background:#f0f2f5;border:1px solid #d4dbe3;padding:4px 10px">
                {{ $dateFrom }} — {{ $dateTo }}
            </span>
        </div>
    </form>
</div>

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
    <div style="background:{{ $c['bg'] }};border:1px solid #d1d5db;border-radius:2px;padding:12px 14px">
        <div style="font-size:9.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
            <i class="bi {{ $c['icon'] }} me-1"></i>{{ $c['label'] }}
        </div>
        <div style="font-size:26px;font-weight:700;color:{{ $c['color'] }};line-height:1">{{ $c['val'] }}</div>
    </div>
    @endforeach
</div>

{{-- ── Tabel ───────────────────────────────────────────────────── --}}
@if($anomalies->isEmpty())
<div class="erp-panel">
    <div style="text-align:center;padding:48px 24px;color:#9ca3af">
        <i class="bi bi-check-circle-fill" style="font-size:2.5rem;display:block;margin-bottom:12px;color:#86efac"></i>
        <div style="font-weight:700;font-size:1rem;color:#374151;margin-bottom:4px">Tidak ada anomali ditemukan</div>
        <div style="font-size:0.88rem">Semua data LHP Produksi pada rentang tanggal ini terlihat normal.</div>
    </div>
</div>
@else
<div class="erp-panel">
    <div class="erp-panel-header" style="display:flex;align-items:center;gap:8px">
        <i class="bi bi-exclamation-triangle-fill" style="color:#ca8a04"></i>
        Daftar Record Anomali
        <span style="background:#fde047;color:#713f12;font-size:10px;font-weight:700;
                     padding:2px 8px;border-radius:999px;margin-left:2px">
            {{ $summary['total'] }} record
        </span>
        <span style="margin-left:auto;font-size:10px;font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0">
            Klik <strong>Edit</strong> untuk perbaiki data
        </span>
    </div>
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 260px);min-height:200px">
        <table class="erp-table tbl-freeze" style="min-width:1400px">
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
                <tr class="anomaly-row" style="background:#fefce8">
                    <td class="sc sc-1" style="text-align:center;color:#9ca3af;font-size:11px">{{ $i + 1 }}</td>
                    <td class="sc sc-2" style="font-weight:600">
                        {{ \Carbon\Carbon::parse($t->tanggal_produksi)->format('d/m/Y') }}
                    </td>
                    <td class="sc sc-3" style="font-size:0.88rem;color:#6b7280;white-space:nowrap">{{ $t->shift?->nama_shift ?? '—' }}</td>
                    <td class="sc sc-4" style="font-weight:600;white-space:nowrap">{{ $t->karyawan?->nama_karyawan ?? '—' }}</td>
                    <td class="sc sc-5 sc-sep" style="font-size:0.88rem">{{ $t->mesin?->id_mesin ?? '—' }}</td>
                    <td style="font-size:0.82rem;white-space:nowrap">{{ $part?->part_no ?? '—' }}</td>
                    <td style="font-size:0.82rem;white-space:nowrap">{{ $part?->part_name ?? '—' }}</td>
                    <td style="font-size:0.82rem;white-space:nowrap">{{ $t->partProses?->nama_proses ?? '—' }}</td>
                    <td style="font-size:0.82rem;white-space:nowrap;color:#6b7280">
                        {{ $t->jam_start }} – {{ $t->jam_finish }}
                    </td>
                    <td style="text-align:right;font-weight:600;
                               color:{{ $a->wkj < 0.083 ? '#b91c1c' : '#374151' }}">
                        {{ number_format($a->wkj, 2) }}j
                    </td>
                    <td style="text-align:right;font-weight:600">{{ number_format($t->qty_aktual) }}</td>
                    <td style="text-align:right;color:#6b7280">{{ number_format($a->sph_std) }}</td>
                    <td style="text-align:right;font-weight:700;color:#b91c1c">
                        {{ number_format($a->sph_act) }}
                    </td>
                    <td style="text-align:right;font-weight:700;white-space:nowrap;
                               color:{{ $a->pct === null ? '#9ca3af' : ($a->pct > 150 ? '#b91c1c' : '#374151') }}">
                        @if($a->pct !== null)
                            {{ $a->pct }}% <i class="bi bi-exclamation-triangle-fill" style="font-size:0.7rem;color:#ca8a04"></i>
                        @else
                            —
                        @endif
                    </td>
                    <td style="font-size:0.78rem;color:#92400e;white-space:nowrap">
                        @foreach($a->warnings as $w)
                            <div><i class="bi bi-dot" style="color:#ca8a04;font-size:1rem"></i>{{ $w }}</div>
                        @endforeach
                    </td>
                    <td>
                        <div style="display:flex;gap:5px;align-items:center">
                            <a href="{{ route('input.produksi.edit', $t) }}"
                               class="btn btn-warning btn-sm"
                               style="font-size:0.78rem;padding:3px 8px;white-space:nowrap">
                                <i class="bi bi-pencil me-1"></i>Edit
                            </a>
                            <form method="POST"
                                  action="{{ route('admin.anomali.approve', $t) }}"
                                  onsubmit="return confirm('Tandai data ini sebagai OK?\n\nIni berarti lo sudah review dan yakin nilai SPH / persentase ini memang benar.')">
                                @csrf
                                <button type="submit" class="btn btn-success btn-sm"
                                        style="font-size:0.78rem;padding:3px 8px;white-space:nowrap">
                                    <i class="bi bi-check-lg me-1"></i>OK
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>
@endif

<div style="margin-top:10px;font-size:0.8rem;color:#9ca3af">
    <i class="bi bi-info-circle me-1"></i>
    Threshold: Achievement &gt;150% · Waktu kerja &lt;5 menit · SPH standar = 0.
    Semua data ditampilkan tanpa paginasi untuk rentang tanggal yang dipilih.
</div>

@endsection
