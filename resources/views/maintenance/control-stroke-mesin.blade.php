@extends('layouts.app')
@section('title', 'Control Stroke Mesin')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
<style>
    #strokeTable tbody td { white-space: nowrap; }

    /* KPI strip ringkas — satu baris, tiap sel bisa diklik jadi filter status.
       Menggantikan 3 kartu tinggi yang dulu makan ~240px vertikal. */
    .kpi-strip { display:flex; gap:10px; flex-wrap:wrap; }
    .kpi-cell {
        flex:1 1 150px; display:flex; align-items:center; gap:10px;
        border:1px solid #e5e7eb; border-left-width:4px; border-radius:4px;
        background:#fff; padding:8px 12px; cursor:pointer; user-select:none;
        transition:background .1s, box-shadow .1s;
    }
    .kpi-cell:hover { background:#f9fafb; }
    .kpi-cell.active { box-shadow:0 0 0 2px rgba(17,24,39,.25) inset; background:#f3f4f6; }
    .kpi-cell .kpi-num  { font-size:1.4rem; font-weight:700; line-height:1; }
    .kpi-cell .kpi-lbl  { font-size:11px; color:#6b7280; line-height:1.2; }
    .kpi-cell i         { font-size:1.3rem; }


</style>

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════
     Toolbar pencarian, urutan, dan filter lanjutan dipindah UTUH ke dalam
     dock — id dan kelasnya tidak diubah sedikit pun supaya JS halaman ini
     tetap bekerja apa adanya. --}}
<div class="erp-ws" x-data="{ filterOpen: false }">

<div class="erp-tb">
    <button type="button" class="erp-tb-btn" :class="filterOpen ? 'is-on' : ''" @click="filterOpen = !filterOpen">
        <i class="bi bi-funnel"></i>Filter
    </button>
    @if(! auth()->user()->isGuest())
    {{-- href diisi pemuat data — file selalu sama dengan tabel, termasuk filternya. --}}
    <a id="exportExcel" href="{{ route('maintenance.control-stroke-mesin.export') }}" class="erp-tb-btn"><i class="bi bi-file-earmark-excel"></i>Export Excel</a>
    @endif
    <a href="{{ route('maintenance.control-stroke-mesin') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    @if(auth()->user()->hasRole('admin'))
    <a href="{{ route('maintenance.control-stroke-mesin.schedule-pdf') }}" target="_blank" class="erp-tb-btn">
        <i class="bi bi-file-earmark-pdf"></i>Schedule PDF
    </a>
    <a href="{{ route('maintenance.control-stroke-mesin.schedule-edit') }}" class="erp-tb-btn">
        <i class="bi bi-calendar-week"></i>Edit Jadwal
    </a>
    @endif
    @if(auth()->user()->hasRole(['admin', 'staff_maintenance']))
    <form method="POST" action="{{ route('maintenance.control-stroke-mesin.schedule-generate') }}" style="display:contents"
          onsubmit="return confirm('Generate ulang jadwal preventive bulan ini? Jadwal lama akan ditimpa.');">
        @csrf
        <button type="submit" class="erp-tb-btn"><i class="bi bi-arrow-repeat"></i>Generate Ulang</button>
    </form>
    @endif
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- Menyaring di sisi klien atas data yang sudah dimuat. Opsi Find diisi
     initFilters() dari data itu sendiri — tak ada opsi yang hasilnya kosong. --}}
<x-filter.dock form-id="csForm" x-on:submit.prevent="bacaFilterCs()">
    <x-filter.grup judul="Find">
        <x-filter.teks id="csSearch" placeholder="Cari kode / nama mesin..." />
        <x-filter.cari name="mesin" id="cs_mesin" placeholder="Mesin" />
        <x-filter.cari name="tipe" id="cs_tipe" placeholder="Tipe Mesin" />
        <x-filter.cari name="line" id="cs_line" placeholder="Line" />
    </x-filter.grup>

    <x-filter.grup judul="Urutkan">
        <select id="csSort" name="sort" class="erp-in" aria-label="Urutkan">
            <option value="pct_desc">Stroke tertinggi ↓</option>
            <option value="pct_asc">Stroke terendah ↑</option>
            <option value="kode_asc">Kode mesin A–Z</option>
            <option value="nama_asc">Nama mesin A–Z</option>
        </select>
    </x-filter.grup>
