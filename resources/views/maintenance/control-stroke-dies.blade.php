@extends('layouts.app')
@section('title', 'Control Stroke Dies')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
<style>
    #strokeTable tbody td { white-space: nowrap; }

    /* KPI strip ringkas — satu baris, tiap sel bisa diklik jadi filter status. */
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

    .cs-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .cs-toolbar .cs-search { flex:1 1 220px; min-width:180px; }
    .filter-badge { font-size:10px; }

</style>

{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════
     Toolbar pencarian, urutan, dan filter lanjutan dipindah UTUH ke dalam
     dock — id dan kelasnya tidak diubah sedikit pun supaya JS halaman ini
     tetap bekerja apa adanya. --}}
<div class="erp-ws">

<div class="erp-tb">
    <button type="button" id="btnDock" class="erp-tb-btn"
            onclick="erpToggleDock('csDock','btnDock')">
        <i class="bi bi-funnel"></i>Filter
    </button>
    <a href="{{ route('maintenance.control-stroke-dies') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <a href="{{ route('maintenance.control-stroke-dies.schedule-pdf') }}" target="_blank" class="erp-tb-btn">
        <i class="bi bi-file-earmark-pdf"></i>Schedule PDF
    </a>
    <a href="{{ route('maintenance.control-stroke-dies.schedule-edit') }}" class="erp-tb-btn">
        <i class="bi bi-calendar-week"></i>Edit Jadwal
    </a>
    @if(auth()->user()->hasRole(['admin', 'staff_maintenance']))
    <form method="POST" action="{{ route('maintenance.control-stroke-dies.schedule-generate') }}" style="display:contents"
          onsubmit="return confirm('Generate ulang jadwal preventive bulan ini? Jadwal lama akan ditimpa.');">
        @csrf
        <button type="submit" class="erp-tb-btn"><i class="bi bi-arrow-repeat"></i>Generate Ulang</button>
    </form>
    @endif
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" id="csDock" style="display:none">
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" onclick="erpToggleDock('csDock','btnDock')" title="Tutup filter">&times;</button>
    </div>
    <div class="erp-dock-body">
<div class="cs-toolbar">
    <div class="cs-search">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="csSearch" class="form-control" placeholder="Cari part no / nama dies / proses...">
        </div>
    </div>
    <select id="csSort" class="form-select form-select-sm" >
        <option value="pct_desc">Stroke tertinggi &darr;</option>
        <option value="pct_asc">Stroke terendah &uarr;</option>
        <option value="kode_asc">Part No A–Z</option>
        <option value="nama_asc">Nama dies A–Z</option>
    </select>
    <button type="button" id="csFilterToggle" class="btn btn-outline-secondary btn-sm" aria-expanded="false">
        <i class="bi bi-sliders me-1"></i>Filter <span id="csFilterCount" class="badge bg-secondary filter-badge d-none">0</span>
    </button>
    <button type="button" id="csReset" class="btn btn-outline-secondary btn-sm d-none"><i class="bi bi-x-lg me-1"></i>Reset</button>
</div>

<div id="csFilterPanel" class="d-none">
    <div style="padding:6px">
        <div class="cs-adv">
            <div class="erp-grp">
                <b>Part</b>
                <div class="cs-ta" data-key="part"><input type="text" class="form-control form-control-sm cs-ta-input" placeholder="Semua Part..." autocomplete="off"><div class="cs-ta-dd d-none"></div></div>
            </div>
            <div class="erp-grp">
                <b>Customer</b>
                <div class="cs-ta" data-key="customer"><input type="text" class="form-control form-control-sm cs-ta-input" placeholder="Semua Customer..." autocomplete="off"><div class="cs-ta-dd d-none"></div></div>
            </div>
            <div class="erp-grp">
                <b>Proses</b>
                <div class="cs-ta" data-key="proses"><input type="text" class="form-control form-control-sm cs-ta-input" placeholder="Semua Proses..." autocomplete="off"><div class="cs-ta-dd d-none"></div></div>
            </div>
        </div>
    </div>
</div>


    </div>
</aside>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">

