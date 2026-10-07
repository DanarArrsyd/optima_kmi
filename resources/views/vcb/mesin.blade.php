@extends('layouts.app')
@section('title', 'VCB Mesin')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
<div class="erp-ws" x-data="{ filterOpen: false }">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
    </button>
    @if(! auth()->user()->isGuest())
    <a id="exportExcel" href="#" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- Dock mulai tertutup; cakupan data yang sedang dilihat tetap
     terbaca di status bar, jadi tidak ada yang tersembunyi diam-diam. --}}
<x-filter.dock>
    <x-filter.grup judul="Find">
        <x-filter.cari name="shift_id" id="shift_id" placeholder="Shift"
                       :items="$shifts->map(fn ($s) => ['v' => $s->id, 't' => $s->nama_shift])" />
        <x-filter.mesin />
    </x-filter.grup>

    <x-filter.tanggal :from="date('Y-m-01')" :to="date('Y-m-d')" />
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">

<div style="position:relative">
    <div class="erp-loading-overlay" id="mesinLoadingOverlay">
        <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
    </div>
    <div class="text-danger mb-3" id="mesinLoadError" style="display:none">
        Gagal memuat data — coba lagi.
    </div>

    <div class="erp-panel mb-3">
        <div class="erp-panel-header">Achievement Mesin &mdash; Actual vs Target QTY</div>
        <div class="erp-panel-body">
            {{-- Tinggi dikunci di pembungkus, sama seperti grafik VCB lain
                 (lihat feedback proxy Chart.js/Alpine). Nama mesin ditempel
                 di dalam batang Actual (dataset 0) — garis Target (dataset 1)
                 nol pengaruh ke plugin ini, cuma baca dataset pertama. --}}
            <div style="position:relative;height:340px">
                <canvas id="vcbChart"></canvas>
            </div>
            <div style="font-size:11px;color:var(--text-3);border-top:1px solid var(--border);margin-top:10px;padding-top:8px">
                Batang = Actual QTY (biru = lebihi target, hijau = capai target, merah = di bawah target). Garis abu-abu = Target QTY.
            </div>
        </div>
    </div>

    <div class="erp-panel">
        <div class="erp-panel-header">Detail per Mesin</div>
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>ID</th><th>Line</th><th>Tipe</th><th>Target QTY</th><th>Actual QTY</th><th>Duration (jam)</th><th>Achievement %</th><th>Avg SPH</th></tr>
                </thead>
                <tbody id="vcbTableBody">
                    <tr><td colspan="9" style="text-align:center;color:var(--text-4);padding:20px">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
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
    const overlay = document.getElementById('mesinLoadingOverlay');
    const errBox  = document.getElementById('mesinLoadError');
    const submitBtn = document.querySelector('#filterForm button[type="submit"]');
    overlay.style.display = 'flex';
    errBox.style.display = 'none';
    submitBtn.disabled = true;
    try {
        const params = new URLSearchParams(new FormData(document.getElementById('filterForm')));
        // Tombol export tidak dirender untuk role guest.
        const exportBtn = document.getElementById('exportExcel');
        if (exportBtn) exportBtn.href = `{{ route('vcb.mesin.export') }}?${params}`;

        const res  = await fetch(`{{ route('vcb.mesin.data') }}?${params}`);
        const data = await res.json();

        if (vcbChart) vcbChart.destroy();
        vcbChart = new Chart(document.getElementById('vcbChart'), {
            type: 'bar',
            data: { labels: data.labels, datasets: data.datasets },
            /* Nama mesin ditempel TEGAK di dalam batang Actual (dataset 0) —
             * pola sama VCB Produksi, bukan tick miring di bawah sumbu-X.
             * Batang di sini dicat solid gelap (biru/hijau/merah) jadi teks
             * dalamnya HARUS putih, sama seperti Availability Mesin. */
            plugins: [pluginNamaDalamBatang({ warnaDalam: '#ffffff' })],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 24 } },
                scales: {
                    x: {
                        /* Nama sudah tergambar di dalam batang — tick di bawah
                         * bikin namanya dobel. */
                        ticks: { display: false },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#e5e7eb' },
                        ticks: { color: '#6b7280', font: { size: 11 } },
                    },
                },
                plugins: {
                    /* Legend dibuat manual — dataset batang mewarnai tiap batang
                     * beda-beda (achievement per mesin), bukan satu warna per
                     * dataset, jadi swatch bawaan Chart.js bakal salah/aneh. */
                    legend: {
                        position: 'top',
                        labels: {
                            generateLabels: () => [
                                { text: 'Actual QTY', fillStyle: '#6b7280', strokeStyle: '#6b7280' },
                                { text: 'Target QTY', fillStyle: '#374151', strokeStyle: '#374151' },
                            ],
                        },
                    },
                    datalabels: {
                        display: c => c.datasetIndex === 0,
                        anchor: 'end',
                        align: 'end',
                        offset: 2,
                        color: '#374151',
                        font: { size: 10, weight: 700 },
                        formatter: (v, c) => (data.achievements[c.dataIndex] ?? 0) + '%',
                    },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: c => {
                                const i = c.dataIndex;
                                if (c.datasetIndex === 0) {
                                    return `Actual: ${Number(c.raw).toLocaleString('id-ID')} (${data.achievements[i]}%)`;
                                }

                                return `Target: ${Number(c.raw).toLocaleString('id-ID')}`;
                            },
                        },
                    },
                },
            },
        });

        const tbody = document.getElementById('vcbTableBody');
        if (!data.table.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
            return;
        }
        tbody.innerHTML = data.table.map(row => {
            // Warna dari angkanya, BUKAN dari traffic_light: cabang 'success' saja tak pernah
            // memuat mesin yang melampaui target ('info'), jadi yang di atas target dulu
            // jatuh ke cabang terakhir dan diberi warna merah.
            const badge = row.achievement_pct > 100 ? 'bg-primary'
                        : row.achievement_pct >= 100 ? 'bg-success'
                        : 'bg-danger';
            return `<tr class="table-${escHtml(row.traffic_light)}">
                <td>${escHtml(row.mesin)}</td><td>${escHtml(row.id_mesin)}</td><td>${escHtml(row.line)}</td><td>${escHtml(row.tipe_mesin)}</td>
                <td>${Number(row.total_target_qty).toLocaleString()}</td>
                <td>${Number(row.total_qty_aktual).toLocaleString()}</td>
                <td>${row.total_duration_hours}</td>
                <td><span class="badge ${badge}">${row.achievement_pct}%</span></td>
                <td>${row.avg_actual_sph}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error('loadData error:', e);
        errBox.style.display = '';
    } finally {
        overlay.style.display = 'none';
        submitBtn.disabled = false;
    }
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
