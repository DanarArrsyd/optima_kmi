@extends('layouts.app')
@section('title', 'VCB Mesin')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-gear me-2"></i>VCB Mesin — Machine Achievement</h4>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-3">
                <label class="form-label small mb-1">Tanggal Dari</label>
                <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="{{ date('Y-m-01') }}">
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Tanggal Sampai</label>
                <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Bulan</label>
                <input type="month" id="bulan_filter" class="form-control form-control-sm">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Shift</label>
                <select id="shift_id" name="shift_id" class="form-select form-select-sm">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $shift)
                        <option value="{{ $shift->id }}">{{ $shift->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
            <div class="col-md-2">
                <a id="exportExcel" href="#" class="btn btn-success btn-sm w-100">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
                </a>
            </div>
        </form>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body"><canvas id="vcbChart" height="100"></canvas></div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>ID</th><th>Line</th><th>Tipe</th><th>Target QTY</th><th>Actual QTY</th><th>Duration (jam)</th><th>Achievement %</th><th>Avg SPH</th></tr>
                </thead>
                <tbody id="vcbTableBody">
                    <tr><td colspan="9" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let vcbChart = null;

async function loadData() {
    const params = new URLSearchParams({
        date_from: document.getElementById('date_from').value,
        date_to:   document.getElementById('date_to').value,
        shift_id:  document.getElementById('shift_id').value,
    });
    document.getElementById('exportExcel').href = `/export/produksi?${params}&group=mesin&format=excel`;

    const res  = await fetch(`{{ route('vcb.mesin.data') }}?${params}`);
    const data = await res.json();

    if (vcbChart) vcbChart.destroy();
    vcbChart = new Chart(document.getElementById('vcbChart'), {
        type: 'bar',
        data: { labels: data.labels, datasets: data.datasets },
        options: { responsive: true, plugins: { legend: { position: 'top' } }, scales: { y: { beginAtZero: true } } }
    });

    const tbody = document.getElementById('vcbTableBody');
    if (!data.table.length) {
        tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = data.table.map(row => {
        const badge = row.traffic_light === 'success' ? 'bg-success' : row.traffic_light === 'warning' ? 'bg-warning text-dark' : 'bg-danger';
        return `<tr class="table-${row.traffic_light}">
            <td>${row.mesin}</td><td>${row.id_mesin}</td><td>${row.line}</td><td>${row.tipe_mesin}</td>
            <td>${Number(row.total_target_qty).toLocaleString()}</td>
            <td>${Number(row.total_qty_aktual).toLocaleString()}</td>
            <td>${row.total_duration_hours}</td>
            <td><span class="badge ${badge}">${row.achievement_pct}%</span></td>
            <td>${row.avg_actual_sph}</td>
        </tr>`;
    }).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
document.getElementById('bulan_filter').addEventListener('change', function () {
    if (!this.value) return;
    const [y, m] = this.value.split('-').map(Number);
    const lastDay = new Date(y, m, 0).getDate();
    const today = new Date().toISOString().slice(0, 10);
    const to = `${this.value}-${String(lastDay).padStart(2, '0')}`;
    document.getElementById('date_from').value = `${this.value}-01`;
    document.getElementById('date_to').value   = to > today ? today : to;
    loadData();
});
loadData();
</script>
@endpush
