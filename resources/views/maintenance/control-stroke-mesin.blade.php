@extends('layouts.app')
@section('title', 'Control Stroke Mesin')
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

    .cs-toolbar { display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
    .cs-toolbar .cs-search { flex:1 1 220px; min-width:180px; }
    .filter-badge { font-size:10px; }
</style>

<div class="d-flex justify-content-between align-items-center mb-2">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Mesin</h4>
    <div class="text-muted small">Target preventive: <strong>{{ number_format($threshold, 0, ',', '.') }} stroke</strong> / mesin</div>
</div>

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

{{-- Toolbar: search + sort selalu terlihat; filter lanjutan dilipat. --}}
<div class="cs-toolbar mb-2">
    <div class="cs-search">
        <div class="input-group input-group-sm">
            <span class="input-group-text"><i class="bi bi-search"></i></span>
            <input type="text" id="csSearch" class="form-control" placeholder="Cari kode / nama mesin...">
        </div>
    </div>
    <select id="csSort" class="form-select form-select-sm" style="width:auto">
        <option value="pct_desc">Stroke tertinggi &darr;</option>
        <option value="pct_asc">Stroke terendah &uarr;</option>
        <option value="kode_asc">Kode mesin A–Z</option>
        <option value="nama_asc">Nama mesin A–Z</option>
    </select>
    <button type="button" id="csFilterToggle" class="btn btn-outline-secondary btn-sm" aria-expanded="false">
        <i class="bi bi-sliders me-1"></i>Filter <span id="csFilterCount" class="badge bg-secondary filter-badge d-none">0</span>
    </button>
    <button type="button" id="csReset" class="btn btn-outline-secondary btn-sm d-none"><i class="bi bi-x-lg me-1"></i>Reset</button>
</div>

{{-- Panel filter lanjutan — tersembunyi sampai tombol Filter ditekan. --}}
<div id="csFilterPanel" class="erp-panel mb-2 d-none">
    <div class="card-body py-2">
        <div class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Tipe Mesin</label>
                <select id="csTipe" class="form-select form-select-sm">
                    <option value="">Semua Tipe</option>
                    @foreach($tipeList as $tipe)
                        <option value="{{ $tipe }}">{{ $tipe }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Line</label>
                <select id="csLine" class="form-select form-select-sm">
                    <option value="">Semua Line</option>
                    @foreach($lineList as $line)
                        <option value="{{ $line }}">{{ $line }}</option>
                    @endforeach
                </select>
            </div>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header d-flex justify-content-between align-items-center">
        <span>Detail per Mesin</span>
        <span class="text-muted small" id="csCount"></span>
    </div>
    <div class="erp-panel-body p-0">
        {{-- Kotak setinggi layar: tabel geser horizontal DI DALAM kotaknya sendiri, jadi
             scrollbar-nya tak terkubur di dasar tabel panjang. Header ringkas sekarang, jadi
             min-height 600px (thead + 8 baris) sesuai permintaan, offset 300px. Header ringkas
             (mulai y=241, dari 407) bikin scrollbar jauh lebih dekat dari sebelumnya; di layar
             tinggi (>900px) kotak melar via max-height dan scrollbar naik ke bidang pandang. --}}
        <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 300px);min-height:600px">
            <table class="erp-table" id="strokeTable">
                <thead style="position:sticky;top:0;z-index:10">
                    <tr>
                        <th>Mesin</th><th>Nama Mesin</th><th>Tipe</th><th>Line</th>
                        <th style="min-width:240px">Stroke Saat Ini</th>
                        <th>Status</th><th>Preventive Terakhir</th><th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="8" class="text-center text-muted py-3">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const canInputPreventive = {{ in_array(auth()->user()->role, ['admin', 'staff_maintenance']) ? 'true' : 'false' }};
const baseUrl = `{{ url('maintenance/availability/control-stroke-mesin') }}`;
const dataUrl = `{{ route('maintenance.control-stroke-mesin.data') }}`;
const COLSPAN = 8;

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmt(n) { return Number(n || 0).toLocaleString('id-ID'); }

const STATUS = {
    ok:      { label: 'OK',                badge: 'bg-success',           bar: 'bg-success', icon: 'bi-check-circle-fill',         color: '#15803d', tint: '' },
    warning: { label: 'Perlu Perhatian',   badge: 'bg-warning text-dark', bar: 'bg-warning', icon: 'bi-exclamation-triangle-fill', color: '#b45309', tint: '#fffbeb' },
    overdue: { label: 'Segera Preventive', badge: 'bg-danger',            bar: 'bg-danger',  icon: 'bi-exclamation-octagon-fill',  color: '#b91c1c', tint: '#fef2f2' },
};

let allRows = [];
const state = { status: '', q: '', tipe: '', line: '', sort: 'pct_desc' };

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
    // KPI dihitung dari SELURUH data, bukan yang sedang difilter.
    document.getElementById('kpiTotalMesin').textContent = fmt(allRows.length);
    document.getElementById('kpiOverdue').textContent    = fmt(allRows.filter(r => r.status === 'overdue').length);
    document.getElementById('kpiWarning').textContent    = fmt(allRows.filter(r => r.status === 'warning').length);
    render();
}

function render() {
    const q = state.q.trim().toLowerCase();
    let rows = allRows.filter(r =>
        (!state.status || r.status === state.status) &&
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
    const advCount = (state.tipe ? 1 : 0) + (state.line ? 1 : 0);
    const badge = document.getElementById('csFilterCount');
    badge.textContent = advCount;
    badge.classList.toggle('d-none', advCount === 0);
    const anyFilter = state.status || state.q || state.tipe || state.line;
    document.getElementById('csReset').classList.toggle('d-none', !anyFilter);

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
    tbody.innerHTML = rows.map(r => {
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

document.getElementById('csSearch').addEventListener('input', e => { state.q = e.target.value; render(); });
document.getElementById('csSort').addEventListener('change', e => { state.sort = e.target.value; render(); });
document.getElementById('csTipe').addEventListener('change', e => { state.tipe = e.target.value; render(); });
document.getElementById('csLine').addEventListener('change', e => { state.line = e.target.value; render(); });

document.getElementById('csFilterToggle').addEventListener('click', () => {
    const panel = document.getElementById('csFilterPanel');
    const btn = document.getElementById('csFilterToggle');
    const hidden = panel.classList.toggle('d-none');
    btn.setAttribute('aria-expanded', hidden ? 'false' : 'true');
});

document.getElementById('csReset').addEventListener('click', () => {
    state.status = ''; state.q = ''; state.tipe = ''; state.line = '';
    document.getElementById('csSearch').value = '';
    document.getElementById('csTipe').value = '';
    document.getElementById('csLine').value = '';
    render();
});

document.addEventListener('DOMContentLoaded', loadData);
</script>
@endpush