</x-filter.dock>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">
@include('partials.status-muat', ['id' => 'an'])

{{-- KPI strip: klik sel = filter status. Klik sel yang sama lagi = balik ke semua. --}}
<div class="kpi-strip mb-2">
    <div class="kpi-cell active" data-status="" style="border-left-color:#6b7280" role="button" tabindex="0" aria-pressed="true">
        <i class="bi bi-cpu-fill" style="color:#6b7280"></i>
        <div><div class="kpi-num" id="kpiTotalMesin">0</div><div class="kpi-lbl">Total mesin</div></div>
    </div>
    <div class="kpi-cell" data-status="warning" style="border-left-color:#b45309" role="button" tabindex="0" aria-pressed="false">
        <i class="bi bi-exclamation-triangle-fill" style="color:#b45309"></i>
        <div><div class="kpi-num" id="kpiWarning" style="color:#b45309">0</div><div class="kpi-lbl">Perlu perhatian (&ge;80%)</div></div>
    </div>
    <div class="kpi-cell" data-status="overdue" style="border-left-color:#b91c1c" role="button" tabindex="0" aria-pressed="false">
        <i class="bi bi-exclamation-octagon-fill" style="color:#b91c1c"></i>
        <div><div class="kpi-num" id="kpiOverdue" style="color:#b91c1c">0</div><div class="kpi-lbl">Segera preventive (&ge;100%)</div></div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header d-flex justify-content-between align-items-center">
        <span>Detail per Mesin</span>
        <span class="text-muted small" id="csCount"></span>
    </div>
    <div class="erp-panel-body" style="padding:0">
        {{-- Kotak setinggi layar: tabel geser horizontal DI DALAM kotaknya sendiri, jadi
             scrollbar-nya tak terkubur di dasar tabel panjang. Header ringkas sekarang, jadi
             min-height 600px (thead + 8 baris) sesuai permintaan, offset 300px. Header ringkas
             (mulai y=241, dari 407) bikin scrollbar jauh lebih dekat dari sebelumnya; di layar
             tinggi (>900px) kotak melar via max-height dan scrollbar naik ke bidang pandang. --}}
        <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 300px);min-height:600px">
            <table class="erp-table" id="strokeTable">
                <thead style="position:sticky;top:0;z-index:10">
                    <tr>
                        <th style="width:48px">No.</th><th>Mesin</th><th>Nama Mesin</th><th>Tipe</th><th>Line</th>
                        <th style="min-width:240px">Stroke Saat Ini</th>
                        <th class="text-end">Running Time</th>
                        <th>Status</th><th>Preventive Terakhir</th><th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="10" class="text-center text-muted py-3">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>Control Stroke Mesin</span>
    <span>Target preventive <b>{{ number_format($threshold, 0, ',', '.') }}</b> stroke / mesin</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection

@push('scripts')
<script>
const canInputPreventive = {{ auth()->user()->hasRole(['admin', 'staff_maintenance']) ? 'true' : 'false' }};
const baseUrl = `{{ url('maintenance/availability/control-stroke-mesin') }}`;
const dataUrl = `{{ route('maintenance.control-stroke-mesin.data') }}`;
const COLSPAN = 10;

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmt(n) { return Number(n || 0).toLocaleString('id-ID'); }
// Running time: menit → jam desimal (1 angka di belakang koma). 0 → "—".
function fmtJam(menit) {
    const j = Number(menit || 0) / 60;
    if (j <= 0) return '<span class="text-muted">—</span>';
    return `${j.toLocaleString('id-ID', { maximumFractionDigits: 1 })} jam`;
}

