@extends('layouts.app')
@section('title', 'Control Stroke Mesin')
@section('content')
<style>
    /* Kolom mengikuti panjang isinya; tabel geser horizontal di dalam kotaknya sendiri. */
    #strokeTable tbody td { white-space: nowrap; }
</style>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Mesin</h4>
    <div class="text-muted small">Target preventive: <strong>{{ number_format($threshold, 0, ',', '.') }} stroke</strong> / mesin</div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Tipe Mesin</label>
                <select id="tipe_mesin" name="tipe_mesin" class="form-select form-select-sm">
                    <option value="">Semua Tipe</option>
                    @foreach($tipeList as $tipe)
                        <option value="{{ $tipe }}">{{ $tipe }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="form-label small mb-1">Mesin</label>
                <div x-data="taField(taMesin)">
                    <div class="ta-wrap">
                        <input type="text" class="form-control form-control-sm ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Semua Mesin...">
                        <input type="hidden" id="mesin_id" name="mesin_id" :value="val ?? ''">
                        <div class="ta-dropdown" x-show="open" x-cloak>
                            <template x-if="filtered.length===0"><div class="ta-empty">Tidak ditemukan</div></template>
                            <template x-for="(item,i) in filtered" :key="item.v">
                                <div class="ta-item" :class="{'ta-active':i===idx}" @mousedown.prevent="pick(item)" x-text="item.t"></div>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="erp-panel h-100" style="border-left:4px solid #6b7280">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="bi bi-cpu-fill" style="font-size:1.9rem;color:#6b7280"></i>
                <div>
                    <div class="text-muted small">Total Mesin Dipantau</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1"><span id="kpiTotalMesin">0</span></div>
                    <div class="text-muted" style="font-size:11px">seluruh mesin terdaftar</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100" style="border-left:4px solid #b91c1c">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="bi bi-exclamation-octagon-fill" style="font-size:1.9rem;color:#b91c1c"></i>
                <div>
                    <div class="text-muted small">Segera Preventive</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1;color:#b91c1c"><span id="kpiOverdue">0</span></div>
                    <div class="text-muted" style="font-size:11px">sudah lewat batas {{ number_format($threshold, 0, ',', '.') }}</div>
                </div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100" style="border-left:4px solid #b45309">
            <div class="card-body py-3 d-flex align-items-center gap-3">
                <i class="bi bi-exclamation-triangle-fill" style="font-size:1.9rem;color:#b45309"></i>
                <div>
                    <div class="text-muted small">Mendekati Batas</div>
                    <div style="font-size:1.9rem;font-weight:700;line-height:1;color:#b45309"><span id="kpiWarning">0</span></div>
                    <div class="text-muted" style="font-size:11px">sudah &ge; 80% ({{ number_format($threshold * 0.8, 0, ',', '.') }} stroke)</div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body p-0">
        {{-- Kotak setinggi layar, bukan .table-responsive polos: tanpa batas tinggi, div-nya
             ikut setinggi tabel (24 mesin ≈ 1700px) sehingga scrollbar horizontal ada di
             dasar seluruh tabel — harus scroll ~1400px ke bawah dulu buat menggesernya.
             min-height 600px = thead (37px) + 8 baris (70px each) = 593, dibulatkan.
             Sebelumnya 200px: di layar 1366x600 cuma muat 2,4 baris — tabelnya jadi tak
             terpakai. Konten di atas tabel setinggi ~407px, jadi di layar sependek itu
             8 baris DAN scrollbar-nya mustahil terlihat bersamaan (407 + 593 = 1000 > 600);
             8 baris dipilih, scrollbar-nya beberapa ratus px di bawah — masih jauh lebih
             dekat daripada 1410px waktu kotaknya belum dibatasi sama sekali.

             max-height 420px = tinggi konten di atas + margin, biar di layar tinggi
             (>1020px) kotaknya melar dan scrollbar-nya tetap di layar. Jangan disalin
             angka 260px dari maintenance/breakdown — chrome halaman itu jauh lebih
             pendek (tabelnya mulai y=156, di sini y=407). --}}
        <div style="overflow-x:auto;overflow-y:auto;max-height:calc(100vh - 420px);min-height:600px">
            <table class="erp-table" id="strokeTable">
                {{-- Sticky: kotaknya sekarang scroll vertikal sendiri, header harus ikut terlihat. --}}
                <thead style="position:sticky;top:0;z-index:10">
                    <tr>
                        <th>Mesin</th><th>Nama Mesin</th><th>Tipe</th>
                        <th style="min-width:240px">Stroke Saat Ini</th>
                        <th>Status</th><th>Preventive Terakhir</th><th class="text-end">Aksi</th>
                    </tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="7" class="text-center text-muted py-3">Memuat data...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin]));