<div class="kpi-strip mb-2">
    <div class="kpi-cell active" data-status="" style="border-left-color:#6b7280" role="button" tabindex="0" aria-pressed="true">
        <i class="bi bi-grid-3x3-gap-fill" style="color:#6b7280"></i>
        <div><div class="kpi-num" id="kpiTotalDies">0</div><div class="kpi-lbl">Total dies (punya produksi)</div></div>
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

{{-- Tiap filter typeahead (bisa diketik); opsinya diisi dari data yang dimuat. --}}
<div class="erp-panel">
    <div class="erp-panel-header d-flex justify-content-between align-items-center">
        <span>Detail per Dies</span>
        <span class="text-muted small" id="csCount"></span>
    </div>
    <div class="erp-panel-body" style="padding:0">
        {{-- Kotak setinggi layar: tabel geser horizontal di dalam kotaknya sendiri. Header
             ringkas sekarang, jadi min-height 600px (8 baris) + offset 300px. Header ringkas (y=241) bikin scrollbar jauh
             lebih dekat; layar tinggi (>900px) kotak melar. Dies lebih banyak dari mesin. --}}
        <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 300px);min-height:600px">
            <table class="erp-table" id="strokeTable">
                <thead style="position:sticky;top:0;z-index:10">
                    <tr>
                        <th style="width:48px">No.</th><th>Part No</th><th>Nama Dies</th><th>Customer</th><th>Proses</th>
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
    <span>Control Stroke Dies</span>
    <span>Target preventive <b>{{ number_format($threshold, 0, ',', '.') }}</b> stroke / dies</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection

@push('scripts')
@include('maintenance.partials.typeahead')
<script>
const canInputPreventive = {{ auth()->user()->hasRole(['admin', 'staff_maintenance']) ? 'true' : 'false' }};
const baseUrl = `{{ url('maintenance/availability/control-stroke-dies') }}`;
const dataUrl = `{{ route('maintenance.control-stroke-dies.data') }}`;
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
    ok:      { label: 'OK',                 badge: 'bg-success',           bar: 'bg-success', icon: 'bi-check-circle-fill',         color: '#15803d', tint: '' },
    warning: { label: 'Perlu Perhatian',    badge: 'bg-warning text-dark', bar: 'bg-warning', icon: 'bi-exclamation-triangle-fill', color: '#b45309', tint: '#fffbeb' },
    overdue: { label: 'Segera Preventive',  badge: 'bg-danger',            bar: 'bg-danger',  icon: 'bi-exclamation-octagon-fill',  color: '#b91c1c', tint: '#fef2f2' },
};

let allRows = [];
const state = { status: '', q: '', part: '', customer: '', proses: '', sort: 'pct_desc' };
const ta = {};

function uniqueOpts(rows, valFn, labelFn) {
    const seen = new Map();
    rows.forEach(r => { const v = valFn(r); if (v != null && v !== '' && !seen.has(v)) seen.set(v, labelFn(r)); });
    return [...seen].map(([v, t]) => ({ v, t })).sort((a, b) => String(a.t).localeCompare(String(b.t)));
}

function initFilters() {
    ta.part = csCombobox(document.querySelector('.cs-ta[data-key="part"]'),
        uniqueOpts(allRows, r => r.kode_mesin, r => `${r.kode_mesin} – ${r.nama_mesin}`),
        v => { state.part = v; render(); });
    ta.customer = csCombobox(document.querySelector('.cs-ta[data-key="customer"]'),
        uniqueOpts(allRows, r => r.customer, r => r.customer),
        v => { state.customer = v; render(); });
    ta.proses = csCombobox(document.querySelector('.cs-ta[data-key="proses"]'),
        uniqueOpts(allRows, r => r.nama_proses, r => r.nama_proses),
        v => { state.proses = v; render(); });
}

async function loadData() {
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
    document.getElementById('kpiTotalDies').textContent = fmt(allRows.length);
    document.getElementById('kpiOverdue').textContent   = fmt(allRows.filter(r => r.status === 'overdue').length);
    document.getElementById('kpiWarning').textContent   = fmt(allRows.filter(r => r.status === 'warning').length);
    initFilters();
    render();
}

