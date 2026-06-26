@extends('layouts.app')
@section('content')
@if(session('success'))<div class="alert alert-success">{{ session('success') }}</div>@endif
<div class="erp-panel">
  <div class="erp-panel-header d-flex justify-content-between align-items-center">
    <span>Daftar Breakdown Mesin</span>
    <a href="{{ route('maintenance.breakdown.create') }}" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg"></i> Input Breakdown</a>
  </div>
  <form method="GET" class="d-flex gap-2 p-2 flex-wrap">
    <input type="date" name="date_from" value="{{ $dateFrom }}" class="form-control form-control-sm" style="width:160px">
    <input type="date" name="date_to" value="{{ $dateTo }}" class="form-control form-control-sm" style="width:160px">
    <select name="mesin_id" class="form-select form-select-sm" style="width:200px">
      <option value="0">Semua Mesin</option>
      @foreach($mesinList as $m)<option value="{{ $m->id }}" @selected($mesinId==$m->id)>{{ $m->id_mesin }} - {{ $m->nama_mesin }}</option>@endforeach
    </select>
    <button class="btn btn-sm btn-secondary">Filter</button>
  </form>
  <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 260px);min-height:200px">
    <table class="erp-table tbl-freeze" style="min-width:1300px">
      <thead style="position:sticky;top:0;z-index:10">
        <tr>
          <th class="sc sc-1" style="width:90px">Tanggal</th>
          <th class="sc sc-2" style="width:150px">No. LKM</th>
          <th class="sc sc-3" style="width:70px">Mesin</th>
          <th class="sc sc-4" style="width:140px">Nama Mesin</th>
          <th class="sc sc-5 sc-sep" style="width:80px">Status</th>
          <th style="width:90px">Jam BD</th>
          <th style="width:80px;text-align:right">Repair (j)</th>
          <th style="width:90px;text-align:right">Downtime (j)</th>
          <th style="width:120px">PIC</th>
          <th>Problem</th>
          @if(auth()->user()->role==='admin')<th style="width:120px">Aksi</th>@endif
        </tr>
      </thead>
      <tbody>
      @forelse($breakdowns as $b)
        <tr>
          <td class="sc sc-1">{{ \Carbon\Carbon::parse($b->tanggal_breakdown)->format('d/m/Y') }}</td>
          <td class="sc sc-2" style="white-space:nowrap">{{ $b->no_lkm }}</td>
          <td class="sc sc-3">{{ $b->mesin?->id_mesin ?? '—' }}</td>
          <td class="sc sc-4" style="white-space:nowrap">{{ $b->mesin?->nama_mesin ?? '—' }}</td>
          <td class="sc sc-5 sc-sep">{{ $b->status_breakdown }}</td>
          <td>{{ \Illuminate\Support\Str::of($b->jam_breakdown)->substr(0,5) }}</td>
          <td style="text-align:right">{{ number_format($b->repair_time,1) }}</td>
          <td style="text-align:right">{{ number_format($b->total_downtime,1) }}</td>
          <td>{{ $b->pic_maintenance }}</td>
          <td style="font-size:0.82rem">{{ \Illuminate\Support\Str::limit($b->problem_mesin,60) }}</td>
          @if(auth()->user()->role==='admin')
          <td style="white-space:nowrap">
            <a href="{{ route('maintenance.breakdown.edit',$b) }}" class="btn btn-warning btn-sm">Edit</a>
            <form method="POST" action="{{ route('maintenance.breakdown.destroy',$b) }}" class="d-inline" onsubmit="return confirm('Hapus breakdown ini?')">@csrf @method('DELETE')<button class="btn btn-danger btn-sm">Hapus</button></form>
          </td>
          @endif
        </tr>
      @empty
        <tr><td colspan="11" style="text-align:center;color:#9ca3af">Belum ada data breakdown.</td></tr>
      @endforelse
      </tbody>
    </table>
  </div>
  <div class="p-2">{{ $breakdowns->links() }}</div>
</div>
@endsection
