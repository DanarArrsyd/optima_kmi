@extends('layouts.app')
@section('title', 'Dashboard MTBF')
@section('content')
<div class="erp-panel" x-data="mtbfDash()" x-init="load()">
  <div class="erp-panel-header">Dashboard MTBF Mesin</div>
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
      Rata-rata MTBF: <strong x-text="summary.avg_mtbf ?? '—'"></strong> jam
    </div>
    <canvas id="mtbfChart" height="90"></canvas>
    <div class="table-responsive mt-3">
      <table class="erp-table" style="min-width:650px">
        <thead>
          <tr>
            <th>Kode Mesin</th>
            <th>Nama Mesin</th>
            <th style="text-align:right">Breakdown</th>
            <th style="text-align:right">MTBF (jam)</th>
            <th style="text-align:right">MTBF (hari)</th>
          </tr>
        </thead>
        <tbody>
          <template x-for="r in rows" :key="r.id_mesin">
            <tr>
              <td x-text="r.kode_mesin"></td>
              <td x-text="r.nama_mesin"></td>
              <td style="text-align:right" x-text="r.count"></td>
              <td style="text-align:right">
                <span x-show="!r.instalasi_set" class="text-muted fst-italic">instalasi belum diset</span>
                <span x-show="r.instalasi_set" x-text="r.mtbf_jam"></span>
              </td>
              <td style="text-align:right">
                <span x-show="!r.instalasi_set" class="text-muted fst-italic">—</span>
                <span x-show="r.instalasi_set" x-text="r.mtbf_hari"></span>
              </td>
            </tr>
          </template>
          <tr x-show="rows.length === 0">
            <td colspan="5" class="text-center text-muted py-3">Tidak ada data.</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</div>
<script>
function mtbfDash() {
  return {
    dateFrom: '{{ date('Y-01-01') }}',
    dateTo: '{{ date('Y-m-d') }}',
    mesinId: '0',
    rows: [],
    summary: {},
    chart: null,
    async load() {
      const url = new URL('{{ route('maintenance.mtbf.data') }}', window.location.origin);
      url.search = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo, mesin_id: this.mesinId });
      const res = await fetch(url);
      const json = await res.json();
      this.rows = json.rows;
      this.summary = json.summary;
      this.draw();
    },
    draw() {
      const ctx = document.getElementById('mtbfChart');
      if (this.chart) this.chart.destroy();
      this.chart = new Chart(ctx, {
        type: 'bar',
        data: {
          labels: this.rows.map(r => r.kode_mesin),
          datasets: [{
            label: 'MTBF (jam)',
            data: this.rows.map(r => r.instalasi_set ? r.mtbf_jam : null),
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
