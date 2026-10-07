@extends('layouts.app')
@section('title', 'Log Aktivitas')
@section('suppress_layout_flash', 'yes')
@section('main_mod', 'erp-flush')

@section('content')

<div class="erp-ws" x-data="{ filterOpen: false }">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
        @if($modul !== '' || $event !== '' || $userId !== '' || $search !== '')<span aria-hidden="true">&bull;</span>@endif
    </button>
    <a href="{{ route('sistem.log-aktivitas.index') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock :action="route('sistem.log-aktivitas.index')">
    <x-filter.grup judul="Find">
        <x-filter.teks name="search" :value="$search" placeholder="Cari deskripsi..." />
        <x-filter.cari name="modul" placeholder="Modul" :value="$modul"
                       :items="collect($modulLabels)->map(fn ($label, $key) => ['v' => $key, 't' => $label])" />
        <x-filter.cari name="event" placeholder="Aksi" :value="$event"
                       :items="collect($eventLabels)->map(fn ($label, $key) => ['v' => $key, 't' => $label])" />
        <x-filter.cari name="user_id" placeholder="User" :value="$userId"
                       :items="$userList->map(fn ($u) => ['v' => $u->id, 't' => $u->name])" />
    </x-filter.grup>

    <x-filter.tanggal :from="$dateFrom" :to="$dateTo" :pakai="$pakaiTanggal" />
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">

@if($logs->isEmpty())
<div class="erp-panel">
    <div style="text-align:center;padding:48px 24px;color:#9ca3af">
        <i class="bi bi-journal-text" style="font-size:2.5rem;display:block;margin-bottom:12px"></i>
        <div style="font-weight:700;font-size:1rem;color:#374151;margin-bottom:4px">Nol aktivitas</div>
        <div style="font-size:0.88rem">Nol aktivitas tercatat pada filter/rentang tanggal ini.</div>
    </div>
</div>
@else
<div style="overflow-x:auto">
<table class="erp-table" style="min-width:1100px">
    <thead>
        <tr>
            <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">Waktu</th>
            <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">User</th>
            <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">Modul</th>
            <th style="position:sticky;top:0;z-index:10;background:#e5e7eb;white-space:nowrap">Aksi</th>
            <th style="position:sticky;top:0;z-index:10;background:#e5e7eb">Deskripsi</th>
            <th style="position:sticky;top:0;z-index:10;background:#e5e7eb">Perubahan</th>
        </tr>
    </thead>
    <tbody>
    @foreach($logs as $log)
        @php
            $eventBadge = match($log->event) {
                'created' => ['#f0fdf4', '#15803d'],
                'updated' => ['#eff6ff', '#1d4ed8'],
                'deleted' => ['#fef2f2', '#b91c1c'],
                'login' => ['#f0fdf4', '#15803d'],
                'logout' => ['#f9fafb', '#6b7280'],
                'failed_login' => ['#fef2f2', '#b91c1c'],
                default => ['#f9fafb', '#6b7280'],
            };
            $old = $log->properties['old'] ?? [];
            $baru = $log->properties['attributes'] ?? [];
            $diffKeys = array_unique(array_merge(array_keys($old), array_keys($baru)));
        @endphp
        <tr>
            <td style="white-space:nowrap;color:#6b7280">{{ $log->created_at->clone()->timezone('Asia/Jakarta')->format('d/m/Y H:i') }}</td>
            <td style="white-space:nowrap;font-weight:600">{{ $log->causer?->name ?? '—' }}</td>
            <td style="white-space:nowrap">{{ $modulLabels[$log->log_name] ?? ($log->log_name ?? '—') }}</td>
            <td style="white-space:nowrap">
                <span style="background:{{ $eventBadge[0] }};color:{{ $eventBadge[1] }};padding:2px 8px;border-radius:2px;font-size:0.78rem;font-weight:700">
                    {{ $eventLabels[$log->event] ?? $log->event ?? '—' }}
                </span>
            </td>
            <td>{{ $log->description }}</td>
            <td style="font-size:0.82rem;color:#6b7280">
                @if($diffKeys === [])
                    —
                @else
                    <div style="max-height:70px;overflow-y:auto">
                    @foreach($diffKeys as $k)
                        <div><b>{{ $k }}</b>: {{ $old[$k] ?? '—' }} &rarr; {{ $baru[$k] ?? '—' }}</div>
                    @endforeach
                    </div>
                @endif
            </td>
        </tr>
    @endforeach
    </tbody>
</table>
</div>
@endif

</div>{{-- /erp-scroll --}}

<div class="erp-pg">{{ $logs->links() }}</div>

</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>Period {{ \Carbon\Carbon::parse($dateFrom)->format('d M Y') }}
          &mdash; {{ \Carbon\Carbon::parse($dateTo)->format('d M Y') }}</span>
    <span><b>{{ number_format($logs->total()) }}</b> record aktivitas</span>
    @if($modul !== '' || $event !== '' || $userId !== '' || $search !== '')<span>Filter aktif</span>@endif
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection
