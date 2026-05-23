@extends('layouts.app')
@section('title', 'VCB Dandori')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-clock-history me-2"></i>VCB Dandori — Loss Time Analysis</h4>
</div>

<div class="card shadow-sm mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Bulan</label>
                <select id="bulan" name="bulan" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($months as $m)
                        <option value="{{ $m }}" {{ $m === date('M') ? 'selected' : '' }}>{{ $m }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Week</label>
                <select id="week" name="week" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($weeks as $w)
                        <option value="{{ $w }}">{{ $w }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label small mb-1">Mesin</label>
                <select id="mesin_id" name="mesin_id" class="form-select form-select-sm">
                    <option value="">Semua Mesin</option>
                    @foreach($mesin as $m)
                        <option value="{{ $m->id }}">{{ $m->nama_mesin }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Shift</label>
                <select id="shift_id" name="shift_id" class="form-select form-select-sm">
                    <option value="">Semua</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-1">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search"></i></button>
            </div>
            <div class="col-md-2">
                <a id="exportExcel" href="#" class="btn btn-success btn-sm w-100">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export
                </a>
            </div>
        </form>
    </div>
</div>

<div class="row mb-4">
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Total Loss per Mesin (menit)</div>
            <div class="card-body"><canvas id="chartLoss" height="160"></canvas></div>
        </div>
    </div>
    <div class="col-md-6">
        <div class="card shadow-sm">
            <div class="card-header fw-semibold">Total Over-Standard per Mesin (menit)</div>
            <div class="card-body"><canvas id="chartOver" height="160"></canvas></div>
        </div>
    </div>
</div>

<div class="card shadow-sm">
    <div class="card-header fw-semibold">Summary per Mesin</div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-sm table-bordered mb-0">
                <thead class="table-dark" id="tableHead">
                    <tr><th>Mesin</th><th>Total Loss</th><th>Total Over</th></tr>
                </thead>
                <tbody id="tableBody">
                    <tr><td colspan="3" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
let chartLoss = null, chartOver = null;

async function loadData() {
    const params = new URLSearchParams({
        bulan:    document.getElementById('bulan').value,
        week:     document.getElementById('week').value,
        mesin_id: document.getElementById('mesin_id').value,
        shift_id: document.getElementById('shift_id').value,
    });
    document.getElementById('exportExcel').href = `/export/dandori?${params}&format=excel`;

    const res  = await fetch(`/vcb/dandori/data?${params}`);
    const data = await res.json();

    const opts = {
        responsive: true,
        plugins: { legend: { position: 'right' } },
        scales: { x: { stacked: true }, y: { stacked: true, beginAtZero: true, title: { display: true, text: 'Menit' } } }
    };

    if (chartLoss) chartLoss.destroy();
    chartLoss = new Chart(document.getElementById('chartLoss'), { type: 'bar', data: { labels: data.labels, datasets: data.datasets_loss }, options: { ...opts } });

    if (chartOver) chartOver.destroy();
    chartOver = new Chart(document.getElementById('chartOver'), { type: 'bar', data: { labels: data.labels, datasets: data.datasets_over }, options: { ...opts } });

    const jenisList = data.jenis_list;
    document.getElementById('tableHead').innerHTML = `<tr>
        <th>Mesin</th>
        ${jenisList.map(j => `<th>${j} (Loss)</th><th>${j} (Over)</th>`).join('')}
        <th>Total Loss</th><th>Total Over</th>
    </tr>`;

    const tbody = document.getElementById('tableBody');
    if (!data.table.length) {
        tbody.innerHTML = `<tr><td colspan="${2 + jenisList.length * 2 + 2}" class="text-center text-muted py-3">Tidak ada data.</td></tr>`;
        return;
    }
    tbody.innerHTML = data.table.map(row => `
        <tr>
            <td><strong>${row.mesin}</strong></td>
            ${jenisList.map(j => `<td>${row[j + '_loss'] ?? 0}</td><td>${row[j + '_over'] ?? 0}</td>`).join('')}
            <td><strong>${row.total_loss}</strong></td>
            <td><strong>${row.total_over}</strong></td>
        </tr>
    `).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