function render() {
    const q = state.q.trim().toLowerCase();
    let rows = allRows.filter(r =>
        (!state.status   || r.status === state.status) &&
        (!state.part     || r.kode_mesin === state.part) &&
        (!state.customer || r.customer === state.customer) &&
        (!state.proses   || r.nama_proses === state.proses) &&
        (!q || (`${r.kode_mesin} ${r.nama_mesin} ${r.nama_proses}`.toLowerCase().includes(q)))
    );

    const cmp = {
        pct_desc: (a, b) => b.pct - a.pct,
        pct_asc:  (a, b) => a.pct - b.pct,
        kode_asc: (a, b) => String(a.kode_mesin).localeCompare(String(b.kode_mesin)),
        nama_asc: (a, b) => String(a.nama_mesin).localeCompare(String(b.nama_mesin)),
    }[state.sort];
    rows = rows.slice().sort(cmp);

    document.querySelectorAll('.kpi-cell').forEach(c => {
        const on = (c.dataset.status || '') === state.status;
        c.classList.toggle('active', on);
        c.setAttribute('aria-pressed', on ? 'true' : 'false');
    });

    const advCount = (state.part ? 1 : 0) + (state.customer ? 1 : 0) + (state.proses ? 1 : 0);
    const badge = document.getElementById('csFilterCount');
    badge.textContent = advCount;
    badge.classList.toggle('d-none', advCount === 0);
    const anyFilter = state.status || state.q || state.part || state.customer || state.proses;
    document.getElementById('csReset').classList.toggle('d-none', !anyFilter);

    document.getElementById('csCount').textContent =
        rows.length === allRows.length ? `${allRows.length} dies` : `${rows.length} dari ${allRows.length} dies`;

    const tbody = document.getElementById('strokeTableBody');
    if (!allRows.length) {
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="text-center text-muted py-3">Tidak ada data.</td></tr>`;
        return;
    }
    if (!rows.length) {
        tbody.innerHTML = `<tr><td colspan="${COLSPAN}" class="text-center text-muted py-3">Tidak ada dies yang cocok dengan filter.</td></tr>`;
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
            ? `<a href="${baseUrl}/${r.id_part_proses}/preventive" class="btn btn-primary btn-sm"><i class="bi bi-tools me-1"></i>Preventive</a>`
            : `<button type="button" class="btn btn-primary btn-sm" disabled title="Hanya Maintenance yang bisa input"><i class="bi bi-tools me-1"></i>Preventive</button>`;
        return `<tr ${rowStyle}>
            <td class="text-muted">${i + 1}</td>
            <td style="font-weight:600">${escHtml(r.kode_mesin)}</td>
            <td>${escHtml(r.nama_mesin)}</td>
            <td>${escHtml(r.customer) || '—'}</td>
            <td>${escHtml(r.nama_proses)}</td>
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
                    <a href="${baseUrl}/${r.id_part_proses}/history" class="btn btn-outline-secondary btn-sm"><i class="bi bi-clock-history me-1"></i>Riwayat</a>
                </div>
            </td>
        </tr>`;
    }).join('');
}

// ── Wiring ──────────────────────────────────────────────────────────────
document.querySelectorAll('.kpi-cell').forEach(cell => {
    const toggle = () => {
        const s = cell.dataset.status || '';
        state.status = (state.status === s) ? '' : s;
        render();
    };
    cell.addEventListener('click', toggle);
    cell.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); toggle(); } });
});

document.getElementById('csSearch').addEventListener('input', e => { state.q = e.target.value; render(); });
document.getElementById('csSort').addEventListener('change', e => { state.sort = e.target.value; render(); });
// Filter part/customer/proses = combobox typeahead (di-wire lewat onSelect di initFilters()).

document.getElementById('csFilterToggle').addEventListener('click', () => {
    const panel = document.getElementById('csFilterPanel');
    const btn = document.getElementById('csFilterToggle');
    const hidden = panel.classList.toggle('d-none');
    btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
});

document.getElementById('csReset').addEventListener('click', () => {
    state.status = ''; state.q = ''; state.part = ''; state.customer = ''; state.proses = '';
    document.getElementById('csSearch').value = '';
    ta.part?.reset(); ta.customer?.reset(); ta.proses?.reset();
    render();
});

document.addEventListener('DOMContentLoaded', loadData);
</script>
@endpush
