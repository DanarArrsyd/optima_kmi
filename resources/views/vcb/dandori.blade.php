@extends('layouts.app')
@section('title', 'VCB Dandori')

@push('styles')
<style>
/* ── 3-column shell ───────────────────────────────────────────── */
.vcbd-wrap {
    display: flex;
    gap: 0;
    background: var(--bg);
    /* Berada di dalam .erp-ws-body yang sudah setinggi ruang kerja, jadi
       tidak perlu lagi ditarik ke tepi dengan margin negatif. */
    flex: 1 1 auto;
    min-height: 0;
}

/* ── Sidebar ──────────────────────────────────────────────────── */
.vcbd-sidebar {
    width: 210px;
    min-width: 210px;
    background: #fff;
    border-right: 1px solid #e0e4ea;
    display: flex;
    flex-direction: column;
    padding: 14px 10px;
    gap: 16px;
    overflow-y: auto;
    max-height: calc(100vh - 100px);
    position: sticky;
    top: 60px;
    align-self: flex-start;
}
.vcbd-sidebar-label {
    font-size: 0.67rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #6b7280;
    padding-bottom: 5px;
    border-bottom: 1px solid #e5e7eb;
    margin-bottom: 2px;
}
.vcbd-mesin-btn {
    display: block;
    width: 100%;
    text-align: left;
    padding: 5px 8px;
    font-size: 0.77rem;
    font-weight: 500;
    border: 1px solid #d1d5db;
    border-radius: 3px;
    background: #f9fafb;
    color: #374151;
    cursor: pointer;
    transition: background .1s, color .1s;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.3;
}
.vcbd-mesin-btn.active { background: #1f2937; color: #fff; border-color: #1f2937; }
.vcbd-mesin-btn:hover:not(.active) { background: #e5e7eb; }
.vcbd-ctrl-row { display: flex; gap: 4px; margin-bottom: 2px; }
.vcbd-ctrl-btn {
    flex: 1;
    padding: 4px 0;
    font-size: 0.71rem;
    font-weight: 600;
    border: 1px solid #d1d5db;
    border-radius: 3px;
    background: #fff;
    color: #374151;
    cursor: pointer;
    text-align: center;
}
.vcbd-ctrl-btn:hover { background: #f3f4f6; }

/* ── Main content ─────────────────────────────────────────────── */
.vcbd-main {
    flex: 1;
    min-width: 0;
    padding: 14px 14px;
    display: flex;
    flex-direction: column;
    gap: 12px;
    /* Kolom tengah HARUS menggulung sendiri. Sidebar kiri dan panel kanan
       sudah punya overflow-y sendiri; tanpa ini kolom tengah meluber keluar
       .erp-ws-body dan grafik paling bawah lewat DI BELAKANG .erp-status —
       terlihat seperti footer yang menimpa grafik, padahal grafiknya yang
       tidak pernah bisa digulung.

       min-height:0 wajib menemaninya: anak flex bawaannya min-height:auto,
       yang menolak menyusut di bawah tinggi kontennya, sehingga overflow-y
       tidak pernah aktif. */
    overflow-y: auto;
    min-height: 0;
}

/* ── Right KPI panel ──────────────────────────────────────────── */
.vcbd-right {
    width: 232px;
    min-width: 232px;
    background: #fff;
    border-left: 1px solid #e0e4ea;
    display: flex;
    flex-direction: column;
    padding: 14px 12px;
    gap: 16px;
    overflow-y: auto;
    max-height: calc(100vh - 100px);
    position: sticky;
    top: 60px;
    align-self: flex-start;
}
.vcbd-kpi-box {
    background: #1f2937;
    color: #fff;
    border-radius: 3px;
    padding: 14px 14px;
    text-align: center;
}
.vcbd-kpi-label {
    font-size: 0.66rem;
    font-weight: 700;
    letter-spacing: .1em;
    text-transform: uppercase;
    opacity: .7;
}
.vcbd-kpi-value {
    font-size: 2rem;
    font-weight: 800;
    line-height: 1.15;
    margin-top: 5px;
    letter-spacing: -.5px;
}
.vcbd-kpi-sub { font-size: 0.7rem; opacity: .55; margin-top: 2px; }

.vcbd-rank-section { display: flex; flex-direction: column; gap: 4px; }
.vcbd-rank-label {
    font-size: 0.67rem;
    font-weight: 700;
    letter-spacing: .08em;
    text-transform: uppercase;
    color: #6b7280;
    padding-bottom: 5px;
    border-bottom: 1px solid #e5e7eb;
}
.vcbd-rank-table { width: 100%; border-collapse: collapse; }
.vcbd-rank-table td { padding: 4px 2px; vertical-align: middle; font-size: 0.77rem; }
.vcbd-rank-table tr + tr td { border-top: 1px solid #f3f4f6; }
.vcbd-rank-name {
    font-weight: 600;
    color: #111827;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 128px;
    display: block;
    font-size: 0.76rem;
}
.vcbd-rank-bar-wrap { height: 5px; background: #e5e7eb; border-radius: 3px; margin-top: 3px; }
.vcbd-rank-bar      { height: 5px; border-radius: 3px; background: #c0392b; }
.vcbd-rank-val {
    text-align: right;
    font-weight: 700;
    color: #c0392b;
    padding-left: 6px;
    white-space: nowrap;
    font-size: 0.77rem;
}

@keyframes spin { to { transform: rotate(360deg); } }
.spin { display:inline-block; animation: spin .7s linear infinite; }
</style>
@endpush

{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')

@php $defaultFrom = date('Y-m-01'); $defaultTo = date('Y-m-d'); @endphp

{{-- Satu scope Alpine mencakup page-header + seluruh vcbd-wrap --}}
<div x-data="vcbDandori()">

<div class="erp-ws">

<div class="erp-tb">
    <button type="button" id="btnDock" class="erp-tb-btn"
            onclick="erpToggleDock('vcbdSidebar','btnDock')">
        <i class="bi bi-funnel"></i>Filter
    </button>
    <button type="button" class="erp-tb-btn" @click="loadData()" :disabled="loading">
        <i class="bi bi-arrow-clockwise"></i><span x-show="!loading">Refresh</span><span x-show="loading" x-cloak>Memuat&hellip;</span>
    </button>
    @if(! auth()->user()->isGuest())
    <a :href="exportUrl" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">
<div class="vcbd-wrap">

    {{-- ── LEFT SIDEBAR — filter tanggal + mesin ──────────────
         Mulai tertutup seperti dock di halaman lain; cakupan data yang
         sedang dilihat tetap terbaca di status bar. --}}
    <aside class="vcbd-sidebar" id="vcbdSidebar" style="display:none">
        <div class="erp-grp" style="margin-bottom:12px">
            <b>Filter by Date</b>
            <div class="erp-dock-row"><span>From</span>
                <input type="date" class="erp-in" x-model="dateFrom" :max="dateTo"></div>
            <div class="erp-dock-row"><span>To</span>
                <input type="date" class="erp-in" x-model="dateTo" :min="dateFrom"></div>
            <input type="month" class="erp-in" @change="setBulan($event.target.value)" title="Pilih satu bulan penuh">
            <button type="button" class="erp-btn is-pri" @click="loadData()">
                <i class="bi bi-funnel"></i>Tampilkan
            </button>
        </div>

        <div>
            <div class="vcbd-sidebar-label">Filter Mesin</div>
            <div class="vcbd-ctrl-row mt-2">
                <button class="vcbd-ctrl-btn" @click="selectAllMesin()">Semua</button>
                <button class="vcbd-ctrl-btn" @click="resetMesin()">Reset</button>
            </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:5px">
            @foreach($mesin as $m)
            <button class="vcbd-mesin-btn"
                    :class="{ active: selectedMesin.includes({{ $m->id }}) }"
                    @click="toggleMesin({{ $m->id }})">
                {{ $m->id_mesin }} – {{ $m->nama_mesin }}
            </button>
            @endforeach
        </div>
    </aside>

    {{-- ── MAIN CONTENT ───────────────────────────────────────── --}}
    <main class="vcbd-main">

        {{-- Chart 1: Loss Time per Jenis (vertical bars) --}}
        <div class="erp-panel">
            <div class="erp-panel-header">
                <i class="bi bi-bar-chart me-1"></i>
                Grafik Loss Time per Jenis &mdash; Over Standar (menit)
            </div>
            <div class="erp-panel-body" style="padding:16px">
                <div x-show="!loading && !hasData"
                     style="text-align:center;color:#9ca3af;padding:60px 0;font-size:0.85rem">
                    <i class="bi bi-bar-chart" style="font-size:3rem;display:block;margin-bottom:10px;opacity:.2"></i>
                    Belum ada data untuk periode ini.
                </div>
                {{-- Wrapper div with fixed height — Chart.js uses this when responsive:true maintainAspectRatio:false --}}
                <div x-show="hasData && !loading" style="position:relative;height:340px">
                    <canvas id="chartMain"></canvas>
                </div>
            </div>
        </div>

        {{-- Charts 2 & 3: per Minggu + per Bulan --}}
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div class="erp-panel">
                <div class="erp-panel-header">
                    <i class="bi bi-calendar-week me-1"></i>Grafik Loss Time / Minggu &mdash; Over Standar (menit)
                </div>
                <div class="erp-panel-body" style="padding:16px">
                    <div x-show="!hasData && !loading"
                         style="text-align:center;color:#9ca3af;padding:40px 0;font-size:0.82rem">
                        Tidak ada data
                    </div>
                    <div x-show="hasData" style="position:relative;height:240px">
                        <canvas id="chartWeek"></canvas>
                    </div>
                </div>
            </div>
            <div class="erp-panel">
                <div class="erp-panel-header">
                    <i class="bi bi-calendar-month me-1"></i>Grafik Loss Time / Bulan &mdash; Over Standar (menit)
                </div>
                <div class="erp-panel-body" style="padding:16px">
                    <div x-show="!hasData && !loading"
                         style="text-align:center;color:#9ca3af;padding:40px 0;font-size:0.82rem">
                        Tidak ada data
                    </div>
                    <div x-show="hasData" style="position:relative;height:240px">
                        <canvas id="chartMonth"></canvas>
                    </div>
                </div>
            </div>
        </div>

        {{-- Detail table --}}
        <div class="erp-panel">
            <div class="erp-panel-header">
                <i class="bi bi-list-ol me-1"></i>Ranking Over Standar per Jenis
            </div>
            <div style="overflow-x:auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th style="width:36px;text-align:center">No</th>
                            <th style="width:80px">Kode</th>
                            <th>Jenis Loss Time</th>
                            <th style="width:140px;text-align:right">Over Standar (mnt)</th>
                            <th style="min-width:160px">Proporsi</th>
                        </tr>
                    </thead>
                    <tbody id="rankTableBody">
                        <tr>
                            <td colspan="5" style="text-align:center;color:#888;padding:20px">
                                Klik <strong>Tampilkan</strong> untuk melihat data.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

    </main>

    {{-- ── RIGHT PANEL — KPI + ranking ────────────────────────── --}}
    <aside class="vcbd-right">

        {{-- SATU angka: over standar. Kotak "Total Aktual" sempat ada di sini
             untuk merekonsiliasi dengan Overview yang waktu itu memakai
             actual_menit. Sejak Overview ikut memakai over_menit, kedua halaman
             sudah menampilkan angka yang sama dan kotak itu jadi mubazir.

             total_actual masih ada di payload getVcbDandori() dan sengaja tidak
             dibuang dari service — Availability/OEE tetap memakai actual_menit,
             jadi angkanya masih dipakai di tempat lain. Yang dihapus di sini
             cuma tampilannya. --}}
        <div class="vcbd-kpi-box">
            <div class="vcbd-kpi-label">Total Over Standar</div>
            <div class="vcbd-kpi-value" x-text="fmt(summary.total_over)">—</div>
            <div class="vcbd-kpi-sub">menit &mdash; dasar semua grafik di halaman ini</div>
        </div>

        <div class="vcbd-rank-section">
            <div class="vcbd-rank-label">Jenis Loss Time <span style="font-weight:400;color:#9ca3af">(over standar)</span></div>
            <div x-show="!summary.by_jenis || summary.by_jenis.length === 0"
                 style="color:#9ca3af;font-size:0.78rem;padding:4px 0">Tidak ada data</div>
            <table class="vcbd-rank-table" x-show="summary.by_jenis && summary.by_jenis.length > 0">
                <tbody>
                    <template x-for="item in summary.by_jenis" :key="item.kode">
                        <tr>
                            <td>
                                <span class="vcbd-rank-name" :title="item.jenis" x-text="item.jenis"></span>
                                <div class="vcbd-rank-bar-wrap">
                                    <div class="vcbd-rank-bar"
                                         :style="`width:${maxJenis > 0 ? item.over/maxJenis*100 : 0}%`"></div>
                                </div>
                            </td>
                            <td class="vcbd-rank-val" x-text="fmt(item.over)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <div class="vcbd-rank-section">
            <div class="vcbd-rank-label">Nama Mesin</div>
            <div x-show="!summary.by_mesin || summary.by_mesin.length === 0"
                 style="color:#9ca3af;font-size:0.78rem;padding:4px 0">Tidak ada data</div>
            <table class="vcbd-rank-table" x-show="summary.by_mesin && summary.by_mesin.length > 0">
                <tbody>
                    <template x-for="item in summary.by_mesin" :key="item.id_mesin">
                        <tr>
                            <td>
                                <span class="vcbd-rank-name"
                                      :title="item.id_mesin + ' – ' + item.nama_mesin"
                                      x-text="item.id_mesin + ' – ' + item.nama_mesin"></span>
                                <div class="vcbd-rank-bar-wrap">
                                    <div class="vcbd-rank-bar"
                                         :style="`width:${maxMesin > 0 ? item.over/maxMesin*100 : 0}%`"></div>
                                </div>
                            </td>
                            <td class="vcbd-rank-val" x-text="fmt(item.over)"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

    </aside>

</div>{{-- /vcbd-wrap --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>VCB Dandori &mdash; Loss Time Analysis</span>
    <span x-show="loading" x-cloak>Memuat&hellip;</span>
    <span x-show="!loading" x-text="'Total over standar: ' + fmt(summary.total_over) + ' mnt'"></span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}

</div>{{-- /vcbDandori scope --}}
@endsection

@push('scripts')
<script>
/* Cegah stored XSS: data master (nama mesin, part, proses, karyawan) bisa memuat markup. */
function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}
let chartMain = null, chartWeek = null, chartMonth = null;

/* Plugin inline: gambar nilai di atas tiap bar. Sebelumnya pakai plugin CDN
   (chartjs-plugin-datalabels) yang rawan keblok CSP/gagal load di produksi,
   jadi labelnya tak pernah muncul. Versi inline ini nol dependency. */
const barValueLabels = {
    id: 'barValueLabels',
    afterDatasetsDraw(chart) {
        const ctx = chart.ctx;
        chart.data.datasets.forEach((ds, di) => {
            const meta = chart.getDatasetMeta(di);
            if (meta.hidden) return;
            meta.data.forEach((bar, i) => {
                const v = Number(ds.data[i]);
                if (!v || v <= 0) return;
                ctx.save();
                ctx.font = '700 10px Arial, sans-serif';
                ctx.fillStyle = '#1a3a4a';
                ctx.textAlign = 'center';
                ctx.textBaseline = 'bottom';
                ctx.fillText(
                    v.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 }),
                    bar.x, bar.y - 3
                );
                ctx.restore();
            });
        });
    },
};

/* namaDalamBatang HANYA untuk grafik yang label-nya nama jenis loss time.
   Grafik per-minggu dan per-bulan label-nya tanggal — memutarnya ke dalam
   batang membuatnya tidak terbaca sekaligus menghapus sumbu waktunya. */
function buildBar(ctx, labels, values, namaDalamBatang = false) {
    return new Chart(ctx, {
        type: 'bar',
        plugins: namaDalamBatang
            ? [barValueLabels, pluginNamaDalamBatang({ warnaDalam: '#ffffff' })]
            : [barValueLabels],
        data: {
            labels,
            datasets: [{
                label: 'Loss Time (mnt)',
                data: values,
                backgroundColor: '#1a6b7c',
                borderColor: '#1a6b7c',
                borderRadius: 3,
                borderSkipped: false,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 22 } },   /* ruang untuk label di atas bar */
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => ' ' + c.parsed.y
                                        .toLocaleString('id-ID', { minimumFractionDigits: 1 }) + ' mnt',
                    },
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    /* Saat nama digambar di dalam batang, tick sumbu WAJIB mati
                       — kalau tidak namanya tergambar dua kali. Tick miring yang
                       dulu dipakai memakan tinggi plot, memaksa nama panjang
                       dipotong jadi '…', dan tetap sulit dibaca. */
                    ticks: namaDalamBatang ? { display: false } : {
                        font: { size: 10 },
                        maxRotation: 30,
                        minRotation: 0,
                        callback: function(val) {
                            const lbl = this.getLabelForValue(val);
                            return lbl.length > 18 ? lbl.substring(0, 17) + '…' : lbl;
                        },
                    },
                    grid: { display: false },
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 10 },
                        callback: v => v >= 1000 ? (v / 1000).toFixed(1) + 'k' : v,
                    },
                    grid: { color: '#f3f4f6' },
                },
            },
        },
    });
}

