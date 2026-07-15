@extends('layouts.app')
@section('title', 'Control Stroke Dies')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Dies</h4>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-4">
                <label class="form-label small mb-1">Customer</label>
                <div x-data="taField(taCustomer)">
                    <div class="ta-wrap">
                        <input type="text" class="form-control form-control-sm ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Semua Customer...">
                        <input type="hidden" id="customer" name="customer" :value="val ?? ''">
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
                <label class="form-label small mb-1">Part No</label>
                <div x-data="taField(taPart)">
                    <div class="ta-wrap">
                        <input type="text" class="form-control form-control-sm ta-input"
                               x-model="query" @focus="openDd()" @blur="closeDd()" @keydown="onKey($event)" @input="onInput()"
                               placeholder="Semua Dies...">
                        <input type="hidden" id="part_id" name="part_id" :value="val ?? ''">
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
        <div class="erp-panel h-100"><div class="card-body py-3">
            <div class="text-muted small">Total Dies Dipantau</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalDies">0</span></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100"><div class="card-body py-3">
            <div class="text-muted small">Segera Preventive</div>
            <div style="font-size:1.6rem;font-weight:700;color:#b91c1c"><span id="kpiOverdue">0</span></div>
        </div></div>
    </div>
    <div class="col-md-4">
        <div class="erp-panel h-100"><div class="card-body py-3">
            <div class="text-muted small">Mendekati Batas</div>
            <div style="font-size:1.6rem;font-weight:700;color:#b45309"><span id="kpiWarning">0</span></div>
        </div></div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Dies</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th>Part No</th><th>Nama Dies</th><th>Proses</th>
                        <th style="min-width:220px">Stroke Saat Ini</th>
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

