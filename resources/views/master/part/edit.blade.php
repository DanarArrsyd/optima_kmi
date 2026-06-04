@extends('layouts.app')
@section('title', 'Edit Part — ' . $part->part_no)
@section('content')

{{-- ══ ALPINE SCOPE (drawer tambah proses) ══ --}}
<div x-data="{ drawerOpen: {{ $errors->has('id_proses') || $errors->has('nama_proses') || $errors->has('sph_standar_produksi') ? 'true' : 'false' }} }">

{{-- ── DRAWER BACKDROP ─────────────────────────────────────────── --}}
<div x-show="drawerOpen" x-cloak @click="drawerOpen = false"
     x-transition:enter="transition ease-out duration-200"
     x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
     x-transition:leave="transition ease-in duration-150"
     x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
     style="position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1040"></div>

{{-- ── DRAWER PANEL — Tambah Proses ───────────────────────────── --}}
<div x-show="drawerOpen" x-cloak
     x-transition:enter="transition ease-out duration-250"
     x-transition:enter-start="transform translate-x-full" x-transition:enter-end="transform translate-x-0"
     x-transition:leave="transition ease-in duration-200"
     x-transition:leave-start="transform translate-x-0" x-transition:leave-end="transform translate-x-full"
     style="position:fixed;top:0;right:0;height:100vh;width:520px;max-width:100vw;background:#fff;z-index:1050;display:flex;flex-direction:column;box-shadow:-4px 0 24px rgba(0,0,0,.18)">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 20px;background:#111827;color:#fff;flex-shrink:0">
        <div style="font-weight:700;font-size:0.95rem">
            <i class="bi bi-plus-circle me-2"></i>Tambah Proses — {{ $part->part_no }}
        </div>
        <button @click="drawerOpen = false" style="background:none;border:none;color:#fff;font-size:1.25rem;cursor:pointer;padding:0">
            <i class="bi bi-x-lg"></i>
        </button>
    </div>
    <div style="flex:1;overflow-y:auto;padding:24px">
        @if($errors->has('id_proses') || $errors->has('nama_proses') || $errors->has('sph_standar_produksi'))
        <div class="alert alert-danger mb-4" style="font-size:0.82rem">
            <ul class="mb-0 ps-3">@foreach($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
        </div>
        @endif

        <form method="POST" action="{{ route('master.part.proses.store', $part) }}">
            @csrf
            <div class="row g-3 mb-4">
                <div class="col-4">
                    <label class="form-label" style="font-size:0.82rem;font-weight:600">ID Proses <span class="text-danger">*</span></label>
                    <input type="text" name="id_proses"
                           class="form-control form-control-sm @error('id_proses') is-invalid @enderror"
                           placeholder="1" value="{{ old('id_proses') }}">
                    @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-8">
                    <label class="form-label" style="font-size:0.82rem;font-weight:600">Nama Proses <span class="text-danger">*</span></label>
                    <input type="text" name="nama_proses"
                           class="form-control form-control-sm @error('nama_proses') is-invalid @enderror"
                           placeholder="PROGRESSIVE / BENDING / dll"
                           value="{{ old('nama_proses') }}">
                    @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
            </div>

            <div style="background:#f8fafc;border:1px solid #e5e7eb;border-radius:6px;padding:16px;margin-bottom:20px">
                <div style="font-size:0.72rem;font-weight:700;color:#6b7280;letter-spacing:.06em;text-transform:uppercase;margin-bottom:12px">
                    <i class="bi bi-speedometer2 me-1"></i>Standar SPH
                </div>
                <div class="row g-3 mb-3">
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">SPH Produksi <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="number" step="1" name="sph_standar_produksi"
                                   class="form-control @error('sph_standar_produksi') is-invalid @enderror"
                                   value="{{ old('sph_standar_produksi', 0) }}" min="0">
                            <span class="input-group-text" style="font-size:0.75rem">pcs/jam</span>
                        </div>
                        @error('sph_standar_produksi')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">SPH QC <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="number" step="1" name="sph_standar_qc"
                                   class="form-control @error('sph_standar_qc') is-invalid @enderror"
                                   value="{{ old('sph_standar_qc', 0) }}" min="0">
                            <span class="input-group-text" style="font-size:0.75rem">pcs/jam</span>
                        </div>
                        @error('sph_standar_qc')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
                <div style="font-size:0.72rem;font-weight:700;color:#6b7280;letter-spacing:.06em;text-transform:uppercase;margin-bottom:12px">
                    <i class="bi bi-clock me-1"></i>Standar Waktu Dandori
                </div>
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">Std Trial QC <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="std_trial_qc_menit"
                                   class="form-control @error('std_trial_qc_menit') is-invalid @enderror"
                                   value="{{ old('std_trial_qc_menit', 0) }}" min="0">
                            <span class="input-group-text" style="font-size:0.75rem">mnt</span>
                        </div>
                        @error('std_trial_qc_menit')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                    <div class="col-6">
                        <label class="form-label" style="font-size:0.8rem">Std Setting Dies <span class="text-danger">*</span></label>
                        <div class="input-group input-group-sm">
                            <input type="number" name="std_setting_dies_menit"
                                   class="form-control @error('std_setting_dies_menit') is-invalid @enderror"
                                   value="{{ old('std_setting_dies_menit', 0) }}" min="0">
                            <span class="input-group-text" style="font-size:0.75rem">mnt</span>
                        </div>
                        @error('std_setting_dies_menit')<div class="text-danger small mt-1">{{ $message }}</div>@enderror
                    </div>
                </div>
            </div>

            <div style="display:flex;gap:8px;border-top:1px solid #e5e7eb;padding-top:16px">
                <button type="submit" class="btn btn-success btn-sm" style="flex:1">
                    <i class="bi bi-plus-lg me-1"></i>Tambah Proses
                </button>
                <button type="button" @click="drawerOpen = false" class="btn btn-outline-secondary btn-sm">Batal</button>
            </div>
        </form>
    </div>
</div>

{{-- ══ PAGE CONTENT ════════════════════════════════════════════════ --}}

<div class="page-header">
    <div style="display:flex;align-items:center;gap:12px">
        <h1 class="page-title" style="margin:0"><i class="bi bi-box-seam"></i>Edit Part</h1>
        <div style="display:flex;gap:6px;align-items:center">
            @if($part->customer)
            <span style="font-size:0.75rem;background:#f0f2f5;border:1px solid #d4dbe3;padding:2px 8px;color:#5d7283">{{ $part->customer }}</span>
            @endif
            <span style="font-size:0.88rem;font-weight:700;color:#374151">{{ $part->part_no }}</span>
            <span style="color:#9ca3af">·</span>
            <span style="font-size:0.85rem;color:#6b7280">{{ $part->part_name }}</span>
        </div>
    </div>
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm">
        <i class="bi bi-arrow-left me-1"></i>Kembali
    </a>
</div>

@if(session('success'))
    <div class="alert alert-success alert-dismissible fade show mb-3">
        <i class="bi bi-check-circle-fill me-1"></i>{{ session('success') }}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif
@if(session('error'))
    <div class="alert alert-danger alert-dismissible fade show mb-3">
        {{ session('error') }}<button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
@endif

<div class="row g-4">

    {{-- LEFT: Info Part --}}
    <div class="col-lg-3">
        <div class="erp-panel" style="position:sticky;top:16px">
            <div class="erp-panel-header"><i class="bi bi-pencil-square me-1"></i>Info Part</div>
            <div class="erp-panel-body">
                <form method="POST" action="{{ route('master.part.update', $part) }}">
                    @csrf @method('PUT')
                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem">Customer</label>
                        <input type="text" name="customer"
                               class="form-control form-control-sm @error('customer') is-invalid @enderror"
                               value="{{ old('customer', $part->customer) }}" placeholder="Toyota, Honda…">
                        @error('customer')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-3">
                        <label class="form-label" style="font-size:0.8rem">Part No <span class="text-danger">*</span></label>
                        <input type="text" name="part_no"
                               class="form-control form-control-sm @error('part_no') is-invalid @enderror"
                               value="{{ old('part_no', $part->part_no) }}">
                        @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <div class="mb-4">
                        <label class="form-label" style="font-size:0.8rem">Part Name <span class="text-danger">*</span></label>
                        <input type="text" name="part_name"
                               class="form-control form-control-sm @error('part_name') is-invalid @enderror"
                               value="{{ old('part_name', $part->part_name) }}">
                        @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    </div>
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="bi bi-save me-1"></i>Update Part
                    </button>
                </form>
                <div style="margin-top:16px;padding-top:14px;border-top:1px solid #e5e7eb">
                    <div style="font-size:0.72rem;color:#9ca3af;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Ringkasan</div>
                    <div style="display:flex;justify-content:space-between;margin-bottom:6px">
                        <span style="font-size:0.82rem;color:#6b7280">Total Proses</span>
                        <strong style="font-size:0.82rem">{{ $part->proses->count() }}</strong>
                    </div>
                    @if($recommendations->count() || $recommendationsQc->count())
                    <div style="display:flex;justify-content:space-between">
                        <span style="font-size:0.82rem;color:#6b7280">Rekomendasi SPH</span>
                        <span style="font-size:0.82rem">
                            @if($recommendations->count())<span style="color:#d4a017;font-weight:600">{{ $recommendations->count() }} Prod</span>@endif
                            @if($recommendationsQc->count())<span style="color:#1a6b7c;font-weight:600" class="{{ $recommendations->count() ? 'ms-1' : '' }}">{{ $recommendationsQc->count() }} QC</span>@endif
                        </span>
                    </div>
                    @endif
                </div>
            </div>
        </div>
    </div>

    {{-- RIGHT: Daftar Proses --}}
    <div class="col-lg-9">
        <div class="erp-panel">
            <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
                <span><i class="bi bi-list-ol me-1"></i>Daftar Proses <span class="badge bg-secondary ms-1">{{ $part->proses->count() }}</span></span>
                <button class="btn btn-success btn-sm" @click="drawerOpen = true">
                    <i class="bi bi-plus-lg me-1"></i>Tambah Proses
                </button>
            </div>

            @if($part->proses->isEmpty())
            <div style="text-align:center;padding:40px;color:#9ca3af">
                <i class="bi bi-inbox" style="font-size:2rem;display:block;margin-bottom:10px"></i>
                <div style="margin-bottom:12px">Belum ada proses. Tambahkan proses pertama.</div>
                <button class="btn btn-success btn-sm" @click="drawerOpen = true">
                    <i class="bi bi-plus-lg me-1"></i>Tambah Proses
                </button>
            </div>
            @else
            <div style="overflow-x:auto">
                <table class="erp-table" style="min-width:720px">
                    <thead>
                        <tr>
                            <th style="width:60px;text-align:center">ID</th>
                            <th>Nama Proses</th>
                            <th style="width:140px;text-align:right">SPH Produksi</th>
                            <th style="width:140px;text-align:right">SPH QC</th>
                            <th style="width:100px;text-align:right">Trial QC</th>
                            <th style="width:110px;text-align:right">Setting Dies</th>
                            <th style="width:70px">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($part->proses as $p)
                        @php
                            $rec   = $recommendations->get($p->id)   ?? null;
                            $recQc = $recommendationsQc->get($p->id) ?? null;
                            $rowBg = ($rec && $recQc) ? 'background:#fff9ee' : ($rec ? 'background:#fffdf0' : ($recQc ? 'background:#f5fbfd' : ''));
                        @endphp
                        {{-- Main row --}}
                        <tr style="{{ $rowBg }}">
                            <td style="text-align:center;font-weight:700;color:#374151">{{ $p->id_proses }}</td>
                            <td>
                                <span style="font-weight:600">{{ $p->nama_proses }}</span>
                                @if(strtoupper(trim($p->nama_proses)) === 'PROGRESSIVE')
                                    <span class="badge bg-info text-dark ms-1" style="font-size:0.65rem">PROG</span>
                                @endif
                                @if($rec)
                                    <span class="badge ms-1" style="background:#d4a017;color:#fff;font-size:0.62rem">Prod ↑</span>
                                @endif
                                @if($recQc)
                                    <span class="badge ms-1" style="background:#1a6b7c;color:#fff;font-size:0.62rem">QC ↑</span>
                                @endif
                            </td>
                            <td style="text-align:right">
                                <span style="font-weight:600">{{ number_format($p->sph_standar_produksi) }}</span>
                                <span style="font-size:0.72rem;color:#9ca3af"> pcs/j</span>
                                @if($p->sph_last_upgraded_at)
                                <br><span style="font-size:0.68rem;color:#2d7a2d">
                                    <i class="bi bi-arrow-up-circle-fill"></i> {{ $p->sph_last_upgraded_at->format('d M Y') }}
                                </span>
                                @endif
                                @if($p->sphLog->isNotEmpty())
                                <br><button type="button" onclick="toggleEl('sph-log-{{ $p->id }}')"
                                        style="background:none;border:none;padding:0;font-size:0.65rem;color:#6b7280;cursor:pointer">
                                    <i class="bi bi-clock-history"></i> {{ $p->sphLog->count() }}× riwayat
                                </button>
                                @endif
                            </td>
                            <td style="text-align:right">
                                <span style="font-weight:600">{{ number_format($p->sph_standar_qc) }}</span>
                                <span style="font-size:0.72rem;color:#9ca3af"> pcs/j</span>
                                @if($p->sph_qc_last_upgraded_at)
                                <br><span style="font-size:0.68rem;color:#1a6b7c">
                                    <i class="bi bi-arrow-up-circle-fill"></i> {{ $p->sph_qc_last_upgraded_at->format('d M Y') }}
                                </span>
                                @endif
                                @if($p->sphQcLog->isNotEmpty())
                                <br><button type="button" onclick="toggleEl('sph-qc-log-{{ $p->id }}')"
                                        style="background:none;border:none;padding:0;font-size:0.65rem;color:#6b7280;cursor:pointer">
                                    <i class="bi bi-clock-history"></i> {{ $p->sphQcLog->count() }}× riwayat
                                </button>
                                @endif
                            </td>
                            <td style="text-align:right;color:#374151">{{ $p->std_trial_qc_menit }} <span style="font-size:0.72rem;color:#9ca3af">mnt</span></td>
                            <td style="text-align:right;font-weight:600;color:#374151">{{ $p->std_setting_dies_menit }} <span style="font-size:0.72rem;color:#9ca3af">mnt</span></td>
                            <td>
                                <form method="POST" action="{{ route('master.part.proses.destroy', [$part, $p]) }}"
                                      onsubmit="return confirm('Hapus proses {{ addslashes($p->nama_proses) }}?')">
                                    @csrf @method('DELETE')
                                    <button class="btn btn-danger btn-sm">Hapus</button>
                                </form>
                            </td>
                        </tr>

                        {{-- Riwayat SPH Produksi (collapsible) --}}
                        @if($p->sphLog->isNotEmpty())
                        <tr id="sph-log-{{ $p->id }}" style="display:none;background:#f8fafc">
                            <td colspan="7" style="padding:0 12px 10px;border-top:none">
                                <div style="border:1px solid #d1d5db;border-radius:4px;overflow:hidden;margin-top:4px">
                                    <div style="background:#f1f5f9;padding:5px 10px;font-size:0.72rem;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:.04em">
                                        <i class="bi bi-clock-history me-1"></i>Riwayat Upgrade SPH Produksi — {{ $p->nama_proses }}
                                    </div>
                                    <table style="width:100%;font-size:0.78rem;border-collapse:collapse">
                                        <thead><tr style="background:#e2e8f0">
                                            <th style="padding:4px 10px;font-weight:600;text-align:left">Tanggal</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:right">Lama</th>
                                            <th style="padding:4px 10px;text-align:center">→</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:right">Baru</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:right">+%</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:left">Oleh</th>
                                        </tr></thead>
                                        <tbody>
                                        @foreach($p->sphLog as $log)
                                            @php $lp = $log->sph_standar_lama > 0 ? round(($log->sph_standar_baru/$log->sph_standar_lama-1)*100) : 0; @endphp
                                            <tr style="border-top:1px solid #e5e7eb">
                                                <td style="padding:4px 10px">{{ $log->upgraded_at->format('d M Y') }} <span style="color:#9ca3af">{{ $log->upgraded_at->format('H:i') }}</span></td>
                                                <td style="padding:4px 10px;text-align:right;color:#6b7280">{{ number_format($log->sph_standar_lama) }}</td>
                                                <td style="padding:4px 10px;text-align:center;color:#d4a017">→</td>
                                                <td style="padding:4px 10px;text-align:right;font-weight:700;color:#2d7a2d">{{ number_format($log->sph_standar_baru) }}</td>
                                                <td style="padding:4px 10px;text-align:right;color:#2d7a2d">+{{ $lp }}%</td>
                                                <td style="padding:4px 10px">{{ $log->upgradedBy?->name ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                        @endif

                        {{-- Riwayat SPH QC (collapsible) --}}
                        @if($p->sphQcLog->isNotEmpty())
                        <tr id="sph-qc-log-{{ $p->id }}" style="display:none;background:#f0f8fa">
                            <td colspan="7" style="padding:0 12px 10px;border-top:none">
                                <div style="border:1px solid #a5d8e0;border-radius:4px;overflow:hidden;margin-top:4px">
                                    <div style="background:#e0f2f7;padding:5px 10px;font-size:0.72rem;font-weight:700;color:#1a6b7c;text-transform:uppercase;letter-spacing:.04em">
                                        <i class="bi bi-clock-history me-1"></i>Riwayat Upgrade SPH QC — {{ $p->nama_proses }}
                                    </div>
                                    <table style="width:100%;font-size:0.78rem;border-collapse:collapse">
                                        <thead><tr style="background:#ccedf5">
                                            <th style="padding:4px 10px;font-weight:600;text-align:left;color:#1a6b7c">Tanggal</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:right;color:#1a6b7c">Lama</th>
                                            <th style="padding:4px 10px;text-align:center;color:#1a6b7c">→</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:right;color:#1a6b7c">Baru</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:right;color:#1a6b7c">+%</th>
                                            <th style="padding:4px 10px;font-weight:600;text-align:left;color:#1a6b7c">Oleh</th>
                                        </tr></thead>
                                        <tbody>
                                        @foreach($p->sphQcLog as $log)
                                            @php $lp = $log->sph_standar_lama > 0 ? round(($log->sph_standar_baru/$log->sph_standar_lama-1)*100) : 0; @endphp
                                            <tr style="border-top:1px solid #b2d8e4">
                                                <td style="padding:4px 10px">{{ $log->upgraded_at->format('d M Y') }} <span style="color:#9ca3af">{{ $log->upgraded_at->format('H:i') }}</span></td>
                                                <td style="padding:4px 10px;text-align:right;color:#6b7280">{{ number_format($log->sph_standar_lama) }}</td>
                                                <td style="padding:4px 10px;text-align:center;color:#1a6b7c">→</td>
                                                <td style="padding:4px 10px;text-align:right;font-weight:700;color:#1a6b7c">{{ number_format($log->sph_standar_baru) }}</td>
                                                <td style="padding:4px 10px;text-align:right;color:#1a6b7c">+{{ $lp }}%</td>
                                                <td style="padding:4px 10px">{{ $log->upgradedBy?->name ?? '—' }}</td>
                                            </tr>
                                        @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </td>
                        </tr>
                        @endif

                        {{-- Rekomendasi SPH Produksi --}}
                        @if($rec)
                        <tr style="background:#fffdf0">
                            <td colspan="7" style="padding:0 12px 10px;border-top:none">
                                <div style="border:1px solid #d4a017;border-radius:4px;overflow:hidden;margin-top:4px">
                                    <div style="background:#d4a017;padding:6px 12px;display:flex;align-items:center;justify-content:space-between">
                                        <span style="font-size:0.75rem;font-weight:700;color:#fff">
                                            <i class="bi bi-graph-up-arrow me-1"></i>Rekomendasi Upgrade SPH Produksi
                                        </span>
                                        <span style="font-size:0.7rem;color:rgba(255,255,255,.85)">
                                            {{ $rec->exceed_count }}× dari {{ $rec->total_sessions }} sesi ({{ $rec->exceed_pct }}%)
                                        </span>
                                    </div>
                                    <div style="background:#fffdf5;padding:10px 14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                                        <div style="display:flex;align-items:center;gap:10px">
                                            <span style="font-size:1.4rem;font-weight:700;color:#374151">{{ number_format($rec->sph_standar_produksi) }}</span>
                                            <span style="color:#d4a017;font-size:1.2rem">→</span>
                                            <span style="font-size:1.4rem;font-weight:800;color:#2d7a2d">{{ number_format($rec->recommended_sph) }}</span>
                                            <span style="font-size:0.8rem;color:#6b7280">pcs/jam &nbsp;<strong style="color:#2d7a2d">+{{ $rec->increase_pct }}%</strong></span>
                                        </div>
                                        <div style="font-size:0.75rem;color:#6b7280;flex:1">
                                            <i class="bi bi-trophy me-1" style="color:#d4a017"></i>Terbaik: <strong>{{ number_format($rec->max_sph) }}</strong>
                                            &nbsp;·&nbsp; Rata-rata: <strong>{{ number_format($rec->avg_exceed_sph) }}</strong>
                                        </div>
                                        <form method="POST" action="{{ route('master.part.proses.upgrade-sph', [$part, $p]) }}"
                                              onsubmit="return confirm('Terapkan upgrade SPH Produksi {{ addslashes($p->nama_proses) }}?\n{{ $rec->sph_standar_produksi }} → {{ $rec->recommended_sph }} pcs/jam')">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="recommended_sph" value="{{ $rec->recommended_sph }}">
                                            <button type="submit" class="btn btn-sm" style="background:#2d7a2d;color:#fff;border:none;padding:6px 14px;font-weight:600;font-size:0.8rem">
                                                <i class="bi bi-arrow-up-circle me-1"></i>Terapkan {{ number_format($rec->recommended_sph) }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endif

                        {{-- Rekomendasi SPH QC --}}
                        @if($recQc)
                        <tr style="background:#f5fbfd">
                            <td colspan="7" style="padding:0 12px 10px;border-top:none">
                                <div style="border:1px solid #1a6b7c;border-radius:4px;overflow:hidden;margin-top:4px">
                                    <div style="background:#1a6b7c;padding:6px 12px;display:flex;align-items:center;justify-content:space-between">
                                        <span style="font-size:0.75rem;font-weight:700;color:#fff">
                                            <i class="bi bi-graph-up-arrow me-1"></i>Rekomendasi Upgrade SPH QC
                                        </span>
                                        <span style="font-size:0.7rem;color:rgba(255,255,255,.85)">
                                            {{ $recQc->exceed_count }}× dari {{ $recQc->total_sessions }} sesi ({{ $recQc->exceed_pct }}%)
                                        </span>
                                    </div>
                                    <div style="background:#f5fbfd;padding:10px 14px;display:flex;align-items:center;gap:16px;flex-wrap:wrap">
                                        <div style="display:flex;align-items:center;gap:10px">
                                            <span style="font-size:1.4rem;font-weight:700;color:#374151">{{ number_format($recQc->sph_standar_qc) }}</span>
                                            <span style="color:#1a6b7c;font-size:1.2rem">→</span>
                                            <span style="font-size:1.4rem;font-weight:800;color:#1a6b7c">{{ number_format($recQc->recommended_sph) }}</span>
                                            <span style="font-size:0.8rem;color:#6b7280">pcs/jam &nbsp;<strong style="color:#1a6b7c">+{{ $recQc->increase_pct }}%</strong></span>
                                        </div>
                                        <div style="font-size:0.75rem;color:#6b7280;flex:1">
                                            <i class="bi bi-trophy me-1" style="color:#1a6b7c"></i>Terbaik: <strong>{{ number_format($recQc->max_sph) }}</strong>
                                            &nbsp;·&nbsp; Rata-rata: <strong>{{ number_format($recQc->avg_exceed_sph) }}</strong>
                                        </div>
                                        <form method="POST" action="{{ route('master.part.proses.upgrade-qc-sph', [$part, $p]) }}"
                                              onsubmit="return confirm('Terapkan upgrade SPH QC {{ addslashes($p->nama_proses) }}?\n{{ $recQc->sph_standar_qc }} → {{ $recQc->recommended_sph }} pcs/jam')">
                                            @csrf @method('PATCH')
                                            <input type="hidden" name="recommended_sph" value="{{ $recQc->recommended_sph }}">
                                            <button type="submit" class="btn btn-sm" style="background:#1a6b7c;color:#fff;border:none;padding:6px 14px;font-weight:600;font-size:0.8rem">
                                                <i class="bi bi-arrow-up-circle me-1"></i>Terapkan {{ number_format($recQc->recommended_sph) }}
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </td>
                        </tr>
                        @endif

                    @endforeach
                    </tbody>
                </table>
            </div>
            @endif
        </div>
    </div>

</div>{{-- /row --}}
</div>{{-- /alpine --}}

@push('scripts')
<script>
function toggleEl(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = el.style.display === 'none' ? 'table-row' : 'none';
}
</script>
@endpush
@endsection
