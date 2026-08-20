@extends('layouts.app')
@section('title', 'VCB Mesin')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
<div class="erp-ws">

<div class="erp-tb">
    <button type="button" id="btnDock" class="erp-tb-btn is-on"
            onclick="erpToggleDock('mesinDock','btnDock')">
        <i class="bi bi-funnel"></i>Filter
    </button>
    @if(! auth()->user()->isGuest())
    <a id="exportExcel" href="#" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" id="mesinDock">
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" onclick="erpToggleDock('mesinDock','btnDock')" title="Tutup filter">&times;</button>
    </div>
    <form id="filterForm" class="erp-dock-body">
        <div class="erp-grp">
            <b>Tanggal Dari</b>
            <input type="date" id="date_from" name="date_from" class="erp-in" value="{{ date('Y-m-01') }}">
        </div>
        <div class="erp-grp">
            <b>Tanggal Sampai</b>
            <input type="date" id="date_to" name="date_to" class="erp-in" value="{{ date('Y-m-d') }}">
        </div>
        <div class="erp-grp">
            <b>Bulan</b>
            <input type="month" id="bulan_filter" class="erp-in">
        </div>
        <div class="erp-grp">
            <b>Shift</b>
            <select id="shift_id" name="shift_id" class="erp-in">
                <option value="">Semua Shift</option>
                @foreach($shifts as $shift)
                    <option value="{{ $shift->id }}">{{ $shift->nama_shift }}</option>
                @endforeach
            </select>
        </div>
        <div class="erp-grp">
            <button type="submit" class="erp-btn is-pri"><i class="bi bi-search"></i>Tampilkan</button>
        </div>
    </form>
</aside>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">

<div class="erp-panel">
    <div class="erp-panel-body" style="padding:12px"><canvas id="vcbChart" height="100"></canvas></div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div style="overflow-x:auto">
        <table class="erp-table">
            <thead>
                <tr><th>Mesin</th><th>ID</th><th>Line</th><th>Tipe</th><th>Target QTY</th><th>Actual QTY</th><th>Duration (jam)</th><th>Achievement %</th><th>Avg SPH</th></tr>
            </thead>
            <tbody id="vcbTableBody">
                <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:20px">Klik Tampilkan untuk melihat data.</td></tr>
            </tbody>
        </table>
    </div>
</div>

</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>VCB Mesin &mdash; Machine Achievement</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection


@push('scripts')
<script>
/* Cegah stored XSS: data master (nama mesin, part, proses, karyawan) bisa memuat markup. */
function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}
let vcbChart = null;

async function loadData() {
    const params = new URLSearchParams({
        date_from: document.getElementById('date_from').value,
        date_to:   document.getElementById('date_to').value,
        shift_id:  document.getElementById('shift_id').value,
    });
    // Tombol export tidak dirender untuk role guest.
    const exportBtn = document.getElementById('exportExcel');
    if (exportBtn) exportBtn.href = `/export/produksi?${params}&group=mesin&format=excel`;

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
        return `<tr class="table-${escHtml(row.traffic_light)}">
            <td>${escHtml(row.mesin)}</td><td>${escHtml(row.id_mesin)}</td><td>${escHtml(row.line)}</td><td>${escHtml(row.tipe_mesin)}</td>
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