const STATUS = {
    ok:      { label: 'OK',                badge: 'bg-success',           bar: 'bg-success', icon: 'bi-check-circle-fill',         color: '#15803d', tint: '' },
    warning: { label: 'Perlu Perhatian',   badge: 'bg-warning text-dark', bar: 'bg-warning', icon: 'bi-exclamation-triangle-fill', color: '#b45309', tint: '#fffbeb' },
    overdue: { label: 'Segera Preventive', badge: 'bg-danger',            bar: 'bg-danger',  icon: 'bi-exclamation-octagon-fill',  color: '#b91c1c', tint: '#fef2f2' },
};

let allRows = [];
const state = { status: '', q: '', mesin: '', tipe: '', line: '', sort: 'pct_desc' };

// bangun daftar opsi unik dari data yang dimuat → tak ada opsi kosong
function uniqueOpts(rows, valFn, labelFn) {
    const seen = new Map();
    rows.forEach(r => { const v = valFn(r); if (v != null && v !== '' && !seen.has(v)) seen.set(v, labelFn(r)); });
    return [...seen].map(([v, t]) => ({ v, t })).sort((a, b) => String(a.t).localeCompare(String(b.t)));
}

function initFilters() {
    Alpine.$data(document.getElementById('cs_mesin')).items = uniqueOpts(allRows, r => r.kode_mesin, r => `${r.kode_mesin} – ${r.nama_mesin}`);
    Alpine.$data(document.getElementById('cs_tipe')).items = uniqueOpts(allRows, r => r.tipe_mesin, r => r.tipe_mesin);
    Alpine.$data(document.getElementById('cs_line')).items = uniqueOpts(allRows, r => r.line, r => r.line);
}

/* Pemuat dibungkus muatDenganStatus (layout): overlay, galat, Coba lagi. */
function loadData() { return muatDenganStatus('an', loadDataInti); }