const canInputPreventive = {{ in_array(auth()->user()->role, ['admin', 'staff_maintenance']) ? 'true' : 'false' }};
const baseUrl = `{{ url('maintenance/availability/control-stroke-mesin') }}`;

function escHtml(str) {
    if (str === null || str === undefined) return '';
    return String(str).replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
}
function fmt(n) { return Number(n || 0).toLocaleString('id-ID'); }

function taField(items, initText = '', initVal = null) {
    return {
        items, query: initText, val: initVal, open: false, idx: -1,
        get filtered() {
            if (!this.query.trim()) return this.items.slice(0, 60);
            const q = this.query.toLowerCase();
            return this.items.filter(i => i.t.toLowerCase().includes(q)).slice(0, 60);
        },
        openDd()  { this.open = true; this.idx = -1; },
        closeDd() { setTimeout(() => { this.open = false; if (!this.val) this.query = ''; }, 180); },
        onInput() { this.val = null; this.open = true; this.idx = -1; },
        pick(item) { this.query = item.t; this.val = item.v; this.open = false; this.idx = -1; },
        onKey(e) {
            if      (e.key === 'ArrowDown') { e.preventDefault(); this.open = true; this.idx = Math.min(this.idx+1, this.filtered.length-1); }
            else if (e.key === 'ArrowUp')   { e.preventDefault(); this.idx = Math.max(this.idx-1, 0); }
            else if (e.key === 'Enter')     { e.preventDefault(); if (this.idx>=0 && this.filtered[this.idx]) this.pick(this.filtered[this.idx]); }
            else if (e.key === 'Escape')    { this.open = false; }
        },
    };
}

// warna & label per status — dipakai badge, teks %, bar, dan tint baris
const STATUS = {
    ok:      { label: 'OK',                badge: 'bg-success',           bar: 'bg-success', icon: 'bi-check-circle-fill',         color: '#15803d', tint: '' },
    warning: { label: 'Perlu Perhatian',   badge: 'bg-warning text-dark', bar: 'bg-warning', icon: 'bi-exclamation-triangle-fill', color: '#b45309', tint: '#fffbeb' },
    overdue: { label: 'Segera Preventive', badge: 'bg-danger',            bar: 'bg-danger',  icon: 'bi-exclamation-octagon-fill',  color: '#b91c1c', tint: '#fef2f2' },
};

let currentRows = [];

async function loadData() {
    const params = new URLSearchParams({
        mesin_id:   document.getElementById('mesin_id').value,
        tipe_mesin: document.getElementById('tipe_mesin').value,
    });
    const tbody = document.getElementById('strokeTableBody');
    let data;
    try {
        const res = await fetch(`{{ route('maintenance.control-stroke-mesin.data') }}?${params}`);
        if (!res.ok) throw new Error('HTTP ' + res.status);
        data = await res.json();
    } catch (e) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Gagal memuat data — coba lagi.</td></tr>';
        return;
    }
    currentRows = data.rows || [];

    document.getElementById('kpiTotalMesin').textContent = fmt(data.summary?.total_mesin ?? 0);
    document.getElementById('kpiOverdue').textContent    = fmt(data.summary?.overdue ?? 0);
    document.getElementById('kpiWarning').textContent    = fmt(data.summary?.warning ?? 0);

    if (!currentRows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = currentRows.map(r => {
        const st       = STATUS[r.status] || STATUS.ok;
        const barPct   = Math.min(r.pct, 100);
        const sisa     = r.threshold - r.current_stroke;
        const sisaTxt  = sisa >= 0
            ? `<span class="text-muted">sisa ${fmt(sisa)} stroke</span>`
            : `<span style="color:#b91c1c;font-weight:600">lewat ${fmt(-sisa)} stroke</span>`;
        const rowStyle = st.tint ? `style="background:${st.tint}"` : '';
        const lastPrev = r.last_preventive_date
            ? `${r.last_preventive_date} <span class="text-muted">· ${escHtml(r.last_preventive_pic) || '—'}</span>`
            : '<span class="text-muted">Belum pernah</span>';
        const prevBtn  = canInputPreventive
            ? `<a href="${baseUrl}/${r.id_mesin}/preventive" class="btn btn-primary btn-sm"><i class="bi bi-tools me-1"></i>Preventive</a>`
            : `<button type="button" class="btn btn-primary btn-sm" disabled title="Hanya Maintenance yang bisa input"><i class="bi bi-tools me-1"></i>Preventive</button>`;
        return `<tr ${rowStyle}>
            <td style="font-weight:600">${escHtml(r.kode_mesin)}</td>
            <td>${escHtml(r.nama_mesin)}</td>
            <td>${escHtml(r.tipe_mesin) || '—'}</td>
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

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });

document.addEventListener('DOMContentLoaded', loadData);
</script>
@endpush
