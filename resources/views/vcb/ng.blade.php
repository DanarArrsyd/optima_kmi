@extends('layouts.app')
@section('title', $judul)

@push('styles')
<style>
    /* ══ VCB NG — mengikuti bahasa visual ERP yang sudah ada ═══════════
     * Nol font baru, nol bayangan, nol warna di luar token layout.
     * Aksen merah #b91c1c adalah warna "danger" yang sudah dipakai
     * traffic light VCB lain — bukan warna baru.
     */
    .ngv-kpi {
        border-left: 3px solid var(--border);
        padding: 10px 14px;
        height: 100%;
    }
    .ngv-kpi--accent { border-left-color: #b91c1c; }
    .ngv-kpi-label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .04em;
        text-transform: uppercase;
        color: var(--text-3);
    }
    .ngv-kpi-value {
        font-size: 1.6rem;
        font-weight: 700;
        line-height: 1.15;
        margin-top: 2px;
        color: var(--text);
        font-variant-numeric: tabular-nums;
    }
    .ngv-kpi-sub {
        font-size: 11px;
        color: var(--text-3);
        margin-top: 2px;
        font-variant-numeric: tabular-nums;
    }

    /* Batang bisa diklik — kursor dan keterangan harus mengatakannya. */
    #ngRateChart { cursor: pointer; }

    .ngv-hint {
        font-size: 11px;
        color: var(--text-3);
        border-top: 1px solid var(--border);
        margin-top: 10px;
        padding-top: 8px;
    }

    /* Bilah porsi di dalam sel — rata, tanpa radius, sesuai tema industrial. */
    .ngv-bar-track {
        height: 6px;
        background: var(--surface-3);
        border: 1px solid var(--border);
        min-width: 60px;
    }
    .ngv-bar-fill { height: 100%; background: #b91c1c; }

    /* Dua grafik kanan-kiri di layar sempit numpuk jadi kolom sempit banget —
       balik ke tumpuk atas-bawah di bawah 900px. */
    @media (max-width: 900px) {
        .ngv-grafik-grid { grid-template-columns: 1fr !important; }
    }

    .ngv-total-row td {
        border-top: 2px solid var(--border);
        font-weight: 700;
    }
</style>
@endpush

{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
@php
    $defaultTo = date('Y-m-d');
    $defaultFrom = date('Y-m-01', strtotime('first day of -11 months'));
@endphp
<div x-data="vcbNg()" x-init="load()" class="erp-ws">

<div class="erp-tb">
    <button type="button" id="btnDock" class="erp-tb-btn"
            onclick="erpToggleDock('ngDock','btnDock')">
        <i class="bi bi-funnel"></i>Filter
    </button>
    <button type="button" class="erp-tb-btn" @click="load()" :disabled="loading">
        <i class="bi bi-arrow-clockwise"></i><span x-show="!loading">Refresh</span><span x-show="loading" x-cloak>Memuat&hellip;</span>
    </button>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- Dock mulai tertutup; cakupan data yang sedang dilihat tetap
     terbaca di status bar, jadi tidak ada yang tersembunyi diam-diam. --}}
<aside class="erp-dock" id="ngDock" style="display:none">
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" onclick="erpToggleDock('ngDock','btnDock')" title="Tutup filter">&times;</button>
    </div>
    <div class="erp-dock-body">
        <div class="erp-grp">
            <b>Filter by Date</b>
            <div class="erp-dock-row"><span>From</span><input type="date" class="erp-in" x-model="dateFrom"></div>
            <div class="erp-dock-row"><span>To</span><input type="date" class="erp-in" x-model="dateTo"></div>
        </div>
        <div class="erp-grp">
            <button type="button" class="erp-btn is-pri" @click="load()" :disabled="loading">
                <span x-show="!loading">Tampilkan</span>
                <span x-show="loading" x-cloak>Memuat&hellip;</span>
            </button>
        </div>
    </div>
