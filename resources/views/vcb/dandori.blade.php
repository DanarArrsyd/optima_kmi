@extends('layouts.app')
@section('title', 'VCB Dandori')

@push('styles')
<style>
/* ── 3-column shell ───────────────────────────────────────────── */
.vcbd-wrap {
    display: flex;
    gap: 0;
    background: #f4f6f8;
    /* stretch below page-header */
    margin: -8px -16px -16px;   /* pull to content edges */
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

@section('content')

{{-- ── PAGE HEADER (same pattern as VCB Produksi / Quality) ──── --}}
<div class="page-header">
    <h1 class="page-title">
        <i class="bi bi-clock-history"></i>VCB Dandori — Loss Time Analysis
    </h1>
    <a :href="exportUrl" class="btn btn-success btn-sm" style="text-decoration:none" x-data>
        <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
    </a>
</div>

@php $defaultFrom = date('Y-m-01'); $defaultTo = date('Y-m-d'); @endphp

<div class="vcbd-wrap" x-data="vcbDandori()">

    {{-- ── LEFT SIDEBAR — Mesin filter ──────────────────────── --}}
    <aside class="vcbd-sidebar">
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

        {{-- Filter bar — same class as VCB Produksi --}}
        <div class="filter-bar">
            <form style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;width:100%"
                  @submit.prevent="loadData()">
                <div class="filter-group">
                    <label>Dari Tanggal</label>
                    <input type="date" class="form-control form-control-sm"
                           x-model="dateFrom" :max="dateTo">
                </div>
                <div class="filter-group">
                    <label>Sampai Tanggal</label>
                    <input type="date" class="form-control form-control-sm"
                           x-model="dateTo" :min="dateFrom">
                </div>
                <div class="filter-group" style="flex-direction:row;gap:6px;align-items:flex-end">
                    <button type="submit" class="btn btn-secondary btn-sm">
                        <i class="bi bi-funnel me-1"></i>Tampilkan
                    </button>
                </div>
                <div style="margin-left:auto;display:flex;align-items:flex-end">
                    <span style="font-size:0.75rem;color:#5d7283;background:#f0f2f5;
                                 border:1px solid #d4dbe3;padding:4px 10px;white-space:nowrap">
                        <span x-show="loading"><i class="bi bi-arrow-repeat spin me-1"></i>Memuat...</span>
                        <span x-show="!loading" x-text="'Total Loss Time: ' + fmt(summary.total_loss_time) + ' mnt'">—</span>
                    </span>
                </div>
            </form>
        </div>

        {{-- Chart 1: Loss Time per Jenis (vertical bars) --}}
        <div class="erp-panel">
            <div class="erp-panel-header">
                <i class="bi bi-bar-chart me-1"></i>
                Grafik Loss Time per Jenis Loss Time (menit)
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
                    <i class="bi bi-calendar-week me-1"></i>Grafik Loss Time / Minggu (menit)
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
                    <i class="bi bi-calendar-month me-1"></i>Grafik Loss Time / Bulan (menit)
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
                <i class="bi bi-list-ol me-1"></i>Ranking Loss Time per Jenis
            </div>
            <div style="overflow-x:auto">
                <table class="erp-table">
                    <thead>
                        <tr>
                            <th style="width:36px;text-align:center">No</th>
                            <th style="width:80px">Kode</th>
                            <th>Jenis Loss Time</th>
                            <th style="width:140px;text-align:right">Total Loss Time (mnt)</th>
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

        <div class="vcbd-kpi-box">
            <div class="vcbd-kpi-label">Total Loss Time</div>
            <div class="vcbd-kpi-value" x-text="fmt(summary.total_loss_time)">—</div>
            <div class="vcbd-kpi-sub">menit</div>
        </div>

        <div class="vcbd-rank-section">
            <div class="vcbd-rank-label">Jenis Loss Time</div>
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
@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/chartjs-plugin-datalabels@2.2.0/dist/chartjs-plugin-datalabels.min.js"></script>
<script>
let chartMain = null, chartWeek = null, chartMonth = null;

function buildBar(ctx, labels, values) {
    return new Chart(ctx, {
        type: 'bar',
        plugins: [ChartDataLabels],   /* per-chart — won't affect other pages */
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
                datalabels: {
                    anchor: 'end',
                    align: 'end',
                    offset: 2,
                    font: { size: 10, weight: '700' },
                    color: '#1a3a4a',
                    /* sembunyikan label jika nilai 0 */
                    formatter: v => v > 0
                        ? v.toLocaleString('id-ID', { minimumFractionDigits: 1, maximumFractionDigits: 1 })
                        : '',
                    clip: false,
                },
            },
            scales: {
                x: {
                    beginAtZero: true,
                    ticks: {
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
            total_loss_time: 0,
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
            const p = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo, format: 'excel' });
            this.selectedMesin.forEach(id => p.append('mesin_ids[]', id));
            return `/export/dandori?${p}`;
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

        async loadData() {
            this.loading = true;
            const p = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo });
            this.selectedMesin.forEach(id => p.append('mesin_ids[]', id));

            try {
                const res  = await fetch(`/vcb/dandori/data?${p}`);
                const data = await res.json();

                /* Map total_over → total_loss_time for display label */
                data.total_loss_time = data.total_over;

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
            const make = (ref, elId, labels, values) => {
                if (ref) ref.destroy();
                const el = document.getElementById(elId);
                if (!el || !labels.length) return null;
                return buildBar(el, labels, values);
            };

            /* Main: vertical bars, label = jenis loss time name */
            chartMain = make(
                chartMain, 'chartMain',
                data.by_jenis.map(j => j.jenis),
                data.by_jenis.map(j => j.over)
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
                    <td style="font-weight:700;letter-spacing:.3px">${item.kode}</td>
                    <td>${item.jenis}</td>
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
