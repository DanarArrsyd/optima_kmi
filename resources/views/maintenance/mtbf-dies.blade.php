@extends('layouts.app')
@section('title', 'MTBF Dies')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════
     Isinya KPI + grafik + tabel yang memang perlu digulung, jadi
     .erp-scroll dibiarkan menggulung tegak — bukan dikunci satu layar. --}}
<div class="erp-ws" x-data="{ filterOpen: false }">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
    </button>
    @if(! auth()->user()->isGuest())
    {{-- href diisi pemuat data — file selalu sama dengan tabel, termasuk filternya. --}}
    <a id="exportExcel" href="{{ route('maintenance.mtbf-dies.export') }}" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <a href="{{ route('maintenance.mtbf-dies') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock>
    <x-filter.part-group customer-name="customer" part-name="part_id" :dengan-proses="false" />

    <x-filter.tanggal :from="date('Y-01-01')" :to="date('Y-m-d')" />
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Rata-rata MTBF</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiMtbf">—</span> <span class="small text-muted">jam</span></div>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Total Breakdown</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalBd">0</span></div>
        </div></div>
    </div>
</div>

<div class="erp-panel">
    {{-- Tinggi dikunci di pembungkus, BUKAN lewat atribut height canvas.
         Dengan maintainAspectRatio bawaan (true), Chart.js memakai rasio dari
         atribut canvas (lebar bawaan 300) saat lebar induk belum terukur, dan
         grafik terkunci kecil selamanya. --}}
    <div class="erp-panel-body" style="padding:12px">
        <div style="position:relative;height:320px">
            <canvas id="mtbfChart"></canvas>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Dies</div>
    <div class="erp-panel-body" style="padding:0">
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr><th>Part No</th><th>Nama Dies</th><th>Proses</th><th class="text-end">Breakdown</th><th class="text-end">MTBF (jam kerja)</th><th class="text-end">MTBF (hari kerja)</th></tr>
                </thead>
                <tbody id="mtbfTableBody">
                    <tr><td colspan="6" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>MTBF — Mean Time Between Failures (Dies)</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection

@push('scripts')
<script>
/* Cegah stored XSS: data master (nama mesin, part, proses) bisa memuat markup. */

/* null ≠ 0 dan ≠ 100. Sejak penyebut Availability jadi jam TERJADWAL, nilainya
   bisa null saat rentangnya nol jam kerja (Minggu/libur). Tanpa penjaga ini sel
   tabel mencetak literal "null%" — bukan crash, tapi terbaca seperti data rusak
   padahal maksudnya "tidak ada jam kerja untuk dinilai". */
function fmtPct(v) { return v === null || v === undefined ? '—' : v + '%'; }
function fmtNum(v) { return v === null || v === undefined ? '—' : v; }

function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}

let mtbfChart = null;

async function loadData() {
    const params = new URLSearchParams(new FormData(document.getElementById('filterForm')));
    const exportBtn = document.getElementById('exportExcel');
    if (exportBtn) exportBtn.href = `{{ route('maintenance.mtbf-dies.export') }}?${params}`;
    const res  = await fetch(`{{ route('maintenance.mtbf-dies.data') }}?${params}`);
    const data = await res.json();
    const rows = data.rows || [];

    document.getElementById('kpiMtbf').textContent    = data.summary?.avg_mtbf ?? '—';
    document.getElementById('kpiTotalBd').textContent = data.summary?.total_bd ?? rows.reduce((a,r)=>a+(r.count||0),0);

    if (mtbfChart) mtbfChart.destroy();
    mtbfChart = new Chart(document.getElementById('mtbfChart'), {
        type: 'bar',
        /* Batangnya gelap pekat — teks gelap tenggelam, jadi nama
           mesin digambar putih di dalamnya. */
        plugins: [pluginNamaDalamBatang({ warnaDalam: '#ffffff' })],
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'MTBF (jam)', data: rows.map(r => r.mtbf_jam), backgroundColor: '#1f2937' }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v } }, scales: { y: { beginAtZero: true }, x: { ticks: { display: false }, grid: { display: false } } } }
    });

    const tbody = document.getElementById('mtbfTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => {
        if (!r.instalasi_set) {
            return `<tr class="text-muted"><td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td><td>${escHtml(r.nama_proses)}</td><td class="text-end">${r.count}</td>
                <td class="text-end" colspan="2"><em>instalasi belum diset</em></td></tr>`;
        }
        return `<tr><td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td><td>${escHtml(r.nama_proses)}</td><td class="text-end">${r.count}</td>
            <td class="text-end"><strong>${fmtNum(r.mtbf_jam)}</strong></td><td class="text-end">${fmtNum(r.mtbf_hari)}</td></tr>`;
    }).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
