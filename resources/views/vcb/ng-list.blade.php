@extends('layouts.app')
@section('title', $judul)

@php
    // Data typeahead. Bentuk {v,t} sama dengan filterSearchComp di /input/produksi,
    // supaya komponennya bisa dipakai apa adanya.
    $_jenisNgTA = $jenisNgOptions->map(fn ($j) => ['v' => $j->id, 't' => $j->kode.' — '.$j->nama_ng])->values();
    $_orangTA = $orangOptions->map(fn ($k) => ['v' => $k->id, 't' => $k->nama_karyawan])->values();

    $_jenisLabel = $filter['jenis_ng_id']
        ? (($j = $jenisNgOptions->firstWhere('id', $filter['jenis_ng_id'])) ? $j->kode.' — '.$j->nama_ng : '')
        : '';
    $_orangLabel = $filter['karyawan_id']
        ? ($orangOptions->firstWhere('id', $filter['karyawan_id'])?->nama_karyawan ?? '')
        : '';

    $filterAktif = $filter['jenis_ng_id'] || $filter['karyawan_id'] || $filter['q'];
@endphp

@push('styles')
<style>
    /* ══ Daftar NG — mengikuti /input/produksi ═══════════════════════════
     * Kelas .fbar-* dan .sc-* TIDAK ada di layout bersama; keduanya lokal di
     * input/produksi. Disalin ke sini supaya halaman tidak bergantung pada
     * berkas lain yang bisa berubah tanpa sepengetahuan halaman ini.
     * Kelas panel yang benar .erp-panel-header — .erp-panel-head tidak ada.
     */
    .fbar-lbl { font-size:.67rem; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:#94a3b8; margin-bottom:4px; }
    .fbar-inp { height:34px; width:100%; border-radius:3px; padding:0 26px 0 8px; font-size:.83rem; background:#fff; outline:none; transition:border-color 100ms; box-sizing:border-box; }
    .fbar-inp:focus { border-color:#64748b !important; }
    .fbar-dd { position:absolute; top:calc(100% + 2px); left:0; min-width:210px; background:#fff; border:1px solid #d1d5db; border-radius:3px; z-index:600; max-height:220px; overflow-y:auto; filter:drop-shadow(0 4px 12px rgba(0,0,0,.08)); }
    .fbar-dd-item { padding:7px 10px; cursor:pointer; font-size:.82rem; white-space:nowrap; color:#374151; transition:background 60ms; }
    .fbar-dd-item:hover { background:#f1f5f9; }
    .fbar-clr { position:absolute; right:6px; top:50%; transform:translateY(-50%); background:none; border:none; color:#94a3b8; cursor:pointer; padding:0; font-size:.95rem; line-height:1; }
    .fbar-date { height:34px; border:1px solid #e2e8f0; border-radius:3px; padding:0 8px; font-size:.83rem; color:#1e293b; width:100%; outline:none; transition:border-color 100ms; box-sizing:border-box; }
    .fbar-date:focus { border-color:#64748b; }

    /* Kolom memanjang mengikuti isi teks: nol pembungkusan, lebar dari konten. */
    #ngListTable td, #ngListTable th { white-space: nowrap; }
    .ngl-num { text-align:right; font-variant-numeric:tabular-nums; font-weight:600; }
    .ngl-mut { font-size:.88rem; color:#5d7283; }
    .ngl-kosong { padding:28px 14px; text-align:center; color:#5d7283; font-size:13px; white-space:normal; }
    .ngl-ringkas { font-size:12px; color:#5d7283; font-variant-numeric:tabular-nums; font-weight:400; }
</style>
@endpush

@section('content')
<div class="page-head">
    <h1 class="page-title">{{ $judul }}</h1>
    <p class="page-sub">Satu baris = satu jenis NG dalam satu sesi. Satu LHP dengan tiga jenis NG tampil sebagai tiga baris.</p>
</div>

{{-- ── FILTER ─────────────────────────────────────────────────────── --}}
<div class="erp-panel mb-3">
    <div class="erp-panel-header">Filter</div>
    <div class="erp-panel-body">
        <form method="GET" action="{{ $routeDaftar }}">
            <div style="display:flex;align-items:flex-end;gap:8px;flex-wrap:wrap">

                <div style="flex:1;min-width:120px;max-width:160px">
                    <div class="fbar-lbl">Tanggal Mulai</div>
                    <input type="date" name="date_from" value="{{ $filter['date_from'] }}"
                           class="fbar-date @error('date_from') is-invalid @enderror">
                    @error('date_from')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div style="flex:1;min-width:120px;max-width:160px">
                    <div class="fbar-lbl">Tanggal Akhir</div>
                    <input type="date" name="date_to" value="{{ $filter['date_to'] }}"
                           class="fbar-date @error('date_to') is-invalid @enderror">
                    @error('date_to')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                {{-- Jenis NG — typeahead --}}
                <div style="flex:1.5;min-width:170px"
                     x-data="filterSearchComp(_jenisNgTA, {{ $filter['jenis_ng_id'] ?: 0 }}, '{{ addslashes($_jenisLabel) }}')"
                     @click.outside="open=false">
                    <div class="fbar-lbl">Jenis NG</div>
                    <input type="hidden" name="jenis_ng_id" :value="val">
                    <div style="position:relative">
                        <input type="text" x-model="query" @focus="open=true" @input="onInput()" @keydown.escape="open=false"
                               placeholder="Semua jenis…" class="fbar-inp"
                               :style="val ? 'border:1.5px solid #334155;font-weight:500;color:#1e293b' : 'border:1px solid #e2e8f0;color:#64748b'">
                        <button type="button" x-show="val" @mousedown.prevent="clear()" class="fbar-clr">&times;</button>
                        <div x-show="open && filtered.length > 0" x-cloak class="fbar-dd">
                            <template x-for="item in filtered" :key="item.v">
                                <div @mousedown.prevent="pick(item)" class="fbar-dd-item" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Operator / Inspector — typeahead --}}
                <div style="flex:1.5;min-width:150px"
                     x-data="filterSearchComp(_orangTA, {{ $filter['karyawan_id'] ?: 0 }}, '{{ addslashes($_orangLabel) }}')"
                     @click.outside="open=false">
                    <div class="fbar-lbl">{{ $labelOrang }}</div>
                    <input type="hidden" name="karyawan_id" :value="val">
                    <div style="position:relative">
                        <input type="text" x-model="query" @focus="open=true" @input="onInput()" @keydown.escape="open=false"
                               placeholder="Semua {{ strtolower($labelOrang) }}…" class="fbar-inp"
                               :style="val ? 'border:1.5px solid #334155;font-weight:500;color:#1e293b' : 'border:1px solid #e2e8f0;color:#64748b'">
                        <button type="button" x-show="val" @mousedown.prevent="clear()" class="fbar-clr">&times;</button>
                        <div x-show="open && filtered.length > 0" x-cloak class="fbar-dd">
                            <template x-for="item in filtered" :key="item.v">
                                <div @mousedown.prevent="pick(item)" class="fbar-dd-item" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>

                {{-- Part / Customer SENGAJA tetap teks bebas, bukan typeahead: satu
                     input ini mencari tiga kolom sekaligus (part_no, part_name,
                     customer), jadi tidak ada satu daftar yang bisa dipilih. --}}
                <div style="flex:1.5;min-width:160px">
                    <div class="fbar-lbl">Part / Customer</div>
                    <input type="text" name="q" value="{{ $filter['q'] }}" maxlength="100"
                           placeholder="Part no, nama, customer…" class="fbar-inp"
                           style="{{ $filter['q'] ? 'border:1.5px solid #334155;font-weight:500;color:#1e293b' : 'border:1px solid #e2e8f0;color:#64748b' }}">
                    @error('q')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                </div>

                <div style="display:flex;gap:6px;align-items:flex-end">
                    <button type="submit" class="btn btn-dark btn-sm" style="height:34px">
                        <i class="bi bi-funnel"></i> Terapkan
                    </button>
                    <a href="{{ $routeDaftar }}" class="btn btn-outline-secondary btn-sm" style="height:34px;display:inline-flex;align-items:center">Reset</a>
                </div>
            </div>
        </form>
    </div>
</div>

{{-- ── DAFTAR ─────────────────────────────────────────────────────── --}}
<div class="erp-panel">
    <div class="erp-panel-header d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span>
            Daftar Baris NG
            <span class="ngl-ringkas">&mdash; {{ number_format($rows->total(), 0, ',', '.') }} baris{{ $filterAktif ? ' · filter aktif' : '' }}</span>
        </span>
        {{-- Guest sengaja tidak punya tombol export: route-nya juga menolak. --}}
        @if(auth()->user()->role !== 'guest')
        <a href="{{ $routeExport }}?{{ http_build_query(array_filter($filter, fn ($v) => $v !== null && $v !== '')) }}"
           class="btn btn-outline-dark btn-sm">
            <i class="bi bi-file-earmark-excel"></i> Export Excel
        </a>
        @endif
    </div>

    @if($rows->isEmpty())
        <div class="ngl-kosong">{{ $catatanKosong }}</div>
    @else
    {{-- Tanpa min-height: daftar ini sering menyusut jadi beberapa baris setelah
         difilter, dan tinggi minimum menyisakan kotak kosong yang terbaca rusak. --}}
    <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 320px)">
        <table id="ngListTable" class="erp-table">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th>Tanggal</th>
                    <th>Shift</th>
                    @if($sumber === 'produksi')<th>Mesin</th>@endif
                    <th>Customer</th>
                    <th>Part No</th>
                    <th>Part Name</th>
                    <th>Proses</th>
                    <th>{{ $labelOrang }}</th>
                    <th style="text-align:center">Kode NG</th>
                    <th>Jenis NG</th>
                    <th style="text-align:right">Qty NG</th>
                </tr>
            </thead>
            <tbody>
                @foreach($rows as $r)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($r->tanggal)->format('d/m/Y') }}</td>
                    <td class="ngl-mut">{{ $r->shift ?? '—' }}</td>
                    @if($sumber === 'produksi')<td>{{ $r->mesin ?? '—' }}</td>@endif
                    <td class="ngl-mut">{{ $r->customer ?? '—' }}</td>
                    <td>{{ $r->part_no ?? '—' }}</td>
                    <td class="ngl-mut">{{ $r->part_name ?? '—' }}</td>
                    <td class="ngl-mut">{{ $r->proses ?? '—' }}</td>
                    <td>{{ $r->orang ?? '—' }}</td>
                    <td style="text-align:center;font-weight:600">{{ $r->kode_ng }}</td>
                    <td>{{ $r->nama_ng }}</td>
                    <td class="ngl-num" style="color:#c0392b">{{ number_format($r->qty, 0, ',', '.') }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @endif
</div>

{{-- Paginasi mempertahankan filter aktif lewat withQueryString() di service. --}}
@if($rows->hasPages())
<div class="mt-3">{{ $rows->links() }}</div>
@endif
@endsection

@push('scripts')
<script>
const _jenisNgTA = @json($_jenisNgTA);
const _orangTA   = @json($_orangTA);

/* Disalin dari /input/produksi. Sengaja tidak di-share ke layout: memindahkannya
   menyentuh halaman input yang jauh lebih ramai, dan itu perubahan tersendiri. */
function filterSearchComp(items, initVal, initLabel) {
    return {
        items,
        query: initLabel || '',
        val:   (initVal !== undefined && initVal !== null && initVal !== 0 && initVal !== '0') ? initVal : '',
        open:  false,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 40);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 40);
        },
        pick(item) { this.val = item.v; this.query = item.t; this.open = false; },
        clear()    { this.val = ''; this.query = ''; },
        onInput()  { this.val = ''; this.open = true; },
    };
}
</script>
@endpush
