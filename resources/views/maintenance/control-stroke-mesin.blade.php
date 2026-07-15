@extends('layouts.app')
@section('title', 'Control Stroke Mesin')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-arrow-repeat me-2"></i>Control Stroke Mesin</h4>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <form id="filterForm" class="row g-2 align-items-end">
            <div class="col-md-2">
                <label class="form-label small mb-1">Tanggal Dari</label>
                <input type="date" id="date_from" name="date_from" class="form-control form-control-sm" value="{{ date('Y-01-01') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tanggal Sampai</label>
                <input type="date" id="date_to" name="date_to" class="form-control form-control-sm" value="{{ date('Y-m-d') }}">
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Bulan</label>
                <select id="bulan" name="bulan" class="form-select form-select-sm">
                    <option value="">Semua Bulan</option>
                    @php $namaBulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']; $thn = date('Y'); @endphp
                    @foreach($namaBulan as $no => $nm)
                        <option value="{{ $thn }}-{{ sprintf('%02d', $no) }}">{{ $nm }} {{ $thn }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <label class="form-label small mb-1">Tipe Mesin</label>
                <select id="tipe_mesin" name="tipe_mesin" class="form-select form-select-sm">
                    <option value="">Semua Tipe</option>
                    @foreach($tipeList as $tipe)
                        <option value="{{ $tipe }}">{{ $tipe }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
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
            <div class="col-md-2">
                <button type="submit" class="btn btn-primary btn-sm w-100"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body py-3">
        <div class="text-muted small">Total Stroke</div>
        <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalStroke">0</span></div>
    </div>
</div>

<div class="erp-panel mb-4">
    <div class="card-body"><canvas id="strokeChart" height="100"></canvas></div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body p-0">
        <div class="table-responsive">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>Nama Mesin</th><th class="text-end">Total Stroke</th></tr>
                </thead>
                <tbody id="strokeTableBody">
                    <tr><td colspan="3" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin]));

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

let strokeChart = null;

async function loadData() {
    const params = new URLSearchParams({
        date_from:  document.getElementById('date_from').value,
        date_to:    document.getElementById('date_to').value,
        bulan:      document.getElementById('bulan').value,
        tipe_mesin: document.getElementById('tipe_mesin').value,
        mesin_id:   document.getElementById('mesin_id').value,
    });
    const res  = await fetch(`{{ route('maintenance.control-stroke-mesin.data') }}?${params}`);
    const data = await res.json();
    const rows = data.rows || [];

    document.getElementById('kpiTotalStroke').textContent = (data.summary?.total_stroke ?? 0).toLocaleString();

    if (strokeChart) strokeChart.destroy();
    strokeChart = new Chart(document.getElementById('strokeChart'), {
        type: 'bar',
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'Total Stroke', data: rows.map(r => r.total_stroke), backgroundColor: '#1f2937' }] },
        options: { responsive: true, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v.toLocaleString() } }, scales: { y: { beginAtZero: true } } }
    });

    const tbody = document.getElementById('strokeTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="3" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => `<tr>
        <td>${r.kode_mesin}</td><td>${r.nama_mesin}</td>
        <td class="text-end"><strong>${Number(r.total_stroke).toLocaleString()}</strong></td>
    </tr>`).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
