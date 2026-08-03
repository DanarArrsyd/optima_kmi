@extends('layouts.app')
@section('title', 'Overview')

{{--
    Overview grafik global pabrik.

    Halaman ini TIDAK ada hubungannya dengan AI Assistant, walau sempat lahir
    sebagai sub-menunya. Jangan memuat css/chatbot.css di sini: berkas itu
    menyetel #main jadi kanvas penuh tanpa padding untuk halaman chat, dan tiap
    panel di bawah harus melawan gaya itu satu-satu.

    Semua angka dari OverviewService. Blade ini hanya merender.
--}}

@push('styles')
<style>
    /* ══ Kanvas satu layar ═══════════════════════════════════════════
       Seluruh dashboard dikunci setinggi viewport supaya tidak pernah
       di-scroll. Tingginya DIHITUNG, bukan ditebak:

         100vh
         − var(--tb-h)  (46px topbar fixed)
         − 36px         (padding atas+bawah #main, 18px masing-masing)
         − 44px         (page-header versi ringkas: judul 16px + padding 5
                         + border 1 + margin-bottom 8 ≈ 38px, dibulatkan ke 44
                         sebagai sisa napas. Angka pas-pasan berarti pertumbuhan
                         sekecil apa pun di header langsung memunculkan scroll,
                         dan itu justru yang sedang dihindari.)

       #main SENGAJA TIDAK di-override di sini. css/chatbot.css melakukan itu
       untuk halaman chat dan akibatnya tiap elemen baru di sana harus melawan
       gaya global. Memasukkan padding #main ke dalam calc() memberi hasil yang
       sama tanpa menyentuh apa pun di luar halaman ini.

       min-height:0 di SETIAP anak flex/grid itu WAJIB, bukan hiasan: tanpa itu
       anak flex menolak menyusut di bawah tinggi kontennya, canvas Chart.js
       memaksa tingginya sendiri, dan seluruh trik ini bocor jadi scroll. */
    .ov-page {
        height: calc(100vh - var(--tb-h) - 36px - 44px);
        display: grid;
        grid-template-rows: minmax(0, 1fr) minmax(0, 1.25fr);
        gap: 8px;
        overflow: hidden;
    }

    /* Header halaman diringkas — tiap piksel di sini langsung mengurangi
       ruang grafik. */
    .ov-page-header { margin-bottom: 8px; padding-bottom: 5px; }
    .ov-page-header .page-title { font-size: 16px; }
    .ov-page-header .page-title i { font-size: 15px; }

    .ov-row {
        display: grid;
        gap: 8px;
        min-height: 0;
        align-items: stretch;
    }
    .ov-row-1 { grid-template-columns: 1fr 1.9fr 1.2fr 1.2fr; }
    .ov-row-2 { grid-template-columns: 1fr 1.9fr 2.4fr; }

    /* Kolom kiri baris 2: dua panel menumpuk (OEE besar + rincian A/P/Q) */
    .ov-stack { display: flex; flex-direction: column; gap: 8px; min-height: 0; }
    .ov-stack > .erp-panel { flex: 0 0 auto; }
    .ov-stack > .erp-panel.ov-grow { flex: 1 1 0; }

    .ov-row .erp-panel,
    .ov-stack .erp-panel {
        margin-bottom: 0;
        min-height: 0;
        display: flex;
        flex-direction: column;
        overflow: hidden;
    }
    .ov-row .erp-panel-body,
    .ov-stack .erp-panel-body {
        flex: 1 1 0;
        min-height: 0;
        display: flex;
        flex-direction: column;
        justify-content: center;
        padding: 7px 9px;
    }

    /* Header panel dipadatkan dan dipaksa satu baris. Sebelumnya
       "ACHIEVEMENT PRODUCTION - LAST 24 H" membungkus jadi dua baris, yang
       memakan ~18px DAN membuat header baris 1 tidak lagi sejajar. */
    .ov-row .erp-panel-header,
    .ov-stack .erp-panel-header {
        padding: 4px 9px;
        min-height: 0;
        font-size: 11px;
        letter-spacing: 0.3px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
        gap: 5px;
    }

    /* Suffix rentang di header panel — abu, bukan bold, supaya nama metriknya
       yang dibaca lebih dulu. */
    .ov-sub {
        color: var(--text-4);
        font-weight: 600;
        letter-spacing: 0.6px;
        margin-left: 2px;
    }

    /* ══ Angka besar (OEE / A / P / Q) ══════════════════════════════
       Ukurannya pakai clamp() bersatuan vh, bukan px tetap. Halaman ini
       terkunci setinggi viewport, jadi font px tetap berarti angkanya
       tumpah keluar panel di layar pendek (laptop 768px) sementara di layar
       besar malah kelihatan kecil. Batas bawah clamp menjaga tetap terbaca
       dari jarak berdiri di lantai produksi. */
    .ov-big {
        font-size: clamp(24px, 4.6vh, 54px);
        font-weight: 700;
        line-height: 1.02;
        text-align: center;
        color: var(--text);
        letter-spacing: -1px;
    }
    .ov-big-sm { font-size: clamp(17px, 2.9vh, 34px); }
    .ov-metric { min-height: 0; }
    .ov-metric + .ov-metric { border-top: 1px solid var(--border-2); margin-top: 5px; padding-top: 5px; }
    .ov-metric-label {
        font-size: 10px;
        font-weight: 700;
        color: var(--text-2);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        margin-bottom: 0;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    /* ══ Donut + angka di tengahnya ═════════════════════════════════
       Angka ditulis sebagai HTML yang ditumpuk di atas canvas, bukan plugin
       Chart.js: satu elemen teks biasa lebih mudah distel dan ikut aturan
       font Arial global tanpa menghitung ulang koordinat canvas. */
    .ov-donut-wrap { position: relative; flex: 1 1 0; min-height: 0; }
    .ov-donut-center {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
        pointer-events: none;
        font-size: clamp(17px, 2.9vh, 30px);
        font-weight: 700;
        color: var(--text);
    }
    .ov-legend {
        display: flex;
        justify-content: center;
        gap: 11px;
        margin-top: 3px;
        flex: 0 0 auto;
        font-size: 9.5px;
        font-weight: 700;
        color: var(--text-2);
        text-transform: uppercase;
        letter-spacing: 0.4px;
    }
    .ov-legend span { display: inline-flex; align-items: center; gap: 3px; }
    .ov-legend i {
        width: 8px; height: 8px;
        display: inline-block;
        border: 1px solid var(--border);
    }

    /* ══ Kotak canvas grafik ════════════════════════════════════════
       Tinggi px tetap DIGANTI flex: canvas mengisi sisa ruang panel apa pun
       tinggi viewport-nya. Chart.js sudah maintainAspectRatio:false, jadi ia
       mengikuti kotak ini — bukan sebaliknya. */
    .ov-chart-line,
    .ov-chart-pareto { position: relative; flex: 1 1 0; min-height: 0; }

    /* ══ Tombol scope Pareto ════════════════════════════════════════ */
    .ov-scope { display: flex; justify-content: center; margin-bottom: 4px; flex: 0 0 auto; }
    .ov-scope button {
        background: var(--surface);
        border: 1px solid var(--border);
        margin-left: -1px;
        padding: 2px 11px;
        font-size: 10px;
        font-weight: 700;
        color: var(--text-3);
        text-transform: uppercase;
        letter-spacing: 0.4px;
        cursor: pointer;
        transition: background var(--t), color var(--t);
    }
    .ov-scope button:first-child { margin-left: 0; }
    .ov-scope button:hover { background: var(--surface-2); color: var(--text); }
    .ov-scope button.on {
        background: var(--btn-bg);
        border-color: var(--btn-bg);
        color: #fff;
        position: relative;
        z-index: 1;
    }

    .ov-empty {
        padding: 40px 14px;
        text-align: center;
        color: var(--text-3);
        font-size: 14px;
    }

    /* Catatan kenapa sebuah angka kosong — TETAP terlihat, tidak dipindah ke
       tooltip. Halaman yang diam soal ini bikin orang menyangka datanya nol.
       Yang berubah cuma tempatnya: dulu panel sendiri (mencuri satu slot
       grid), sekarang catatan kaki di dalam panel A/P/Q dengan tinggi dibatasi
       + scroll sendiri, supaya catatan panjang tidak pernah mendorong grafik
       lain keluar layar. */
    .ov-catatan {
        font-size: 10px;
        color: var(--text-3);
        line-height: 1.35;
        margin: 5px 0 0;
        padding-left: 14px;
        max-height: 3.6em;
        overflow-y: auto;
        flex: 0 0 auto;
        border-top: 1px solid var(--border-2);
        padding-top: 4px;
    }

    /* ══ Responsif ══════════════════════════════════════════════════
       Di bawah ambang ini kunci satu-layar DILEPAS dan halaman boleh
       di-scroll lagi. Memaksa sepuluh panel masuk layar sempit atau pendek
       menghasilkan grafik yang tak terbaca — scroll lebih baik daripada
       angka setinggi 8px. */
    @media (max-width: 1400px), (max-height: 620px) {
        .ov-page {
            height: auto;
            overflow: visible;
            grid-template-rows: none;
        }
        .ov-row-1 { grid-template-columns: 1fr 1fr; }
        .ov-row-2 { grid-template-columns: 1fr 1fr; }
        .ov-row-2 > .erp-panel:last-child { grid-column: 1 / -1; }

        /* Kotak grafik kembali bertinggi tetap: tanpa tinggi induk yang
           terdefinisi, flex:1 1 0 runtuh jadi nol dan canvas-nya hilang. */
        .ov-donut-wrap  { flex: 0 0 auto; height: 180px; }
        .ov-chart-line  { flex: 0 0 auto; height: 225px; }
        .ov-chart-pareto{ flex: 0 0 auto; height: 320px; }
        .ov-big         { font-size: 44px; }
        .ov-big-sm      { font-size: 30px; }
        .ov-donut-center{ font-size: 26px; }
        .ov-catatan     { max-height: none; }
    }
    @media (max-width: 900px) {
        .ov-row-1, .ov-row-2 { grid-template-columns: 1fr; }
        .ov-row-2 > .erp-panel:last-child { grid-column: auto; }
    }
</style>
@endpush

@section('content')

{{-- ── PAGE HEADER ──────────────────────────────────────────── --}}
<div class="page-header ov-page-header">
    <h1 class="page-title">
        <i class="bi bi-graph-up-arrow"></i>Overview
    </h1>
    @unless($kosong)
        {{-- Tanggal acuan ditulis eksplisit. Panel-panel di bawah berlabel
             "LAST 24 H", dan tanpa tanggal ini orang tidak punya cara tahu
             hari mana yang sebenarnya ditampilkan — LHP sering baru masuk
             besoknya, jadi acuannya belum tentu hari ini. --}}
        <span class="text-muted" style="font-size:12px">
            Data per <strong>{{ $acuan->format('d M Y') }}</strong> (tanggal LHP produksi terakhir)
        </span>
    @endunless
</div>

@if($kosong)
    <div class="erp-panel">
        <div class="ov-empty">
            Belum ada LHP produksi sama sekali, jadi belum ada yang bisa digrafikkan.
        </div>
    </div>
@else

{{-- Alpine HANYA mengurus tombol scope Pareto. Grafiknya digambar oleh
     DOMContentLoaded di bawah, lepas dari Alpine — kalau Alpine gagal boot,
     semua grafik tetap tampil dan yang mati cuma tombol scope-nya. Menaruh
     penggambaran di x-init membuat kegagalan Alpine berarti halaman KOSONG. --}}
<div x-data="ovPage()" class="ov-page">

    {{-- ══ BARIS 1 — ACHIEVEMENT ══════════════════════════════════ --}}
    <div class="ov-row ov-row-1">

        <div class="erp-panel">
            <div class="erp-panel-header">Achievement Production <span class="ov-sub">- LAST 24 H</span></div>
            <div class="erp-panel-body">
                <div class="ov-donut-wrap">
                    <canvas id="ovDonutTotal"></canvas>
                    <div class="ov-donut-center">{{ $achievement['pct'] === null ? '—' : number_format($achievement['pct'], 0, ',', '.').'%' }}</div>
                </div>
                <div class="ov-legend">
                    <span><i style="background:#facc15"></i>ACT</span>
                    <span><i style="background:#ffffff"></i>TARGET</span>
                </div>
            </div>
        </div>

        <div class="erp-panel">
            <div class="erp-panel-header">Achievement <span class="ov-sub">- {{ $tren === [] ? '' : count($tren) }} LAST DAYS</span></div>
            <div class="erp-panel-body">
                <div class="ov-chart-line"><canvas id="ovLineAch"></canvas></div>
            </div>
        </div>

        @foreach($perShift as $s)
            <div class="erp-panel">
                <div class="erp-panel-header">Achievement {{ strtoupper($s['nama']) }} <span class="ov-sub">- LAST 24 H</span></div>
                <div class="erp-panel-body">
                    <div class="ov-donut-wrap">
                        <canvas id="ovDonutShift{{ $loop->index }}"></canvas>
                        <div class="ov-donut-center">{{ $s['pct'] === null ? '—' : number_format($s['pct'], 0, ',', '.').'%' }}</div>
                    </div>
                    <div class="ov-legend">
                        <span><i style="background:#facc15"></i>ACT</span>
                        <span><i style="background:#ffffff"></i>TARGET</span>
                    </div>
                </div>
            </div>
        @endforeach
    </div>

    {{-- ══ BARIS 2 — OEE + LOSS TIME ══════════════════════════════ --}}
    <div class="ov-row ov-row-2">

        {{-- Kolom kiri: OEE besar + rincian A/P/Q --}}
        <div class="ov-stack">
            <div class="erp-panel">
                <div class="erp-panel-header">OEE <span class="ov-sub">- LAST 24 H</span></div>
                <div class="erp-panel-body">
                    <div class="ov-big">{{ $oee['oee_pct'] === null ? '—' : number_format($oee['oee_pct'], 1, ',', '.').'%' }}</div>
                </div>
            </div>

            {{-- ov-grow: panel INILAH yang menyerap sisa tinggi kolom, bukan
                 panel OEE di atasnya. Kalau keduanya tumbuh, angka OEE tunggal
                 dapat ruang sebanyak tiga angka A/P/Q sekaligus. --}}
            <div class="erp-panel ov-grow">
                <div class="erp-panel-body">
                    @foreach([
                        'Availability' => $oee['availability_pct'],
                        'Performance'  => $oee['performance_pct'],
                        'Quality'      => $oee['quality_pct'],
                    ] as $label => $nilai)
                        <div class="ov-metric">
                            <div class="ov-metric-label">{{ $label }} <span class="ov-sub">- LAST 24 H</span></div>
                            <div class="ov-big ov-big-sm">{{ $nilai === null ? '—' : number_format($nilai, 1, ',', '.').'%' }}</div>
                        </div>
                    @endforeach

                    {{-- Catatan ikut DI DALAM panel ini, bukan panel ketiga.
                         Panel sendiri mencuri satu slot tinggi dari kolom dan
                         itu langsung memakan ukuran angka A/P/Q. --}}
                    @if($oee['catatan'] !== [])
                        <ul class="ov-catatan">
                            @foreach($oee['catatan'] as $c)
                                <li>{{ $c }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>
        </div>

        {{-- Tengah: tren OEE --}}
        <div class="erp-panel">
            <div class="erp-panel-header">OEE <span class="ov-sub">- {{ count($tren) }} LAST DAYS</span></div>
            <div class="erp-panel-body">
                <div class="ov-chart-line"><canvas id="ovLineOee"></canvas></div>
            </div>
        </div>

        {{-- Kanan: Pareto loss time --}}
        <div class="erp-panel">
            <div class="erp-panel-header">
                Loss Time
                <span class="ov-sub" x-text="'- ' + labelScope()"></span>
                <span class="ov-sub" style="margin-left:auto;text-transform:none;letter-spacing:0">menit</span>
            </div>
            <div class="erp-panel-body">
                <div class="ov-scope">
                    <button type="button" :class="{ 'on': scope === 'harian' }"  @click="setScope('harian')">Last 24 H</button>
                    <button type="button" :class="{ 'on': scope === 'bulanan' }" @click="setScope('bulanan')">This Month</button>
                    <button type="button" :class="{ 'on': scope === 'semua' }"   @click="setScope('semua')">All Time</button>
                </div>
                <div class="ov-chart-pareto"><canvas id="ovPareto"></canvas></div>
                <div class="ov-empty" x-show="kosongScope()" x-cloak style="padding:14px">
                    Tidak ada data dandori di rentang ini.
                </div>
            </div>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
@unless($kosong)
<script>
/* ════════════════════════════════════════════════════════════════════
   Overview — grafik

   ⚠️ Instance Chart.js DISENGAJA disimpan di sini, DI LUAR state Alpine.
   Menaruh objek Chart di dalam x-data membuat Alpine membungkusnya jadi
   Proxy reaktif; chart.update() lalu menulis ke properti internalnya
   sendiri, memicu reaktivitas, yang memanggil update() lagi — rekursi
   sampai stack habis. Render PERTAMA selamat, jadi gejalanya baru muncul
   saat tombol scope diklik dan mudah salah didiagnosis.
   ════════════════════════════════════════════════════════════════════ */
const OV = {
    charts: {},
    tren: @json($tren),
    loss: @json($lossTime),
    ach: @json($achievement['pct']),
    shift: @json(array_column($perShift, 'pct')),
};

/* Format angka gaya Indonesia: 33.619,0 */
const ovNum = (v, d = 1) => Number(v).toLocaleString('id-ID', {
    minimumFractionDigits: d, maximumFractionDigits: d,
});

const OV_BIRU   = '#31708f';   /* ACT   */
const OV_ORANYE = '#ed7d31';   /* TARGET */
const OV_KUNING = '#facc15';   /* isi donut ACT */

/* Datalabel ACT: kotak kuning bergaris oranye, seperti desain. */
const ovLabelAct = {
    display: true,
    align: 'bottom',
    offset: 4,
    backgroundColor: '#ffff00',
    borderColor: OV_ORANYE,
    borderWidth: 1,
    borderRadius: 2,
    padding: { top: 0, bottom: 0, left: 2, right: 2 },
    color: '#000',
    font: { size: 9, weight: 'bold' },
    formatter: v => v === null ? '' : Math.round(v) + '%',
};

const ovLabelTarget = {
    display: true,
    align: 'top',
    offset: 3,
    color: '#4b5563',
    font: { size: 9, weight: 'bold' },
    formatter: v => v === null ? '' : Math.round(v) + '%',
};

/* ─ Donut: ACT vs sisa menuju 100% ──────────────────────────────
   pct null = tidak ada target yang bisa dibandingkan. Cincin digambar
   kosong dan angka tengahnya sudah "—" dari Blade; JANGAN dipaksa 0,
   itu terbaca sebagai gagal total. */
function ovDonut(id, pct) {
    const el = document.getElementById(id);
    if (!el) return;

    const act = pct === null ? 0 : Math.max(0, Math.min(pct, 100));
    const sisa = 100 - act;

    OV.charts[id] = new Chart(el, {
        type: 'doughnut',
        data: {
            labels: ['ACT', 'TARGET'],
            datasets: [{
                data: pct === null ? [0, 100] : [act, sisa],
                backgroundColor: [OV_KUNING, '#ffffff'],
                borderColor: '#111827',
                borderWidth: 1,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '62%',
            plugins: {
                legend: { display: false },
                datalabels: { display: false },
                tooltip: {
                    callbacks: {
                        label: c => c.label + ': ' + ovNum(c.parsed, 1) + '%',
                    },
                },
            },
        },
    });
}

/* ─ Line tren: ACT vs TARGET rata 100% ────────────────────────── */
function ovLine(id, kunci) {
    const el = document.getElementById(id);
    if (!el) return;

    OV.charts[id] = new Chart(el, {
        type: 'line',
        data: {
            labels: OV.tren.map(r => r.label),
            datasets: [
                {
                    label: 'ACT',
                    data: OV.tren.map(r => r[kunci]),
                    borderColor: OV_BIRU,
                    backgroundColor: OV_BIRU,
                    borderWidth: 2,
                    pointRadius: 4,
                    /* Hari tanpa data (null) diputus, bukan disambung lurus —
                       garis yang menyambung bikin hari kosong terbaca sebagai
                       interpolasi yang seolah-olah terukur. */
                    spanGaps: false,
                    datalabels: ovLabelAct,
                },
                {
                    label: 'TARGET',
                    data: OV.tren.map(() => 100),
                    borderColor: OV_ORANYE,
                    backgroundColor: OV_ORANYE,
                    borderWidth: 2,
                    pointRadius: 4,
                    datalabels: ovLabelTarget,
                },
            ],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 10, right: 6 } },
            scales: {
                y: {
                    beginAtZero: true,
                    max: 120,
                    ticks: { stepSize: 20, callback: v => v + '%', font: { size: 9 } },
                    grid: { color: '#f3f4f6' },
                },
                x: { grid: { display: false }, ticks: { font: { size: 9 } } },
            },
            plugins: {
                legend: { position: 'bottom', labels: { boxWidth: 20, boxHeight: 8, padding: 6, font: { size: 9 } } },
                tooltip: {
                    callbacks: {
                        label: c => c.dataset.label + ': ' +
                            (c.parsed.y === null ? 'tidak ada data' : ovNum(c.parsed.y, 1) + '%'),
                    },
                },
            },
        },
    });
}

/* ─ Pareto loss time ──────────────────────────────────────────── */
function ovPareto(scope) {
    const el = document.getElementById('ovPareto');
    if (!el) return;

    const rows = OV.loss[scope] ?? [];

    OV.charts.pareto = new Chart(el, {
        type: 'bar',
        data: {
            labels: rows.map(r => r.jenis),
            datasets: [{
                label: 'Menit',
                data: rows.map(r => r.menit),
                backgroundColor: OV_BIRU,
                borderColor: '#255a75',
                borderWidth: 1,
            }],
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: { padding: { top: 14 } },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        font: { size: 9 },
                        /* 35.0k — ribuan disingkat, kalau tidak label sumbunya
                           lebih lebar dari grafiknya sendiri. */
                        callback: v => v >= 1000 ? ovNum(v / 1000, 1) + 'k' : ovNum(v, 0),
                    },
                    grid: { color: '#f3f4f6' },
                },
                x: {
                    grid: { display: false },
                    ticks: { font: { size: 8 }, maxRotation: 60, minRotation: 50, autoSkip: false },
                },
            },
            plugins: {
                legend: { display: false },
                datalabels: {
                    display: true,
                    anchor: 'end',
                    align: 'top',
                    offset: 2,
                    color: '#374151',
                    font: { size: 8, weight: 'bold' },
                    formatter: v => ovNum(v, 1),
                },
                tooltip: {
                    callbacks: { label: c => ovNum(c.parsed.y, 1) + ' menit' },
                },
            },
        },
    });
}