async function loadDataInti() {
    const tbody = document.getElementById('strokeTableBody');
    try {
        const res = await fetch(dataUrl);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const data = await res.json();
        allRows = data.rows || [];
    } catch (e) {
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="text-center text-danger py-3">Gagal memuat data — coba lagi.</td></tr>`;
        return;
    }
    // KPI dihitung dari SELURUH data, bukan yang sedang difilter.
    document.getElementById('kpiTotalMesin').textContent = fmt(allRows.length);
    document.getElementById('kpiOverdue').textContent    = fmt(allRows.filter(r => r.status === 'overdue').length);
    document.getElementById('kpiWarning').textContent    = fmt(allRows.filter(r => r.status === 'warning').length);
    initFilters();
    render();
}

function render() {
    const q = state.q.trim().toLowerCase();
    let rows = allRows.filter(r =>
        (!state.status || r.status === state.status) &&
        (!state.mesin  || r.kode_mesin === state.mesin) &&
        (!state.tipe   || r.tipe_mesin === state.tipe) &&
        (!state.line   || r.line === state.line) &&
        (!q || (`${r.kode_mesin} ${r.nama_mesin}`.toLowerCase().includes(q)))
    );

    const cmp = {
        pct_desc:  (a, b) => b.pct - a.pct,
        pct_asc:   (a, b) => a.pct - b.pct,
        kode_asc:  (a, b) => String(a.kode_mesin).localeCompare(String(b.kode_mesin)),
        nama_asc:  (a, b) => String(a.nama_mesin).localeCompare(String(b.nama_mesin)),
    }[state.sort];
    rows = rows.slice().sort(cmp);

    // KPI cell aktif
    document.querySelectorAll('.kpi-cell').forEach(c => {
        const on = (c.dataset.status || '') === state.status;
        c.classList.toggle('active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    // badge jumlah filter lanjutan + tombol reset

    // Tombol ekspor membawa state filter klien yang sama (lihat export() di controller).
    const exportBtn = document.getElementById('exportExcel');
    if (exportBtn) {
        const p = new URLSearchParams();
        ['status', 'q', 'sort', ...['mesin', 'tipe', 'line']].forEach(k => { if (state[k]) p.set(k, state[k]); });
        exportBtn.href = `{{ route('maintenance.control-stroke-mesin.export') }}?${p}`;
    }

    document.getElementById('csCount').textContent =
        rows.length === allRows.length ? `${allRows.length} mesin` : `${rows.length} dari ${allRows.length} mesin`;

    const tbody = document.getElementById('strokeTableBody');
    if (!allRows.length) {
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="text-center text-muted py-3">Tidak ada data.</td></tr>`;
        return;
    }
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="text-center text-muted py-3">Tidak ada mesin yang cocok dengan filter.</td></tr>`;
        return;
    }
    tbody.innerHTML = rows.map((r, i) => {
        const st      = STATUS[r.status] || STATUS.ok;
        const barPct  = Math.min(r.pct, 100);
        const sisa    = r.threshold - r.current_stroke;
        const sisaTxt = sisa >= 0
            ? `<span class="text-muted">sisa ${fmt(sisa)} stroke</span>`
            : `<span style="color:#b91c1c;font-weight:600">lewat ${fmt(-sisa)} stroke</span>`;
        const rowStyle = st.tint ? `style="background:${st.tint}"` : '';
        const lastPrev = r.last_preventive_date
            ? `${r.last_preventive_date} <span class="text-muted">· ${escHtml(r.last_preventive_pic) || '—'}</span>`
            : '<span class="text-muted">Belum pernah</span>';
        const prevBtn = canInputPreventive
            ? `<a href="${baseUrl}/${r.id_mesin}/preventive" class="btn btn-primary btn-sm"><i class="bi bi-tools me-1"></i>Preventive</a>`
            : `<button type="button" class="btn btn-primary btn-sm" disabled title="Hanya Maintenance yang bisa input"><i class="bi bi-tools me-1"></i>Preventive</button>`;
        return `<tr ${rowStyle}>
            <td class="text-muted">${i + 1}</td>
            <td style="font-weight:600">${escHtml(r.kode_mesin)}</td>
            <td>${escHtml(r.nama_mesin)}</td>
            <td>${escHtml(r.tipe_mesin) || '—'}</td>
            <td>${escHtml(r.line) || '—'}</td>
            <td>
                <div class="d-flex justify-content-between align-items-baseline mb-1">
                    <span><strong>${fmt(r.current_stroke)}</strong> <span class="text-muted">/ ${fmt(r.threshold)}</span></span>
                    <strong style="color:${st.color}">${r.pct}%</strong>
                </div>
                <div class="progress" style="height:8px;background:#e5e7eb;border-radius:4px">
                    <div class="progress-bar ${st.bar}" style="width:${barPct}%"></div>
                </div>
                <div style="font-size:11px;margin-top:3px">${sisaTxt}</div>
            </td>
            <td class="text-end" style="font-weight:600">${fmtJam(r.running_time_menit)}</td>
            <td><span class="badge ${st.badge}"><i class="bi ${st.icon} me-1"></i>${st.label}</span></td>
            <td>${lastPrev}</td>
            <td class="text-end">
                <div class="d-flex gap-1 justify-content-end">
                    ${prevBtn}
                    <a href="${baseUrl}/${r.id_mesin}/history" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Riwayat</a>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ── Wiring ──────────────────────────────────────────────────────────────
document.querySelectorAll('.kpi-cell').forEach(cell => {
    const toggle = () => {
        const s = cell.dataset.status || '';
        state.status = (state.status === s) ? '' : s;   // klik lagi = lepas filter
        render();
    };
    cell.addEventListener('click', toggle);
    cell.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
});

/* Dock baku: semua kontrol dibaca dari form. Ketik-cari langsung menyaring;
   pilihan typeahead memancarkan 'change' dari input hidden-nya. */
function bacaFilterCs() {
    const fd = new FormData(document.getElementById('csForm'));
    state.q = fd.get('q') || '';
    state.sort = fd.get('sort') || 'pct_desc';
    ['mesin', 'tipe', 'line'].forEach(k => { state[k] = fd.get(k) || ''; });
    render();
}
document.getElementById('csForm').addEventListener('input', bacaFilterCs);
document.getElementById('csForm').addEventListener('change', bacaFilterCs);

document.addEventListener('DOMContentLoaded', loadData);
</script>
@endpush