</aside>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">

    {{-- ── Galat muat data ──────────────────────── --}}
    <div class="text-danger mb-3" x-show="loadError" x-cloak>
        Gagal memuat data NG dari server. Ringkasan dan grafik di bawah mungkin tidak lengkap atau tidak tampil — coba klik "Tampilkan" lagi.
    </div>

    {{-- ── Ringkasan ────────────────────────────── --}}
    <div class="row g-2 mb-3" x-show="hasData" x-cloak>
        <div class="col-6 col-md-4">
            <div class="erp-panel h-100"><div class="ngv-kpi">
                <div class="ngv-kpi-label">Total NG</div>
                <div class="ngv-kpi-value" x-text="fmtInt(summary.total_ng)">—</div>
                <div class="ngv-kpi-sub">pcs</div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="erp-panel h-100"><div class="ngv-kpi">
                <div class="ngv-kpi-label">Total Diperiksa</div>
                <div class="ngv-kpi-value" x-text="fmtInt(summary.total_check)">—</div>
                <div class="ngv-kpi-sub">pcs</div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="erp-panel h-100"><div class="ngv-kpi ngv-kpi--accent">
                <div class="ngv-kpi-label">NG Rate</div>
                <div class="ngv-kpi-value">
                    <span x-text="fmtInt(ppmOf(summary))"></span><span style="font-size:0.9rem;font-weight:600"> PPM</span>
                </div>
                <div class="ngv-kpi-sub" x-text="fmtPct(summary.ng_rate) + '%'">—</div>
            </div></div>
        </div>
    </div>

    {{-- ── Grafik NG Rate (PPM) & NG per bulan (pcs) — kanan-kiri, sengaja
         dua panel terpisah biar orang yang lirik sekilas nol ketuker rate
         vs jumlah pcs. ── --}}
    <div class="mb-3 ngv-grafik-grid" style="display:grid;grid-template-columns:1fr 1fr;gap:12px">
        <div class="erp-panel" style="margin:0">
            <div class="erp-panel-header">NG Rate per Bulan &mdash; PPM</div>
            <div class="erp-panel-body">
                <div x-show="!hasData && !loading && !loadError" x-cloak class="text-muted text-center py-4">
                    {{ $catatanKosong }}
                </div>
                <div x-show="hasData && summary.total_ng === 0" x-cloak class="text-muted mb-2">
                    {{ $catatanKosong }}
                </div>
                <div x-show="hasData" x-cloak style="height:320px">
                    <canvas id="ngRateChart"></canvas>
                </div>
                <div x-show="hasData" x-cloak class="ngv-hint">
                    1 PPM = 1 pcs NG per sejuta diperiksa — RATE per bulan, bukan jumlah pcs, jadi jangan dijumlah lintas bulan buat dibandingkan ke Total NG di atas. Klik satu batang bulan untuk melihat rincian jenis NG bulan itu (hover buat lihat jumlah pcs asli).
                </div>
            </div>
        </div>

        <div class="erp-panel" style="margin:0">
            <div class="erp-panel-header">NG per Bulan &mdash; Pcs</div>
            <div class="erp-panel-body">
                <div x-show="hasData" x-cloak style="height:320px">
                    <canvas id="ngPcsChart"></canvas>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Rincian bulan terpilih ───────────────── --}}
    <div class="erp-panel" x-show="bulanTerpilih" x-cloak id="ngv-rincian">
        <div class="erp-panel-header">
            <span x-text="'Rincian Jenis NG — ' + labelBulanTerpilih"></span>
        </div>
        <div class="erp-panel-body">
            <div x-show="detailError" x-cloak class="text-danger text-center py-3">
                Gagal memuat rincian bulan ini — coba klik batang bulan itu lagi.
            </div>
            <div x-show="!detailError && detail.length === 0" x-cloak class="text-muted text-center py-3">
                Bulan ini tidak punya NG tercatat.
            </div>
            <div class="table-responsive" x-show="!detailError && detail.length > 0" x-cloak>
                <table class="erp-table mb-0">
                    <thead>
                        <tr>
                            <th style="width:90px">Kode</th>
                            <th>Jenis NG</th>
                            <th class="text-end" style="width:90px">Qty</th>
                            <th class="text-end" style="width:80px">Porsi</th>
                            <th style="width:120px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in detail" :key="row.id_jenis_ng">
                            <tr>
                                <td style="font-weight:700" x-text="row.kode"></td>
                                <td x-text="row.nama_ng"></td>
                                <td class="text-end" style="font-variant-numeric:tabular-nums" x-text="fmtInt(row.qty)"></td>
                                <td class="text-end" style="font-variant-numeric:tabular-nums" x-text="fmtPct(row.porsi) + '%'"></td>
                                <td>
                                    <div class="ngv-bar-track">
                                        <div class="ngv-bar-fill" :style="`width: ${Math.min(100, row.porsi)}%`"></div>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <tr class="ngv-total-row">
                            <td colspan="2">Total bulan ini</td>
                            <td class="text-end" style="font-variant-numeric:tabular-nums" x-text="fmtInt(totalDetail)"></td>
                            <td class="text-end">100%</td>
                            <td></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>{{ $judul }}</span>
    <span x-show="hasData" x-cloak>Total NG <b x-text="fmtInt(summary.total_ng)"></b> pcs</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection


