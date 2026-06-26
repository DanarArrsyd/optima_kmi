@extends('layouts.app')
@section('title', 'Dashboard MTTR')
@section('content')
<div class="erp-panel" x-data="mttrDash()" x-init="load()">
  <div class="erp-panel-header">Dashboard MTTR Mesin</div>
  <div class="d-flex gap-2 p-2 flex-wrap">
    <input type="date" x-model="dateFrom" class="form-control form-control-sm" style="width:160px">
    <input type="date" x-model="dateTo" class="form-control form-control-sm" style="width:160px">
    <select x-model="mesinId" class="form-select form-select-sm" style="width:220px">
      <option value="0">Semua Mesin</option>
      @foreach($mesinList as $m)
        <option value="{{ $m->id }}">{{ $m->id_mesin }} - {{ $m->nama_mesin }}</option>
      @endforeach
    </select>
    <button class="btn btn-sm btn-secondary" @click="load()">Terapkan</button>
  </div>
  <div class="erp-panel-body">
    <div class="mb-3">
      Rata-rata MTTR: <strong x-text="summary.avg_mttr ?? '—'"></strong> jam
      &nbsp;·&nbsp;
      Total breakdown: <strong x-text="summary.total_bd ?? 0"></strong>
    </div>
    <canvas id="mttrChart" height="90"></canvas>
    <div class="table-responsive mt-3">
      <table class="erp-table" style="min-width:600px">
        <thead>
          <tr>
            <th>Kode Mesin</th>
            <th>Nama Mesin</th>
            <th style="text-align:right">Breakdown</th>
            <th style="text-align:right">MTTR (jam)</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="r in rows" :key="r.id_mesin">
            <tr>
              <td x-text="r.kode_mesin"></td>
              <td x-text="r.nama_mesin"></td>
              <td style="text-align:right" x-text="r.count"></td>
              <td style="text-align:right" x-text="r.mttr_jam"></td>
            </tr>
          </template>
          <tr x-show="rows.length === 0">
            <td colspan="4" class="text-center text-muted py-3">Tidak ada data.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
function mttrDash() {
  return {
    dateFrom: '{{ date('Y-01-01') }}',
    dateTo: '{{ date('Y-m-d') }}',
    mesinId: '0',
    rows: [],
    summary: {},
    chart: null,
    async load() {
      const url = new URL('{{ route('maintenance.mttr.data') }}', window.location.origin);
      url.search = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo, mesin_id: this.mesinId });
      const res = await fetch(url);
      const json = await res.json();
      this.rows = json.rows;
      this.summary = json.summary;
      this.draw();
    },
    draw() {
      const ctx = document.getElementById('mttrChart');
      if (this.chart) this.chart.destroy();
      this.chart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: this.rows.map(r => r.kode_mesin),
          datasets: [{
            label: 'MTTR (jam)',
            data: this.rows.map(r => r.mttr_jam),
            backgroundColor: '#1f2937'
          }]
        },
        options: {
          responsive: true,
          plugins: { legend: { display: false } },
          scales: { y: { beginAtZero: true } }
        }
      });
    }
  };
}
</script>
@endsection