{{-- Modal: Input Preventive --}}
<div class="modal fade" id="preventiveModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Input Preventive — <span id="pvDiesLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="preventiveForm">
                <div class="modal-body">
                    <div class="alert alert-warning py-2 small" id="pvResetPreview"></div>
                    <div class="mb-3">
                        <label class="form-label">Tanggal Preventive <span class="text-danger">*</span></label>
                        <input type="date" id="pv_tanggal" name="tanggal_preventive" class="form-control" required>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">PIC / Petugas <span class="text-danger">*</span></label>
                        <select id="pv_karyawan" name="id_karyawan" class="form-select" required>
                            <option value="">-- Pilih Petugas --</option>
                            @foreach($karyawanList as $k)
                                <option value="{{ $k->id }}">{{ $k->nama_karyawan }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Catatan</label>
                        <textarea id="pv_catatan" name="catatan" class="form-control" rows="3" placeholder="Opsional"></textarea>
                    </div>
                    <div id="pvError" class="text-danger small" style="display:none"></div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Batal</button>
                    <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-save me-1"></i>Simpan Preventive</button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- Modal: History --}}
<div class="modal fade" id="historyModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Riwayat Preventive — <span id="hDiesLabel"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table class="erp-table">
                        <thead>
                            <tr><th>Tanggal</th><th>PIC</th><th class="text-end">Stroke Tercapai</th><th>Catatan</th><th>Diinput Oleh</th></tr>
                        </thead>
                        <tbody id="historyTableBody">
                            <tr><td colspan="5" class="text-center text-muted py-3">Memuat...</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Tutup</button>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taCustomer = @json($customers->map(fn($c) => ['v' => $c, 't' => $c]));
const taPart = @json($partList->map(fn($m) => ['v' => $m->id, 't' => $m->part_no.' – '.$m->part_name]));
const csrfToken = document.querySelector('meta[name="csrf-token"]').content;
const canInputPreventive = {{ in_array(auth()->user()->role, ['admin', 'staff_maintenance']) ? 'true' : 'false' }};

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

const STATUS_LABEL = { ok: 'OK', warning: 'Perlu Perhatian', overdue: 'SEGERA PREVENTIVE' };
const STATUS_BADGE = { ok: 'bg-success', warning: 'bg-warning text-dark', overdue: 'bg-danger' };
const STATUS_BAR   = { ok: 'bg-success', warning: 'bg-warning', overdue: 'bg-danger' };

let currentRows = [];
let preventiveModal, historyModal;

async function loadData() {
    const params = new URLSearchParams({
        part_id:  document.getElementById('part_id').value,
        customer: document.getElementById('customer').value,
    });
    const res  = await fetch(`{{ route('maintenance.control-stroke-dies.data') }}?${params}`);
    const data = await res.json();
    currentRows = data.rows || [];

    document.getElementById('kpiTotalDies').textContent = data.summary?.total_dies ?? 0;
    document.getElementById('kpiOverdue').textContent   = data.summary?.overdue ?? 0;
    document.getElementById('kpiWarning').textContent   = data.summary?.warning ?? 0;

    const tbody = document.getElementById('strokeTableBody');
    if (!currentRows.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = currentRows.map(r => {
        const pct        = Math.min(r.pct, 100);
        const rowClass   = r.status === 'overdue' ? 'table-danger' : '';
        const lastPrev   = r.last_preventive_date ? `${r.last_preventive_date} — ${r.last_preventive_pic ?? '—'}` : 'Belum pernah';
        const disabledAttr = canInputPreventive ? '' : 'disabled title="Hanya Maintenance"';
        return `<tr class="${rowClass}">
            <td>${r.kode_mesin}</td><td>${r.nama_mesin}</td><td>${r.nama_proses}</td>
            <td>
                <div class="progress" style="height:20px">
                    <div class="progress-bar ${STATUS_BAR[r.status]}" style="width:${pct}%">
                        ${Number(r.current_stroke).toLocaleString()} / ${Number(r.threshold).toLocaleString()} (${r.pct}%)
                    </div>
                </div>
            </td>
            <td><span class="badge ${STATUS_BADGE[r.status]}">${STATUS_LABEL[r.status]}</span></td>
            <td>${lastPrev}</td>
            <td class="text-end">
                <button type="button" class="btn btn-primary btn-sm" ${disabledAttr} onclick="openPreventiveModal(${r.id_part_proses})"><i class="bi bi-tools"></i></button>
                <button type="button" class="btn btn-outline-secondary btn-sm" onclick="openHistoryModal(${r.id_part_proses})"><i class="bi bi-clock-history"></i></button>
            </td>
        </tr>`;
    }).join('');
}

function openPreventiveModal(idPartProses) {
    const row = currentRows.find(r => r.id_part_proses === idPartProses);
    if (!row) return;
    document.getElementById('preventiveForm').dataset.idPartProses = idPartProses;
    document.getElementById('pvDiesLabel').textContent = `${row.kode_mesin} — ${row.nama_proses}`;
    document.getElementById('pvResetPreview').textContent = `Stroke akan direset dari ${Number(row.current_stroke).toLocaleString()} ke 0.`;
    document.getElementById('pv_tanggal').value    = new Date().toISOString().slice(0, 10);
    document.getElementById('pv_tanggal').max      = new Date().toISOString().slice(0, 10);
    document.getElementById('pv_karyawan').value   = '';
    document.getElementById('pv_catatan').value    = '';
    document.getElementById('pvError').style.display = 'none';
    preventiveModal.show();
}

async function openHistoryModal(idPartProses) {
    const row = currentRows.find(r => r.id_part_proses === idPartProses);
    document.getElementById('hDiesLabel').textContent = row ? `${row.kode_mesin} — ${row.nama_proses}` : '';
    const tbody = document.getElementById('historyTableBody');
    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Memuat...</td></tr>';
    historyModal.show();

    const url = `{{ url('maintenance/availability/control-stroke-dies') }}/${idPartProses}/history`;
    const res  = await fetch(url);
    const data = await res.json();
    const logs = data.logs || [];

    if (!logs.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Belum ada riwayat preventive.</td></tr>';
        return;
    }
    tbody.innerHTML = logs.map(l => `<tr>
        <td>${l.tanggal_preventive}</td><td>${l.pic ?? '—'}</td>
        <td class="text-end">${Number(l.stroke_at_preventive).toLocaleString()}</td>
        <td>${l.catatan ?? '—'}</td><td>${l.input_by ?? '—'}</td>
    </tr>`).join('');
}

document.getElementById('preventiveForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    const idPartProses = e.target.dataset.idPartProses;
    const errorEl = document.getElementById('pvError');
    errorEl.style.display = 'none';

    const url = `{{ url('maintenance/availability/control-stroke-dies') }}/${idPartProses}/preventive`;
    const res = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrfToken, 'Accept': 'application/json' },
        body: JSON.stringify({
            tanggal_preventive: document.getElementById('pv_tanggal').value,
            id_karyawan:        document.getElementById('pv_karyawan').value,
            catatan:            document.getElementById('pv_catatan').value,
        }),
    });

    if (res.ok) {
        preventiveModal.hide();
        loadData();
        return;
    }
    const err = await res.json().catch(() => ({}));
    errorEl.textContent = err.message || 'Gagal menyimpan preventive.';
    errorEl.style.display = 'block';
});

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });

document.addEventListener('DOMContentLoaded', () => {
    preventiveModal = new bootstrap.Modal(document.getElementById('preventiveModal'));
    historyModal    = new bootstrap.Modal(document.getElementById('historyModal'));
    loadData();
});
</script>
@endpush
