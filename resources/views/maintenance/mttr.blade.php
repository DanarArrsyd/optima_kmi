@extends('layouts.app')
@section('title', 'MTTR Mesin')
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
    <a id="exportExcel" href="{{ route('maintenance.mttr.export') }}" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <a href="{{ route('maintenance.mttr') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
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
@include('partials.status-muat', ['id' => 'an'])
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Rata-rata MTTR</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiMttr">—</span> <span class="small text-muted">jam</span></div>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Total Breakdown</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalBd">0</span></div>
        </div></div>
    </div>
</div>

{{-- Kanan-kiri: tren per bulan | per mesin/dies untuk periode terpilih. --}}
<div class="row g-3 mb-3">
    <div class="col-lg-6">
        @include('maintenance.partials.tren-bulanan', ['judul' => 'Tren Bulanan — Rata-rata MTTR (jam)'])
    </div>
    <div class="col-lg-6">
        <div class="erp-panel h-100" style="margin:0">
            <div class="erp-panel-header d-flex align-items-center gap-2">
                <span>MTTR per Mesin — <span id="periodeKanan">Seluruh periode</span></span>
                <button type="button" id="periodeLepas" class="erp-btn ms-auto" style="display:none"><i class="bi bi-x-lg"></i> Seluruh periode</button>
            </div>
            {{-- Tinggi dikunci di pembungkus, BUKAN lewat atribut height canvas
                 (maintainAspectRatio + atribut height = grafik terkunci kecil). --}}
            <div class="erp-panel-body" style="padding:12px">
                <div style="position:relative;height:320px">
                    <canvas id="mttrChart"></canvas>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body" style="padding:0">
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>Nama Mesin</th><th class="text-end">Breakdown</th><th class="text-end">Total Repair (jam)</th><th class="text-end">MTTR (jam)</th></tr>
                </thead>
                <tbody id="mttrTableBody">
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
    <span>MTTR — Mean Time To Repair (Mesin)</span>
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

let mttrChart = null;

const tren = trenBulananMtc({
    url: `{{ route('maintenance.mttr.tren') }}`, metrik: 'mttr', label: 'MTTR (jam)',
    onPilih: () => muatDenganStatus('an', loadTabelInti),
});

function paramFilter() { return new URLSearchParams(new FormData(document.getElementById('filterForm'))); }

/* Pemuat dibungkus muatDenganStatus (layout): overlay, galat, Coba lagi. */
function loadData() { return muatDenganStatus('an', loadDataInti); }

/* Filter baru = kembali ke seluruh periode, lalu tren & tabel dimuat paralel. */
async function loadDataInti() {
    tren.reset();
    await Promise.all([tren.muat(paramFilter()), loadTabelInti()]);
}

/* Grafik kanan + KPI + tabel + link export: rentang filter, atau bulan yang diklik di tren. */
/* Klik bulan A lalu B cepat-cepat: respons A yang datang belakangan TIDAK boleh
   menimpa layar B. Hanya permintaan terakhir yang boleh menulis. */
let generasiTabel = 0;
async function loadTabelInti() {
    const generasi = ++generasiTabel;
    const params = paramFilter();
    const bulan = tren.bulanTerpilih();
    if (bulan) { params.set('date_from', bulan.from); params.set('date_to', bulan.to); params.delete('bulan'); }
    const exportBtn = document.getElementById('exportExcel');
    if (exportBtn) exportBtn.href = `{{ route('maintenance.mttr.export') }}?${params}`;
    const res  = await fetch(`{{ route('maintenance.mttr.data') }}?${params}`);
    if (!res.ok) throw new Error('HTTP ' + res.status);
    const data = await res.json();
    if (generasi !== generasiTabel) return;
    const rows = data.rows || [];

    document.getElementById('kpiMttr').textContent     = data.summary?.avg_mttr ?? '—';
    document.getElementById('kpiTotalBd').textContent  = data.summary?.total_bd ?? 0;

    if (mttrChart) mttrChart.destroy();
    mttrChart = new Chart(document.getElementById('mttrChart'), {
        type: 'bar',
        /* Batangnya gelap pekat — teks gelap tenggelam, jadi nama
           mesin digambar putih di dalamnya. */
        plugins: [pluginNamaDalamBatang({ warnaDalam: '#ffffff' })],
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'MTTR (jam)', data: rows.map(r => r.mttr_jam), backgroundColor: '#1f2937' }] },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v } }, scales: { y: { beginAtZero: true }, x: { ticks: { display: false }, grid: { display: false } } } }
    });

    const tbody = document.getElementById('mttrTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `<tr>
        <td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td>
        <td class="text-end">${r.count}</td>
        <td class="text-end">${Number(r.sum_repair).toLocaleString()}</td>
        <td class="text-end"><strong>${r.mttr_jam}</strong></td>
    </tr>`).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
