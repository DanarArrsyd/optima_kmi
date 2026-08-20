@extends('layouts.app')
@section('title', 'MTBF Mesin')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
{{-- ══ RUANG KERJA ERP ══════════════════════════════════════════
     Isinya KPI + grafik + tabel yang memang perlu digulung, jadi
     .erp-scroll dibiarkan menggulung tegak — bukan dikunci satu layar. --}}
<div class="erp-ws">

<div class="erp-tb">
    <button type="button" id="btnDock" class="erp-tb-btn is-on"
            onclick="erpToggleDock('anDock','btnDock')">
        <i class="bi bi-funnel"></i>Filter
    </button>
    <a href="{{ route('maintenance.mtbf') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

<aside class="erp-dock" id="anDock" style="display:none">
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" onclick="erpToggleDock('anDock','btnDock')" title="Tutup filter">&times;</button>
    </div>
    <form id="filterForm" class="erp-dock-body">
            <div class="erp-grp">
                <b>Tanggal Dari</b>
                <input type="date" id="date_from" name="date_from" class="erp-in" value="{{ date('Y-01-01') }}">
            </div>
            <div class="erp-grp">
                <b>Tanggal Sampai</b>
                <input type="date" id="date_to" name="date_to" class="erp-in" value="{{ date('Y-m-d') }}">
            </div>
            <div class="erp-grp">
                <b>Bulan</b>
                <select id="bulan" name="bulan" class="erp-in">
                    <option value="">Semua Bulan</option>
                    @php $namaBulan = [1=>'Januari','Februari','Maret','April','Mei','Juni','Juli','Agustus','September','Oktober','November','Desember']; $thn = date('Y'); @endphp
                    @foreach($namaBulan as $no => $nm)
                        <option value="{{ $thn }}-{{ sprintf('%02d', $no) }}">{{ $nm }} {{ $thn }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Tipe Mesin</b>
                <select id="tipe_mesin" name="tipe_mesin" class="erp-in">
                    <option value="">Semua Tipe</option>
                    @foreach($tipeList as $tipe)
                        <option value="{{ $tipe }}">{{ $tipe }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Mesin</b>
                <div x-data="taField(taMesin)">
                    <div class="ta-wrap">
                        <input type="text" class="erp-in ta-input"
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
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri"><i class="bi bi-search me-1"></i>Tampilkan</button>
            </div>
        </form>
</aside>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">
<div class="row g-3 mb-4">
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Rata-rata MTBF</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiMtbf">—</span> <span class="small text-muted">jam</span></div>
        </div></div>
    </div>
    <div class="col-md-6">
        <div class="erp-panel h-100"><div class="erp-panel-body" style="padding:10px">
            <div class="text-muted small">Total Breakdown</div>
            <div style="font-size:1.6rem;font-weight:700"><span id="kpiTotalBd">0</span></div>
        </div></div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-body" style="padding:12px"><canvas id="mtbfChart" height="100"></canvas></div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div class="erp-panel-body" style="padding:0">
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>Nama Mesin</th><th class="text-end">Breakdown</th><th class="text-end">MTBF (jam kerja)</th><th class="text-end">MTBF (hari kerja)</th></tr>
                </thead>
                <tbody id="mtbfTableBody">
                    <tr><td colspan="5" class="text-center text-muted py-3">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span>MTBF — Mean Time Between Failures (Mesin)</span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}
@endsection

@push('scripts')
<script>
/* Cegah stored XSS: data master (nama mesin, part, proses) bisa memuat markup. */

/* null ≠ 0 dan ≠ 100. Sejak penyebut Availability jadi jam TERJADWAL, nilainya
   bisa null saat rentangnya nol jam kerja (Minggu/libur). Tanpa penjaga ini sel
   tabel mencetak literal "null%" — bukan crash, tapi terbaca seperti data rusak
   padahal maksudnya "tidak ada jam kerja untuk dinilai". */
function fmtPct(v) { return v === null || v === undefined ? '—' : v + '%'; }
function fmtNum(v) { return v === null || v === undefined ? '—' : v; }

function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}
const taMesin = @json($mesinList->map(fn($m) => ['v' => $m->id, 't' => $m->id_mesin.' – '.$m->nama_mesin]));

/* taField() kini satu definisi di layouts/app.blade.php. */

let mtbfChart = null;

async function loadData() {
    const params = new URLSearchParams({
        date_from:  document.getElementById('date_from').value,
        date_to:    document.getElementById('date_to').value,
        bulan:      document.getElementById('bulan').value,
        tipe_mesin: document.getElementById('tipe_mesin').value,
        mesin_id:   document.getElementById('mesin_id').value,
    });
    const res  = await fetch(`{{ route('maintenance.mtbf.data') }}?${params}`);
    const data = await res.json();
    const rows = data.rows || [];

    document.getElementById('kpiMtbf').textContent    = data.summary?.avg_mtbf ?? '—';
    document.getElementById('kpiTotalBd').textContent = data.summary?.total_bd ?? rows.reduce((a,r)=>a+(r.count||0),0);

    if (mtbfChart) mtbfChart.destroy();
    mtbfChart = new Chart(document.getElementById('mtbfChart'), {
        type: 'bar',
        data: { labels: rows.map(r => r.kode_mesin), datasets: [{ label: 'MTBF (jam)', data: rows.map(r => r.mtbf_jam), backgroundColor: '#1f2937' }] },
        options: { responsive: true, plugins: { legend: { display: false }, datalabels: { display: true, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827', formatter: v => v } }, scales: { y: { beginAtZero: true } } }
    });

    const tbody = document.getElementById('mtbfTableBody');
    if (!rows.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
        return;
    }
    tbody.innerHTML = rows.map(r => {
        if (!r.instalasi_set) {
            return `<tr class="text-muted"><td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td><td class="text-end">${r.count}</td>
                <td class="text-end" colspan="2"><em>instalasi belum diset</em></td></tr>`;
        }
        return `<tr><td>${escHtml(r.kode_mesin)}</td><td>${escHtml(r.nama_mesin)}</td><td class="text-end">${r.count}</td>
            <td class="text-end"><strong>${fmtNum(r.mtbf_jam)}</strong></td><td class="text-end">${fmtNum(r.mtbf_hari)}</td></tr>`;
    }).join('');
}

document.getElementById('filterForm').addEventListener('submit', e => { e.preventDefault(); loadData(); });
loadData();
</script>
@endpush