@push('scripts')
<script>
function vcbNg() {
    /*
     * Instance Chart.js SENGAJA disimpan di variabel closure, bukan sebagai
     * properti objek yang dikembalikan.
     *
     * Alpine membungkus state komponen jadi Proxy reaktif secara mendalam.
     * Instance Chart.js yang ikut ter-proxy akan meledak saat chart.update()
     * menelusuri struktur internalnya — tiap akses memicu pelacakan Alpine
     * sampai "Maximum call stack size exceeded", dan plugin datalabels tumbang
     * bersamanya. Itu yang membuat klik batang gagal diam-diam di prod
     * 2026-07-28: exception terjadi sebelum fetch, jadi panel rincian tampil
     * "tidak punya NG tercatat" padahal datanya ada.
     */
    let chart = null;
    let chartPcs = null;

    return {
        dateFrom: '{{ $defaultFrom }}',
        dateTo: '{{ $defaultTo }}',
        loading: false,
        hasData: false,
        loadError: false,
        summary: { total_ng: 0, total_check: 0, ng_rate: 0 },
        byMonth: [],
        bulanTerpilih: null,
        labelBulanTerpilih: '',
        detail: [],
        detailError: false,

        fmtInt(v) {
            return Number(v || 0).toLocaleString('id-ID');
        },

        /* NG Rate pabrik berada di orde 0,00x% — persen sulit dibaca sekilas,
         * PPM adalah satuan yang dipakai di lantai produksi. Dihitung dari
         * cacah mentah, bukan dari rate yang sudah dibulatkan. */
        ppmOf(m) {
            return m && m.total_check > 0
                ? Math.round(m.total_ng / m.total_check * 1000000)
                : 0;
        },

        fmtPct(v) {
            return Number(v || 0).toLocaleString('id-ID', { maximumFractionDigits: 4 });
        },

        get totalDetail() {
            return this.detail.reduce((s, r) => s + Number(r.qty || 0), 0);
        },

        async load() {
            this.loading = true;
            this.loadError = false;
            this.bulanTerpilih = null;
            this.detail = [];
            this.detailError = false;

            try {
                const p = new URLSearchParams({ date_from: this.dateFrom, date_to: this.dateTo });
                const res = await fetch(`{{ $routeData }}?${p}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!res.ok) { this.hasData = false; this.loadError = true; return; }

                const json = await res.json();
                this.summary = json.summary;
                this.byMonth = json.by_month;
                this.hasData = this.byMonth.length > 0;

                if (this.hasData) {
                    this.$nextTick(() => this.render());
                } else {
                    if (chart) { chart.destroy(); chart = null; }
                    if (chartPcs) { chartPcs.destroy(); chartPcs = null; }
                }
            } catch (e) {
                this.hasData = false;
                this.loadError = true;
            } finally {
                this.loading = false;
            }
        },

        render() {
            const ctx = document.getElementById('ngRateChart');
            if (!ctx) return;

            if (chart) chart.destroy();

            const self = this;

            chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: this.byMonth.map(m => m.bulan),
                    datasets: [{
                        label: 'NG Rate (PPM)',
                        data: this.byMonth.map(m => self.ppmOf(m)),
                        /* Baja untuk bulan biasa, merah untuk bulan yang sedang
                         * dibuka — seleksi harus terlihat di grafik, bukan cuma
                         * di judul panel di bawahnya. */
                        backgroundColor: c => self.byMonth[c.dataIndex]?.bulan_raw === self.bulanTerpilih
                            ? '#b91c1c'
                            : '#6b7280',
                        hoverBackgroundColor: '#374151',
                        borderSkipped: false,
                        maxBarThickness: 84,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    layout: { padding: { top: 24 } },   /* ruang untuk label di atas batang */
                    onClick(evt, els) {
                        if (!els.length) return;
                        self.pilihBulan(self.byMonth[els[0].index]);
                    },
                    scales: {
                        x: {
                            grid: { display: false },
                            ticks: { color: '#4b5563', font: { size: 12 } },
                        },
                        y: {
                            beginAtZero: true,
                            border: { display: false },
                            grid: { color: '#e5e7eb' },
                            ticks: {
                                color: '#6b7280',
                                font: { size: 11 },
                                precision: 0,
                                callback: v => Number(v).toLocaleString('id-ID') + ' PPM',
                            },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        /* Plugin datalabels terdaftar global dengan default
                         * display:false. Dinyalakan khusus di grafik ini supaya
                         * angkanya terbaca tanpa harus hover. */
                        datalabels: {
                            display: true,
                            anchor: 'end',
                            align: 'end',
                            offset: 2,
                            color: '#374151',
                            font: { size: 11, weight: 700 },
                            /* Angka ini RATE (PPM), bukan jumlah pcs NG bulan itu — tanpa
                             * unit di label, gampang dikira pcs dan dijumlah salah lawan
                             * kartu TOTAL NG di atas (yang memang jumlah pcs). */
                            formatter: v => Number(v).toLocaleString('id-ID') + ' ppm',
                        },
                        tooltip: {
                            displayColors: false,
                            callbacks: {
                                label(c) {
                                    const m = self.byMonth[c.dataIndex];
                                    return [
                                        `${self.fmtInt(self.ppmOf(m))} PPM  ·  ${self.fmtPct(m.ng_rate)}%`,
                                        `${self.fmtInt(m.total_ng)} NG dari ${self.fmtInt(m.total_check)} diperiksa`,
                                    ];
                                },
                            },
                        },
                    },
                },
            });

            /* Grafik kedua, jumlah pcs NG polos per bulan — nol rate, nol
             * pembagian, biar orang yang lirik sekilas nol ketuker sama
             * grafik PPM di atasnya. Chart instance ikut disimpan di closure
             * luar (chartPcs), BUKAN state Alpine — lihat catatan di atas. */
            const ctxPcs = document.getElementById('ngPcsChart');
            if (chartPcs) chartPcs.destroy();
            if (ctxPcs) {
                chartPcs = new Chart(ctxPcs, {
                    type: 'bar',
                    data: {
                        labels: this.byMonth.map(m => m.bulan),
                        datasets: [{
                            label: 'NG (pcs)',
                            data: this.byMonth.map(m => m.total_ng),
                            backgroundColor: '#9ca3af',
                            hoverBackgroundColor: '#374151',
                            borderSkipped: false,
                            maxBarThickness: 84,
                        }],
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        layout: { padding: { top: 24 } },
                        scales: {
                            x: {
                                grid: { display: false },
                                ticks: { color: '#4b5563', font: { size: 12 } },
                            },
                            y: {
                                beginAtZero: true,
                                border: { display: false },
                                grid: { color: '#e5e7eb' },
                                ticks: {
                                    color: '#6b7280',
                                    font: { size: 11 },
                                    precision: 0,
                                    callback: v => Number(v).toLocaleString('id-ID') + ' pcs',
                                },
                            },
                        },
                        plugins: {
                            legend: { display: false },
                            datalabels: {
                                display: true,
                                anchor: 'end',
                                align: 'end',
                                offset: 2,
                                color: '#374151',
                                font: { size: 11, weight: 700 },
                                formatter: v => Number(v).toLocaleString('id-ID'),
                            },
                            tooltip: {
                                displayColors: false,
                                callbacks: {
                                    label: c => `${self.fmtInt(c.raw)} pcs NG`,
                                },
                            },
                        },
                    },
                });
            }
        },

        async pilihBulan(m) {
            this.bulanTerpilih = m.bulan_raw;
            this.labelBulanTerpilih = m.bulan;
            this.detail = [];
            this.detailError = false;

            /*
             * Pewarnaan ulang batang ditunda ke frame berikutnya dan dibungkus
             * try/catch: memanggil update() di tengah penanganan klik Chart.js
             * pernah menjatuhkan plugin datalabels. Apa pun yang terjadi di
             * sini tidak boleh menggagalkan pengambilan rincian di bawah —
             * itulah kegagalan yang membuat panel tampak kosong.
             */
            if (chart) {
                requestAnimationFrame(() => {
                    try { chart.update('none'); } catch (e) { /* warna saja, bukan data */ }
                });
            }

            try {
                const p = new URLSearchParams({ bulan: m.bulan_raw });
                const res = await fetch(`{{ $routeDetail }}?${p}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!res.ok) { this.detailError = true; return; }

                this.detail = await res.json();
            } catch (e) {
                this.detailError = true;
            } finally {
                /* Bawa mata pengguna ke daftar rincian — tanpa ini, panelnya
                 * muncul di bawah lipatan dan kliknya terasa tidak melakukan apa-apa. */
                this.$nextTick(() => {
                    const el = document.getElementById('ngv-rincian');
                    if (!el) return;
                    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
                    el.scrollIntoView({ behavior: reduce ? 'auto' : 'smooth', block: 'start' });
                });
            }
        },
    };
}
</script>
@endpush