/* ─ Gambar semua grafik — TIDAK lewat Alpine ─────────────────────
   Sengaja di DOMContentLoaded, bukan x-init. Chart.js dimuat non-defer di
   layout jadi sudah siap di sini, dan grafik tidak lagi bergantung pada
   Alpine yang boot dengan defer. Kalau Alpine mati, halaman ini tetap
   menampilkan seluruh grafiknya; yang hilang cuma tombol scope. */
document.addEventListener('DOMContentLoaded', () => {
    ovDonut('ovDonutTotal', OV.ach);
    OV.shift.forEach((p, i) => ovDonut('ovDonutShift' + i, p));
    ovLine('ovLineAch', 'achievement_pct');
    ovLine('ovLineOee', 'oee_pct');
    ovPareto('harian');
});

function ovPage() {
    return {
        scope: 'harian',

        setScope(s) {
            if (this.scope === s) return;
            this.scope = s;

            /* Data diganti lalu update() — bukan destroy + new Chart():
               membuat ulang chart membuang animasi dan menyebabkan canvas
               berkedip pada tiap klik.

               Guard-nya bukan hiasan: Alpine boot dengan defer, jadi urutan
               relatifnya terhadap DOMContentLoaded tidak dijamin di semua
               browser. Klik sebelum chart-nya ada harus jadi no-op, bukan
               TypeError yang mematikan seluruh komponen Alpine ini. */
            const c = OV.charts.pareto;
            if (!c) return;

            const rows = OV.loss[s] ?? [];
            c.data.labels = rows.map(r => r.jenis);
            c.data.datasets[0].data = rows.map(r => r.menit);
            c.update();
        },

        kosongScope() {
            return (OV.loss[this.scope] ?? []).length === 0;
        },

        labelScope() {
            return { harian: 'LAST 24 H', bulanan: 'THIS MONTH', semua: 'ALL TIME' }[this.scope];
        },
    };
}
</script>
@endunless
@endpush
