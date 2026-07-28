@extends('layouts.app')
@section('title', $judul)

@section('content')
@php
    $defaultTo = date('Y-m-d');
    $defaultFrom = date('Y-m-01', strtotime('-11 months'));
@endphp

<div x-data="vcbNg()" x-init="load()">

    {{-- ── Filter ───────────────────────────────── --}}
    <div class="erp-panel mb-3">
        <div class="erp-panel-body">
            <div class="row g-2 align-items-end">
                <div class="col-auto">
                    <label class="form-label mb-1" style="font-size:0.82rem">Dari</label>
                    <input type="date" class="form-control form-control-sm" x-model="dateFrom">
                </div>
                <div class="col-auto">
                    <label class="form-label mb-1" style="font-size:0.82rem">Sampai</label>
                    <input type="date" class="form-control form-control-sm" x-model="dateTo">
                </div>
                <div class="col-auto">
                    <button class="btn btn-sm btn-dark" @click="load()" :disabled="loading">
                        <span x-show="!loading">Tampilkan</span>
                        <span x-show="loading" x-cloak>Memuat…</span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    {{-- ── Galat muat data ──────────────────────── --}}
    <div class="text-danger mb-3" x-show="loadError" x-cloak>
        Gagal memuat data NG dari server. Ringkasan dan grafik di bawah mungkin tidak lengkap atau tidak tampil — coba klik "Tampilkan" lagi.
    </div>

    {{-- ── Ringkasan ────────────────────────────── --}}
    <div class="row g-2 mb-3" x-show="hasData" x-cloak>
        <div class="col-6 col-md-4">
            <div class="erp-panel h-100"><div class="erp-panel-body">
                <div class="text-muted" style="font-size:0.78rem">Total NG</div>
                <div style="font-size:1.4rem;font-weight:700" x-text="fmtInt(summary.total_ng)"></div>
            </div></div>
        </div>
        <div class="col-6 col-md-4">
            <div class="erp-panel h-100"><div class="erp-panel-body">
                <div class="text-muted" style="font-size:0.78rem">Total Diperiksa</div>
                <div style="font-size:1.4rem;font-weight:700" x-text="fmtInt(summary.total_check)"></div>
            </div></div>
        </div>
        <div class="col-12 col-md-4">
            <div class="erp-panel h-100"><div class="erp-panel-body">
                <div class="text-muted" style="font-size:0.78rem">NG Rate</div>
                <div style="font-size:1.4rem;font-weight:700" x-text="summary.ng_rate + '%'"></div>
            </div></div>
        </div>
    </div>

    {{-- ── Grafik NG Rate per bulan ─────────────── --}}
    <div class="erp-panel mb-3">
        <div class="erp-panel-head">NG Rate per Bulan</div>
        <div class="erp-panel-body">
            <div x-show="!hasData && !loading && !loadError" x-cloak class="text-muted text-center py-4">
                {{ $catatanKosong }}
            </div>
            <div x-show="hasData" x-cloak style="height:320px">
                <canvas id="ngRateChart"></canvas>
            </div>
            <div x-show="hasData" x-cloak class="text-muted mt-2" style="font-size:0.78rem">
                Klik satu batang bulan untuk melihat rincian jenis NG bulan itu.
            </div>
        </div>
    </div>

    {{-- ── Rincian bulan terpilih ───────────────── --}}
    <div class="erp-panel" x-show="bulanTerpilih" x-cloak>
        <div class="erp-panel-head" x-text="'Rincian NG — ' + labelBulanTerpilih"></div>
        <div class="erp-panel-body">
            <div x-show="detailError" x-cloak class="text-danger text-center py-3">
                Gagal memuat rincian bulan ini — coba klik batang bulan itu lagi.
            </div>
            <div x-show="!detailError && detail.length === 0" x-cloak class="text-muted text-center py-3">
                Bulan ini tidak punya NG tercatat.
            </div>
            <div class="table-responsive" x-show="!detailError && detail.length > 0" x-cloak>
                <table class="table table-sm align-middle mb-0">
                    <thead>
                        <tr>
                            <th style="width:100px">Kode</th>
                            <th>Jenis NG</th>
                            <th class="text-end" style="width:100px">Qty</th>
                            <th class="text-end" style="width:100px">Porsi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="row in detail" :key="row.kode">
                            <tr>
                                <td x-text="row.kode"></td>
                                <td x-text="row.nama_ng"></td>
                                <td class="text-end" x-text="fmtInt(row.qty)"></td>
                                <td class="text-end" x-text="row.porsi + '%'"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
function vcbNg() {
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
        chart: null,

        fmtInt(v) {
            return Number(v || 0).toLocaleString('id-ID');
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
                } else if (this.chart) {
                    this.chart.destroy();
                    this.chart = null;
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

            if (this.chart) this.chart.destroy();

            const self = this;

            this.chart = new Chart(ctx, {
                type: 'bar',
                data: {
                    labels: this.byMonth.map(m => m.bulan),
                    datasets: [{
                        label: 'NG Rate (%)',
                        data: this.byMonth.map(m => m.ng_rate),
                        backgroundColor: '#b91c1c',
                        borderRadius: 3,
                        borderSkipped: false,
                    }],
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    onClick(evt, els) {
                        if (!els.length) return;
                        self.pilihBulan(self.byMonth[els[0].index]);
                    },
                    scales: {
                        y: {
                            beginAtZero: true,
                            ticks: { callback: v => v + '%' },
                        },
                    },
                    plugins: {
                        legend: { display: false },
                        datalabels: { display: false },
                        tooltip: {
                            callbacks: {
                                label(c) {
                                    const m = self.byMonth[c.dataIndex];
                                    return `${m.ng_rate}%  —  ${self.fmtInt(m.total_ng)} NG dari ${self.fmtInt(m.total_check)}`;
                                },
                            },
                        },
                    },
                },
            });
        },

        async pilihBulan(m) {
            this.bulanTerpilih = m.bulan_raw;
            this.labelBulanTerpilih = m.bulan;
            this.detail = [];
            this.detailError = false;

            try {
                const p = new URLSearchParams({ bulan: m.bulan_raw });
                const res = await fetch(`{{ $routeDetail }}?${p}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!res.ok) { this.detailError = true; return; }

                this.detail = await res.json();
            } catch (e) {
                this.detailError = true;
            }
        },
    };
}
</script>
@endpush
