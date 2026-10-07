@extends('layouts.app')
@section('title', 'Master Shift')
@section('suppress_layout_flash', 'yes')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

<div x-data="{ filterOpen: false }">

{{-- ══ KONTEN HALAMAN ══════════════════════════════════════════ --}}

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <a href="{{ route('master.shift.create') }}" class="erp-tb-btn is-new">
        <i class="bi bi-plus-lg"></i>Tambah
    </a>
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter @if($search !== '' || $filterAvail !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <button type="button" class="erp-tb-btn"
            onclick="document.getElementById('import-panel').classList.toggle('d-none')">
        <i class="bi bi-file-earmark-arrow-up"></i>Import Excel
    </button>
    {{-- Ekspor membawa filter yang sedang aktif — isi file = isi tabel. --}}
    <a href="{{ route('master.shift.export', request()->query()) }}" class="erp-tb-btn">
        <i class="bi bi-file-earmark-excel"></i>Export Excel
    </a>
    <a href="{{ route('master.shift.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('master.shift.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks :value="$search" placeholder="Nama Shift" />
        <x-filter.cari name="filter_avail" placeholder="Dihitung Availability" :value="$filterAvail"
                       :items="[['v' => 'ya', 't' => 'Dihitung Availability'], ['v' => 'tidak', 't' => 'Tidak Dihitung']]" />
    </x-filter.grup>
</x-filter.dock>

<div class="erp-ws-main">
{{-- Judul sudah ada di topbar; penggulungan dipegang .erp-scroll saja. --}}
<div class="erp-scroll">



@include('partials.import-errors')

<div id="import-panel" class="erp-panel mb-3 d-none">
    <div class="erp-panel-header" style="display:flex;justify-content:space-between;align-items:center">
        <span><i class="bi bi-file-earmark-arrow-up me-1"></i>Import Shift dari Excel</span>
        <a href="{{ route('master.shift.import-template') }}" class="btn btn-outline-secondary btn-sm">
            <i class="bi bi-download me-1"></i>Download Template
        </a>
    </div>
    <div class="erp-panel-body">
        <form method="POST" action="{{ route('master.shift.import') }}" enctype="multipart/form-data">
            @csrf
            <div class="row g-2 align-items-end">
                <div class="col-md-6">
                    <label class="form-label mb-1" style="font-size:0.8rem">File Excel (.xlsx / .xls / .csv)</label>
                    <input type="file" name="file" class="form-control form-control-sm @error('file') is-invalid @enderror"
                           accept=".xlsx,.xls,.csv">
                    @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-auto">
                    <button type="submit" class="btn btn-success btn-sm">
                        <i class="bi bi-upload me-1"></i>Upload & Import
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

        <table class="erp-table" style="min-width:820px">
            <thead style="position:sticky;top:0;z-index:10">
                <tr>
                    <th style="width:140px">Nama Shift</th>
                    <th style="min-width:200px">
                        <span style="color:#1a6b7c">Senin – Kamis</span>
                    </th>
                    <th style="min-width:200px">
                        <span style="color:#7a5c00">Jum'at</span>
                    </th>
                    <th style="min-width:180px">
                        <span style="color:#5a3ea0">Sabtu</span>
                    </th>
                    <th style="width:100px">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($shifts as $s)
                @php
                    $iSK = $s->istirahat->where('kelompok_hari','senin_kamis');
                    $iJm = $s->istirahat->where('kelompok_hari','jumat');
                    $iSb = $s->istirahat->where('kelompok_hari','sabtu');
                @endphp
                <tr>
                    <td style="font-weight:700">{{ $s->nama_shift }}</td>

                    {{-- Senin–Kamis --}}
                    <td>
                        <div style="font-weight:600;color:#1a6b7c">
                            {{ substr($s->jam_start,0,5) }} – {{ substr($s->jam_finish,0,5) }}
                        </div>
                        @foreach($iSK as $ist)
                            <div style="font-size:0.75rem;color:var(--text-3)">
                                <i class="bi bi-cup-hot" style="color:#bbb"></i>
                                {{ substr($ist->jam_start,0,5) }} – {{ substr($ist->jam_finish,0,5) }}
                            </div>
                        @endforeach
                    </td>

                    {{-- Jumat --}}
                    <td>
                        @if($s->jam_start_jumat)
                            <div style="font-weight:600;color:#7a5c00">
                                {{ substr($s->jam_start_jumat,0,5) }} – {{ substr($s->jam_finish_jumat,0,5) }}
                            </div>
                            @foreach($iJm as $ist)
                                <div style="font-size:0.75rem;color:var(--text-3)">
                                    <i class="bi bi-cup-hot" style="color:#bbb"></i>
                                    {{ substr($ist->jam_start,0,5) }} – {{ substr($ist->jam_finish,0,5) }}
                                </div>
                            @endforeach
                        @else
                            <span style="color:#ccc;font-size:0.8rem">—</span>
                        @endif
                    </td>

                    {{-- Sabtu --}}
                    <td>
                        @if($s->jam_start_sabtu)
                            <div style="font-weight:600;color:#5a3ea0">
                                {{ substr($s->jam_start_sabtu,0,5) }} – {{ substr($s->jam_finish_sabtu,0,5) }}
                            </div>
                            @foreach($iSb as $ist)
                                <div style="font-size:0.75rem;color:var(--text-3)">
                                    <i class="bi bi-cup-hot" style="color:#bbb"></i>
                                    {{ substr($ist->jam_start,0,5) }} – {{ substr($ist->jam_finish,0,5) }}
                                </div>
                            @endforeach
                        @else
                            <span style="color:#ccc;font-size:0.8rem">—</span>
                        @endif
                    </td>

                    <td>
                        <div class="action-btns">
                            <a href="{{ route('master.shift.edit', $s) }}" class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" action="{{ route('master.shift.destroy', $s) }}"
                                  data-konfirmasi="Hapus shift {{ $s->nama_shift }}?">
                                @csrf @method('DELETE')
                                <button class="btn btn-danger btn-sm">Hapus</button>
                            </form>
                        </div>
                    </td>
                </tr>
            @empty
                <x-tabel-kosong kolom="5" entitas="data shift" :tambah="route('master.shift.create')" />
            @endforelse
            </tbody>
        </table>
</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $shifts->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span><b>{{ number_format($shifts->total()) }}</b> shift</span>
    @if($search !== '' || $filterAvail !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}


</div>{{-- /alpine outer scope --}}
@endsection
