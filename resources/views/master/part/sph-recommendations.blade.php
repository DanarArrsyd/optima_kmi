@extends('layouts.app')
@section('title', 'Rekomendasi Upgrade SPH')
@section('content')
<div class="page-header">
    <h1 class="page-title"><i class="bi bi-graph-up-arrow"></i>Rekomendasi Upgrade SPH</h1>
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali ke Master Part
    </a>
</div>

{{-- ── REKOMENDASI UPGRADE SPH PRODUKSI ────────────────────────── --}}
<div class="erp-panel mb-3" style="border-color:var(--c-sph-prod)">
    <div class="erp-panel-header"
         style="background:var(--c-sph-prod-bg-2);border-color:var(--c-sph-prod);color:var(--c-sph-prod-text)">
        <i class="bi bi-graph-up-arrow me-2"></i>
        <strong>Rekomendasi Upgrade SPH Standard Produksi</strong>
        <span class="badge ms-2" style="background:var(--c-sph-prod);color:#fff;font-size:0.72rem">
            {{ $recommendations->count() }} item
        </span>
    </div>
    <div style="padding:10px 16px;background:#fffbee;border-bottom:1px solid #ede0a0;font-size:0.78rem;color:var(--c-sph-prod-text)">
        <i class="bi bi-info-circle me-1"></i>
        Kombinasi Part/Proses berikut secara konsisten menghasilkan SPH aktual di atas standar.
        Klik <strong>Lihat &amp; Terapkan</strong> untuk review dan approve upgrade di halaman edit part.
    </div>

    @if($recommendations->isEmpty())
    <div style="text-align:center;padding:32px;color:#9ca3af">
        <i class="bi bi-check2-circle" style="font-size:1.6rem;display:block;margin-bottom:8px"></i>
        Nol rekomendasi Produksi tersisa.
    </div>
    @else
    <div style="overflow-x:auto">
        <table class="erp-table" style="margin:0">
            <thead>
                <tr style="background:var(--c-sph-prod-bg-2)">
                    <th style="white-space:nowrap">Part No</th>
                    <th style="white-space:nowrap">Part Name</th>
                    <th style="white-space:nowrap">Proses</th>
                    <th style="text-align:right;width:110px">SPH Std</th>
                    <th style="text-align:right;width:110px">SPH Rata-rata</th>
                    <th style="text-align:center;white-space:nowrap">Dilampaui</th>
                    <th style="text-align:right;width:130px">Rekomendasi</th>
                    <th style="width:150px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @foreach($recommendations as $rec)
                <tr style="background:var(--c-sph-prod-bg)">
                    <td style="font-weight:600;white-space:nowrap">{{ $rec->part_no }}</td>
                    <td style="white-space:nowrap">{{ $rec->part_name }}</td>
                    <td style="white-space:nowrap">
                        <span style="font-size:0.78rem;color:#6b7280">{{ $rec->id_proses }}</span>
                        {{ $rec->nama_proses }}
                    </td>
                    <td style="text-align:right;color:#374151">
                        {{ number_format($rec->sph_standar_produksi) }}
                    </td>
                    <td style="text-align:right;color:var(--c-sph-qc);font-weight:600">
                        {{ number_format($rec->avg_all_sph) }}
                    </td>
                    <td style="text-align:center;white-space:nowrap">
                        <span title="{{ $rec->exceed_count }} sesi dari {{ $rec->total_sessions }} sesi total">
                            {{ $rec->exceed_count }}x / {{ $rec->total_sessions }}x
                            <span style="color:var(--c-sph-prod-text-2)">({{ $rec->exceed_pct }}%)</span>
                        </span>
                    </td>
                    <td style="text-align:right">
                        <strong style="color:#2d7a2d;font-size:1rem">
                            {{ number_format($rec->recommended_sph) }}
                        </strong>
                        <span style="color:#6b7280;font-size:0.75rem">
                            (+{{ $rec->increase_pct }}%)
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;align-items:center">
                            <a href="{{ route('master.part.edit', $rec->id_part) }}#proses-rec-{{ $rec->id_part_proses }}"
                               class="btn btn-sm"
                               style="background:var(--c-sph-prod);color:#fff;border:none;font-size:0.8rem;white-space:nowrap">
                                <i class="bi bi-arrow-up-circle me-1"></i>Lihat &amp; Terapkan
                            </a>
                            <form method="POST" action="{{ route('master.part.sph-dismiss') }}">
                                @csrf
                                <input type="hidden" name="id_part_proses" value="{{ $rec->id_part_proses }}">
                                <input type="hidden" name="type" value="produksi">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        style="font-size:0.8rem;white-space:nowrap"
                                        title="Abaikan rekomendasi ini">
                                    <i class="bi bi-x me-1"></i>Batal
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($dismissedProdCount > 0)
    <div style="padding:8px 14px;border-top:1px solid #fde68a;background:var(--c-sph-prod-bg);display:flex;align-items:center;gap:8px;font-size:0.82rem;color:#92400e">
        <i class="bi bi-eye-slash"></i>
        {{ $dismissedProdCount }} rekomendasi disembunyikan.
        <form method="POST" action="{{ route('master.part.sph-dismiss-reset') }}" style="display:inline">
            @csrf
            <input type="hidden" name="type" value="produksi">
            <button type="submit" style="background:none;border:none;color:#d97706;font-size:0.82rem;cursor:pointer;text-decoration:underline;padding:0">
                Tampilkan semua
            </button>
        </form>
    </div>
    @endif
