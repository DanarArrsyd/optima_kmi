@extends('layouts.app')
@section('title', 'Availability Mesin')
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
    <a href="{{ route('maintenance.availability') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<x-filter.dock>
    <x-filter.grup judul="Find">
        <x-filter.cari name="tipe_mesin" id="tipe_mesin" placeholder="Tipe Mesin"
                       :items="collect($tipeList)->map(fn ($t) => ['v' => $t, 't' => $t])" />
        <x-filter.mesin />
    </x-filter.grup>

    <x-filter.tanggal :from="date('Y-01-01')" :to="date('Y-m-d')" />
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Rata-rata Availability</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiAvail">—</span> <span class="small text-muted">%</span></div>
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
            <canvas id="availChart"></canvas>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body" style="padding:0">
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>Nama Mesin</th><th class="text-end">Breakdown</th><th class="text-end">Total Downtime (jam)</th><th class="text-end">Availability %</th></tr>
                </thead>
                <tbody id="availTableBody">
                    <tr><td colspan="5" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>Availability (Mesin)</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection

@push('scripts')
<script>
/* Cegah stored XSS: data master (nama mesin, part, proses) bisa memuat markup. */
function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}

let availChart = null;

/* null ≠ 0 dan ≠ 100. Sejak penyebut Availability jadi jam TERJADWAL, nilainya
   bisa null saat rentangnya nol jam kerja (Minggu/libur). Tanpa penjaga ini sel
   tabel mencetak literal "null%" — bukan crash, tapi terbaca seperti data rusak
   padahal maksudnya "tidak ada jam kerja untuk dinilai". */
function fmtPct(v) { return v === null || v === undefined ? '—' : v + '%'; }
function fmtNum(v) { return v === null || v === undefined ? '—' : v; }

function availColor(pct) {
    if (pct === null) return '#9ca3af';
    if (pct >= 95) return '#15803d';
    if (pct >= 85) return '#b45309';
    return '#b91c1c';
}

async function loadData() {
    const params = new URLSearchParams(new FormData(document.getElementById('filterForm')));
    const res  = await fetch(`{{ route('maintenance.availability.data') }}?${params}`);
    const data = await res.json();
    const rows = data.rows || [];

    document.getElementById('kpiAvail').textContent   = data.summary?.avg_availability ?? '—';
    document.getElementById('kpiTotalBd').textContent = data.summary?.total_bd ?? rows.reduce((a,r)=>a+(r.count||0),0);

    if (availChart) availChart.destroy();
    availChart = new Chart(document.getElementById('availChart'), {
        type: 'bar',
        /* Batangnya gelap pekat — teks gelap tenggelam, jadi nama
           mesin digambar putih di dalamnya. */
        plugins: [pluginNamaDalamBatang({ warnaDalam: '#ffffff' })],
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'Availability %', data: rows.map(r => r.availability_pct), backgroundColor: rows.map(r => availColor(r.availability_pct)) }] },
        options: { responsive: true, maintainAspectRatio: false, layout: { padding: { top: 20 } }, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v + '%' } }, scales: { y: { beginAtZero: true, max: 100 }, x: { ticks: { display: false }, grid: { display: false } } } }
    });

    const tbody = document.getElementById('availTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => {
        if (!r.instalasi_set) {
            return `<tr class="text-muted"><td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td><td class="text-end">${r.count}</td>
                <td class="text-end">${Number(r.sum_downtime).toLocaleString()}</td><td class="text-end"><em>instalasi belum diset</em></td></tr>`;
        }
        return `<tr><td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td><td class="text-end">${r.count}</td>
            <td class="text-end">${Number(r.sum_downtime).toLocaleString()}</td>
            <td class="text-end"><strong>${fmtPct(r.availability_pct)}</strong></td></tr>`;
    }).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