function vcbDandori() {
    return {
        dateFrom: '{{ $defaultFrom }}',
        dateTo:   '{{ $defaultTo }}',
        selectedMesin: [],
        loading: false,
        hasData: false,

        summary: {
            total_over: 0,
            by_jenis: [], by_mesin: [], by_week: [], by_month: [],
        },

        get maxJenis() {
            return this.summary.by_jenis.length
                ? Math.max(...this.summary.by_jenis.map(j => j.over))
                : 0;
        },
        get maxMesin() {
            return this.summary.by_mesin.length
                ? Math.max(...this.summary.by_mesin.map(m => m.over))
                : 0;
        },
        get exportUrl() {
            const p = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo });
            this.selectedMesin.forEach(id => p.append('mesin_ids[]', id));
            return `{{ url('vcb/dandori/export') }}?${p}`;
        },

        fmt(v) {
            if (v == null) return '—';
            return parseFloat(v).toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 });
        },

        toggleMesin(id) {
            const i = this.selectedMesin.indexOf(id);
            i >= 0 ? this.selectedMesin.splice(i, 1) : this.selectedMesin.push(id);
        },
        selectAllMesin() {
            @php $allIds = $mesin->pluck('id')->toArray(); @endphp
            this.selectedMesin = @json($allIds);
        },
        resetMesin() { this.selectedMesin = []; },

        setBulan(v) {
            if (!v) return;
            const [y, m] = v.split('-').map(Number);
            const lastDay = new Date(y, m, 0).getDate();
            const today = new Date().toISOString().slice(0, 10);
            const to = `${v}-${String(lastDay).padStart(2, '0')}`;
            this.dateFrom = `${v}-01`;
            this.dateTo   = to > today ? today : to;
            this.loadData();
        },

        async loadData() {
            this.loading = true;
            const p = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo });
            this.selectedMesin.forEach(id => p.append('mesin_ids[]', id));

            try {
                const res  = await fetch(`{{ route('vcb.dandori.data') }}?${p}`);
                const data = await res.json();

                /* Alias total_over → total_loss_time DIHAPUS. Alias itulah
                   sumber kebingungannya: ia menamai over-menit sebagai "loss
                   time", padahal loss time yang utuh adalah actual_menit —
                   dan itu yang dipakai halaman Overview serta dikurangkan dari
                   Availability. Sekarang kedua angka dipakai dengan nama
                   aslinya, jadi labelnya tidak bisa lagi berbohong. */
                this.summary = data;
                this.hasData = data.by_jenis.length > 0;

                this.$nextTick(() => this.drawCharts(data));
                this.renderTable(data);
            } catch (e) {
                console.error('VCB Dandori:', e);
            } finally {
                this.loading = false;
            }
        },

        drawCharts(data) {
            const make = (ref, elId, labels, values, namaDalamBatang = false) => {
                if (ref) ref.destroy();
                const el = document.getElementById(elId);
                if (!el || !labels.length) return null;
                return buildBar(el, labels, values, namaDalamBatang);
            };

            /* Main: vertical bars, label = jenis loss time name */
            chartMain = make(
                chartMain, 'chartMain',
                data.by_jenis.map(j => j.jenis),
                data.by_jenis.map(j => j.over),
                true
            );
            /* Per-week: vertical bars */
            chartWeek = make(
                chartWeek, 'chartWeek',
                data.by_week.map(w => w.label),
                data.by_week.map(w => w.over)
            );
            /* Per-month: vertical bars */
            chartMonth = make(
                chartMonth, 'chartMonth',
                data.by_month.map(m => m.label),
                data.by_month.map(m => m.over)
            );
        },

        renderTable(data) {
            const max   = data.by_jenis.reduce((s, j) => s + j.over, 0) || 1;
            const tbody = document.getElementById('rankTableBody');
            if (!data.by_jenis.length) {
                tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;color:#888;padding:20px">Tidak ada data.</td></tr>`;
                return;
            }
            tbody.innerHTML = data.by_jenis.map((item, i) => {
                const pct = (item.over / max * 100).toFixed(1);
                return `<tr>
                    <td style="text-align:center;color:#6b7280">${i + 1}</td>
                    <td style="font-weight:700;letter-spacing:.3px">${escHtml(item.kode)}</td>
                    <td>${escHtml(item.jenis)}</td>
                    <td style="text-align:right;font-weight:700;color:#1a3a4a">${this.fmt(item.over)}</td>
                    <td style="padding:6px 10px">
                        <div style="display:flex;align-items:center;gap:8px">
                            <div style="flex:1;height:8px;background:#e5e7eb;border-radius:4px">
                                <div style="width:${pct}%;height:8px;background:#1a5c6e;border-radius:4px"></div>
                            </div>
                            <span style="font-size:0.77rem;color:#6b7280;min-width:36px;text-align:right">${pct}%</span>
                        </div>
                    </td>
                </tr>`;
            }).join('');
        },

        init() { this.loadData(); },
    };
}
</script>
@endpush