</div>

{{-- ── REKOMENDASI UPGRADE SPH QUALITY ─────────────────────────── --}}
<div class="erp-panel mb-3" style="border-color:var(--c-sph-qc)">
    <div class="erp-panel-header"
         style="background:var(--c-sph-qc-bg-2);border-color:var(--c-sph-qc);color:var(--c-sph-qc-text)">
        <i class="bi bi-graph-up-arrow me-2"></i>
        <strong>Rekomendasi Upgrade SPH Standard Quality</strong>
        <span class="badge ms-2" style="background:var(--c-sph-qc);color:#fff;font-size:0.72rem">
            {{ $recommendationsQc->count() }} item
        </span>
    </div>
    <div style="padding:10px 16px;background:var(--c-sph-qc-bg-3);border-bottom:1px solid #b2d8e0;font-size:0.78rem;color:var(--c-sph-qc-text)">
        <i class="bi bi-info-circle me-1"></i>
        Kombinasi Part/Proses berikut konsisten menghasilkan SPH aktual QC di atas standar.
        Klik <strong>Lihat &amp; Terapkan</strong> untuk review dan approve upgrade di halaman edit part.
    </div>

    @if($recommendationsQc->isEmpty())
    <div style="text-align:center;padding:32px;color:#9ca3af">
        <i class="bi bi-check2-circle" style="font-size:1.6rem;display:block;margin-bottom:8px"></i>
        Nol rekomendasi QC tersisa.
    </div>
    @else
    <div style="overflow-x:auto">
        <table class="erp-table" style="margin:0">
            <thead>
                <tr style="background:var(--c-sph-qc-bg-2)">
                    <th style="white-space:nowrap">Part No</th>
                    <th style="white-space:nowrap">Part Name</th>
                    <th style="white-space:nowrap">Proses</th>
                    <th style="text-align:right;width:110px">SPH QC Std</th>
                    <th style="text-align:right;width:110px">SPH Rata-rata</th>
                    <th style="text-align:center;white-space:nowrap">Dilampaui</th>
                    <th style="text-align:right;width:130px">Rekomendasi</th>
                    <th style="width:150px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @foreach($recommendationsQc as $rec)
                <tr style="background:var(--c-sph-qc-bg)">
                    <td style="font-weight:600;white-space:nowrap">{{ $rec->part_no }}</td>
                    <td style="white-space:nowrap">{{ $rec->part_name }}</td>
                    <td style="white-space:nowrap">
                        <span style="font-size:0.78rem;color:#6b7280">{{ $rec->id_proses }}</span>
                        {{ $rec->nama_proses }}
                    </td>
                    <td style="text-align:right;color:#374151">
                        {{ number_format($rec->sph_standar) }}
                    </td>
                    <td style="text-align:right;color:var(--c-sph-qc);font-weight:600">
                        {{ number_format($rec->avg_all_sph) }}
                    </td>
                    <td style="text-align:center">
                        <span title="{{ $rec->exceed_count }} sesi dari {{ $rec->total_sessions }} sesi total">
                            {{ $rec->exceed_count }}x / {{ $rec->total_sessions }}x
                            <span style="color:var(--c-sph-qc-text-2)">({{ $rec->exceed_pct }}%)</span>
                        </span>
                    </td>
                    <td style="text-align:right">
                        <strong style="color:#2d7a2d;font-size:1rem">
                            {{ number_format($rec->recommended_sph) }}
                        </strong>
                        <span style="color:#6b7280;font-size:0.75rem">
                            (+{{ $rec->increase_pct }}%)
                        </span>
                    </td>
                    <td>
                        <div style="display:flex;gap:4px;align-items:center">
                            <a href="{{ route('master.part.edit', $rec->id_part) }}#proses-rec-{{ $rec->id_part_proses }}"
                               class="btn btn-sm"
                               style="background:var(--c-sph-qc);color:#fff;border:none;font-size:0.8rem;white-space:nowrap">
                                <i class="bi bi-arrow-up-circle me-1"></i>Lihat &amp; Terapkan
                            </a>
                            <form method="POST" action="{{ route('master.part.sph-dismiss') }}">
                                @csrf
                                <input type="hidden" name="id_part_proses" value="{{ $rec->id_part_proses }}">
                                <input type="hidden" name="type" value="qc">
                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                        style="font-size:0.8rem;white-space:nowrap"
                                        title="Abaikan rekomendasi ini">
                                    <i class="bi bi-x me-1"></i>Batal
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif

    @if($dismissedQcCount > 0)
    <div style="padding:8px 14px;border-top:1px solid var(--c-sph-qc-border);background:#f0f9fb;display:flex;align-items:center;gap:8px;font-size:0.82rem;color:var(--c-sph-qc-text)">
        <i class="bi bi-eye-slash"></i>
        {{ $dismissedQcCount }} rekomendasi disembunyikan.
        <form method="POST" action="{{ route('master.part.sph-dismiss-reset') }}" style="display:inline">
            @csrf
            <input type="hidden" name="type" value="qc">
            <button type="submit" style="background:none;border:none;color:var(--c-sph-qc);font-size:0.82rem;cursor:pointer;text-decoration:underline;padding:0">
                Tampilkan semua
            </button>
        </form>
    </div>
    @endif
</div>
@endsection
