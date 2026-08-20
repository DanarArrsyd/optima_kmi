@extends('layouts.app')
@section('title', 'VCB Quality')
{{-- Ruang kerja ERP mengelola tingginya sendiri sampai tepi layar. --}}
@section('main_mod', 'erp-flush')

@section('content')
{{-- ══════════════════════════════════════════════════════════════
     Ruang kerja ERP. Berbeda dari halaman input: isinya KPI + grafik +
     tabel yang memang perlu digulung, jadi .erp-scroll dibiarkan
     menggulung tegak — bukan dikunci satu layar.
══════════════════════════════════════════════════════════════ --}}
<div class="erp-ws">

<div class="erp-tb">
    <button type="button" id="tabAll" class="erp-tb-btn is-on" onclick="switchMode('all')">
        <i class="bi bi-people"></i>Semua Inspector
    </button>
    <button type="button" id="tabOne" class="erp-tb-btn" onclick="switchMode('one')">
        <i class="bi bi-person"></i>Per Inspector
    </button>
    <button type="button" id="btnDock" class="erp-tb-btn" onclick="erpToggleDock('vcbDock','btnDock')">
        <i class="bi bi-funnel"></i>Filter
    </button>
    <span class="erp-tb-sp"></span>
</div>

<div class="erp-ws-body">

{{-- Dock mulai tertutup; cakupan data yang sedang dilihat tetap
     terbaca di status bar, jadi tidak ada yang tersembunyi diam-diam. --}}
<aside class="erp-dock" id="vcbDock" style="display:none">
    <div class="erp-dock-hd">
        <span>Filter</span>
        <button type="button" class="erp-dock-x" onclick="erpToggleDock('vcbDock','btnDock')" title="Tutup filter">&times;</button>
    </div>
    <div class="erp-dock-body">
        <div id="dockAll">
    <form id="formAll" class="erp-dock-form">
            <div class="erp-grp">
                <b>Dari Tanggal</b>
                <input type="date" id="all_date_from" class="erp-in" value="{{ date('Y-m-01') }}">
            </div>
            <div class="erp-grp">
                <b>Sampai Tanggal</b>
                <input type="date" id="all_date_to" class="erp-in" value="{{ date('Y-m-d') }}">
            </div>
            <div class="erp-grp">
                <b>Bulan</b>
                <input type="month" id="all_bulan_filter" class="erp-in">
            </div>
            <div class="erp-grp">
                <b>Shift</b>
                <select id="all_shift_id" class="erp-in">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri">
                    <i class="bi bi-funnel me-1"></i>Tampilkan
                </button>
            </div>
        </form>
        </div>
        <div id="dockOne" style="display:none">
    <form id="formOne" class="erp-dock-form">
            <div class="erp-grp">
                <b>Dari Tanggal</b>
                <input type="date" id="one_date_from" class="erp-in" value="{{ date('Y-m-01') }}">
            </div>
            <div class="erp-grp">
                <b>Sampai Tanggal</b>
                <input type="date" id="one_date_to" class="erp-in" value="{{ date('Y-m-d') }}">
            </div>
            <div class="erp-grp">
                <b>Bulan</b>
                <input type="month" id="one_bulan_filter" class="erp-in">
            </div>
            <div class="erp-grp">
                <b>Inspector <span class="text-danger">*</span></b>
                <select id="one_karyawan_id" class="erp-in">
                    <option value="">— Pilih Inspector —</option>
                    @foreach($karyawan as $k)
                        <option value="{{ $k->id }}">{{ $k->nama_karyawan }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <b>Shift</b>
                <select id="one_shift_id" class="erp-in">
                    <option value="">Semua Shift</option>
                    @foreach($shifts as $s)
                        <option value="{{ $s->id }}">{{ $s->nama_shift }}</option>
                    @endforeach
                </select>
            </div>
            <div class="erp-grp">
                <button type="submit" class="erp-btn is-pri">
                    <i class="bi bi-search me-1"></i>Search
                </button>
                @if(! auth()->user()->isGuest())
                <a id="oneExportBtn" href="#" class="erp-btn">
                    <i class="bi bi-file-earmark-excel me-1"></i>Export
                </a>
                @endif
            </div>
        </form>
        </div>
    </div>
</aside>

<div class="erp-ws-main">
<div class="erp-scroll erp-scroll-pad">

<div id="modeAll">


    <div id="allKpi" style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px"></div>

    <div class="erp-panel mb-3" id="allChartPanel" style="display:none">
        <div class="erp-panel-header">
            <i class="bi bi-graph-up me-1"></i>Achievement % per Inspector
            <span style="margin-left:auto;font-size:10px;font-weight:400;color:#9ca3af">— — —  = 100% target</span>
        </div>
        <div class="erp-panel-body" style="padding:14px">
            <canvas id="allChart" height="70"></canvas>
        </div>
    </div>

    <div class="erp-panel" id="allTablePanel">
        <div class="erp-panel-header"><i class="bi bi-list-ol me-1"></i>Ranking Achievement Inspector</div>
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr>
                        <th style="width:36px;text-align:center">No</th>
                        <th style="min-width:140px">Inspector</th>
                        <th style="width:50px;text-align:center">Sesi</th>
                        <th style="width:80px;text-align:right">W. Kerja</th>
                        <th style="width:90px;text-align:right">Target QTY</th>
                        <th style="width:90px;text-align:right">Qty OK</th>
                        <th style="width:90px;text-align:right">Qty NG</th>
                        <th style="width:60px;text-align:right">NG%</th>
                        <th style="width:75px;text-align:right">SPH Std</th>
                        <th style="width:75px;text-align:right">SPH Act</th>
                        <th style="min-width:200px">Achievement</th>
                        <th style="width:75px;text-align:center">Status</th>
                    </tr>
                </thead>
                <tbody id="allTableBody">
                    <tr><td colspan="12" style="text-align:center;color:#888;padding:24px">Klik <strong>Tampilkan</strong> untuk melihat data.</td></tr>
                </tbody>
                <tfoot id="allTableFoot" style="display:none">
                    <tr style="background:#e5e7eb;font-weight:700;font-size:11.5px">
                        <td colspan="3" style="padding:7px 10px;color:#374151;text-transform:uppercase;letter-spacing:0.4px">Total</td>
                        <td style="text-align:right;padding:7px 10px" id="ftAllDur"></td>
                        <td style="text-align:right;padding:7px 10px" id="ftAllTgt"></td>
                        <td style="text-align:right;padding:7px 10px" id="ftAllOk"></td>
                        <td style="text-align:right;padding:7px 10px" id="ftAllNg"></td>
                        <td style="text-align:right;padding:7px 10px" id="ftAllNgRate"></td>
                        <td colspan="2" style="padding:7px 10px"></td>
                        <td id="ftAllPct" style="padding:7px 10px"></td>
                        <td style="padding:7px 10px"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

</div>{{-- /modeAll --}}
<div id="modeOne" style="display:none">


    <div id="oneEmpty" class="erp-panel" style="text-align:center;padding:40px;color:#9ca3af">
        <i class="bi bi-person-badge" style="font-size:32px;display:block;margin-bottom:10px;color:#d1d5db"></i>
        Pilih inspector dan klik <strong style="color:#4b5563">Search</strong> untuk melihat detail achievement harian.
    </div>

    <div class="erp-panel mb-3" id="oneChartPanel" style="display:none">
        <div class="erp-panel-header" style="justify-content:center;font-size:12px;letter-spacing:1px">
            ACHIEVEMENT — <span id="oneChartLabel" style="color:#111827">—</span>
            <span style="margin-left:auto;font-size:10px;font-weight:400;color:#9ca3af">— — —  = 100% target</span>
        </div>
        <div class="erp-panel-body" style="padding:14px">
            <canvas id="oneChart" height="75"></canvas>
        </div>
    </div>

    <div class="erp-panel" id="oneDetailPanel" style="display:none">
        <div class="erp-panel-header">
            <i class="bi bi-table me-1"></i>Detail Harian
            <span id="oneDetailLabel" style="margin-left:6px;font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0;font-size:11px"></span>
            <span style="margin-left:auto;font-size:10px;font-weight:400;color:#9ca3af;text-transform:none;letter-spacing:0">Klik tanggal untuk expand/collapse</span>
        </div>
        <div style="overflow-x:auto">
            <table class="erp-table" style="min-width:1020px">
                <thead>
                    <tr>
                        <th style="width:80px">Tanggal</th>
                        <th style="width:75px">Customer</th>
                        <th style="width:95px">Part No.</th>
                        <th style="width:145px">Part Name</th>
                        <th style="width:48px;text-align:center">ID<br>Proses</th>
                        <th style="width:110px">Proses</th>
                        <th style="width:70px;text-align:right">W. Kerja</th>
                        <th style="width:75px;text-align:right">Qty OK</th>
                        <th style="width:75px;text-align:right">Qty NG</th>
                        <th style="width:55px;text-align:right">NG%</th>
                        <th style="width:72px;text-align:right">SPH Std</th>
                        <th style="width:72px;text-align:right">SPH Act</th>
                        <th style="width:62px;text-align:right">%</th>
                        <th style="width:40px;text-align:center"><i class="bi bi-chevron-expand" style="font-size:10px"></i></th>
                    </tr>
                </thead>
                <tbody id="oneBody"></tbody>
            </table>
        </div>
    </div>

</div>{{-- /modeOne --}}
</div>{{-- /erp-scroll --}}
</div>{{-- /erp-ws-main --}}
</div>{{-- /erp-ws-body --}}

<div class="erp-status">
    <span id="allPeriodLabel"></span>
    <span id="onePeriodLabel" style="display:none"></span>
    <span class="sp"></span>
    <span>Login as {{ auth()->user()->name }}</span>
</div>

</div>{{-- /erp-ws --}}

@endsection

@push('scripts')
<script>
/* Cegah stored XSS: data master (nama mesin, part, proses, karyawan) bisa memuat markup. */
function escHtml(str) {
    if (str === null || str === undefined) return "";
    return String(str).replace(/[&<>"']/g, c => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "'": "&#39;" }[c]));
}
/* ═══════════════════════════════════════════════════════════
   OPTIMA — VCB Quality Controller
═══════════════════════════════════════════════════════════ */

let allChart = null;
let oneChart = null;

/* ── Helpers ───────────────────────────────────────────── */
function n(v, dec = 0) {
    return Number(v).toLocaleString('id-ID', { maximumFractionDigits: dec });
}
function pctColor(pct) {
    return pct >= 100 ? '#15803d' : pct >= 85 ? '#a16207' : '#b91c1c';
}
function ngColor(pct) {
    // NG rate: low is good (green), high is bad (red)
    return pct === 0 ? '#15803d' : pct <= 2 ? '#a16207' : '#b91c1c';
}
function statusLabel(tl) {
    const map = { success:['ACHIEVED','#15803d'], warning:['NEAR','#a16207'], danger:['BELOW','#b91c1c'] };
    const [label, color] = map[tl] ?? ['—','#9ca3af'];
    return `<span style="font-size:10px;font-weight:700;color:${color};letter-spacing:0.4px">${label}</span>`;
}
function progressBar(pct) {
    const color = pctColor(pct);
    return `<div style="display:flex;align-items:center;gap:8px">
        <div style="flex:1;height:7px;background:#e5e7eb;border-radius:1px;overflow:hidden">
            <div style="width:${Math.min(pct,100)}%;height:100%;background:${color}"></div>
        </div>
        <span style="font-size:12px;font-weight:700;color:${color};min-width:44px;text-align:right">${pct}%</span>
    </div>`;
}

/* ── Mode switcher ─────────────────────────────────────── */
function switchMode(mode, karyawanId = null) {
    const isAll = mode === 'all';
    document.getElementById('modeAll').style.display = isAll ? '' : 'none';
    document.getElementById('modeOne').style.display = isAll ? 'none' : '';
    /* Tab dan isi dock ikut mode. Warnanya tidak lagi ditulis inline —
       kelas .is-on milik toolbar yang mengurusnya. */
    document.getElementById('tabAll').classList.toggle('is-on', isAll);
    document.getElementById('tabOne').classList.toggle('is-on', !isAll);
    document.getElementById('dockAll').style.display = isAll ? '' : 'none';
    document.getElementById('dockOne').style.display = isAll ? 'none' : '';
    document.getElementById('allPeriodLabel').style.display = isAll ? '' : 'none';
    document.getElementById('onePeriodLabel').style.display = isAll ? 'none' : '';

    if (!isAll && karyawanId) {
        document.getElementById('one_karyawan_id').value = karyawanId;
        document.getElementById('one_date_from').value   = document.getElementById('all_date_from').value;
        document.getElementById('one_date_to').value     = document.getElementById('all_date_to').value;
        document.getElementById('one_shift_id').value    = document.getElementById('all_shift_id').value;
        loadOneInspector();
    }
}

/* ═══ MODE 1 ════════════════════════════════════════════ */
function buildAllChart(labels, achievements, trafficLights) {
    if (allChart) allChart.destroy();
    const colors = trafficLights.map(tl =>
        tl === 'success' ? '#15803d' : tl === 'warning' ? '#a16207' : '#b91c1c'
    );
    const maxY = Math.max(110, ...achievements.map(v => Math.ceil(v/10)*10+10));

    allChart = new Chart(document.getElementById('allChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: achievements,
                backgroundColor: colors.map(c => c + '22'),
                borderColor: colors,
                borderWidth: 1.5,
                borderRadius: 1,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: {
                    callbacks: { label: ctx => ` ${ctx.parsed.y}%` },
                    bodyFont: { family:'Arial', size:12, weight:'700' },
                    titleFont: { family:'Arial', size:11 },
                }
            },
            scales: {
                y: {
                    beginAtZero: true, max: maxY,
                    ticks: { callback: v => v + '%', font:{ family:'Arial', size:10 }, color:'#6b7280' },
                    grid: { color:'#f0f0f0' }, border: { color:'#d1d5db' },
                },
                x: {
                    ticks: { font:{ family:'Arial', size:11, weight:'600' }, color:'#374151' },
                    grid: { display:false }, border: { color:'#d1d5db' },
                }
            }
        },
        plugins: [{
            id: 'dataLabels',
            afterDatasetsDraw(chart) {
                const { ctx } = chart;
                chart.data.datasets.forEach((ds, di) => {
                    chart.getDatasetMeta(di).data.forEach((bar, i) => {
                        ctx.save();
                        ctx.font = '700 11px Arial';
                        ctx.fillStyle = ds.borderColor[i];
                        ctx.textAlign = 'center';
                        ctx.fillText(ds.data[i] + '%', bar.x, bar.y - 5);
                        ctx.restore();
                    });
                });
            }
        }, {
            id: 'refLine',
            afterDraw(chart) {
                const { ctx, chartArea, scales } = chart;
                const y = scales.y.getPixelForValue(100);
                if (y < chartArea.top || y > chartArea.bottom) return;
                ctx.save();
                ctx.setLineDash([6,4]); ctx.strokeStyle='#374151'; ctx.lineWidth=1;
                ctx.beginPath(); ctx.moveTo(chartArea.left,y); ctx.lineTo(chartArea.right,y); ctx.stroke();
                ctx.setLineDash([]);
                ctx.font='600 10px Arial'; ctx.fillStyle='#374151';
                ctx.fillText('100%', chartArea.right+4, y+4);
                ctx.restore();
            }
        }]
    });
}

function renderAllKpi(table) {
    const totalOk     = table.reduce((s,r) => s + r.total_qty_ok, 0);
    const totalNg     = table.reduce((s,r) => s + r.total_qty_ng, 0);
    const totalCheck  = totalOk + totalNg;
    const totalTarget = table.reduce((s,r) => s + r.total_target_qty, 0);
    const totalHours  = table.reduce((s,r) => s + r.total_duration_hours, 0);
    const overallPct  = totalTarget > 0 ? Math.round(totalCheck / totalTarget * 1000) / 10 : 0;
    const ngRate      = totalCheck > 0 ? Math.round(totalNg / totalCheck * 1000) / 10 : 0;
    const achieved    = table.filter(r => r.traffic_light === 'success').length;
    const pctC        = pctColor(overallPct);
    const ngC         = ngColor(ngRate);

    const cards = [
        { label:'Total Inspector',     value: table.length,          sub: `${achieved} achieved`,                    icon:'bi-people',        color:'#111827' },
        { label:'Total Qty Checked',   value: n(totalCheck),         sub: `OK: ${n(totalOk)} / NG: ${n(totalNg)}`,  icon:'bi-clipboard-check', color:'#111827' },
        { label:'Overall Achievement', value: overallPct + '%',      sub: overallPct >= 100 ? 'On Target' : `Kurang ${(100-overallPct).toFixed(1)}%`, icon:'bi-graph-up-arrow', color: pctC },
        { label:'NG Rate',             value: ngRate + '%',          sub: `${n(totalNg)} pcs NG`,                   icon:'bi-exclamation-triangle', color: ngC },
    ];
    document.getElementById('allKpi').innerHTML = cards.map(c => `
        <div style="background:#fff;border:1px solid #d1d5db;border-radius:2px;padding:12px 14px">
            <div style="font-size:9.5px;font-weight:700;color:#6b7280;text-transform:uppercase;letter-spacing:1px;margin-bottom:6px">
                <i class="bi ${c.icon} me-1"></i>${c.label}
            </div>
            <div style="font-size:22px;font-weight:700;color:${c.color};line-height:1.1;margin-bottom:3px">${c.value}</div>
            <div style="font-size:10.5px;color:#9ca3af">${c.sub}</div>
        </div>`).join('');
}

function renderAllTable(table) {
    const tbody = document.getElementById('allTableBody');
    const tfoot = document.getElementById('allTableFoot');

    if (!table.length) {
        tbody.innerHTML = '<tr><td colspan="12" style="text-align:center;color:#888;padding:24px">Tidak ada data pada rentang tanggal ini.</td></tr>';
        tfoot.style.display = 'none';
        return;
    }

    tbody.innerHTML = table.map((row, i) => {
        const sessions   = row.detail ? row.detail.length : '—';
        const sphStdAvg  = row.total_duration_hours > 0 ? n(Math.round(row.total_target_qty / row.total_duration_hours)) : '—';
        const sphActColor= row.avg_actual_sph >= (row.total_duration_hours > 0 ? row.total_target_qty / row.total_duration_hours : 0)
            ? '#15803d' : '#b91c1c';
        const ngC        = ngColor(row.ng_rate ?? 0);
        const rowBg      = i % 2 === 1 ? 'background:#f9fafb' : '';

        return `<tr style="${rowBg};cursor:pointer" onclick="switchMode('one', ${row.id_karyawan_qc ?? ''})"
                    title="Klik untuk detail ${escHtml(row.karyawan)}">
            <td style="text-align:center;color:#9ca3af;font-size:11px">${i+1}</td>
            <td style="font-weight:600;color:#111827">
                ${escHtml(row.karyawan)}
                <i class="bi bi-box-arrow-in-right" style="font-size:10px;color:#9ca3af;margin-left:4px"></i>
            </td>
            <td style="text-align:center;color:#6b7280">${sessions}</td>
            <td style="text-align:right;color:#6b7280">${row.total_duration_hours}j</td>
            <td style="text-align:right;color:#6b7280">${n(Math.round(row.total_target_qty))}</td>
            <td style="text-align:right;font-weight:600;color:#15803d">${n(row.total_qty_ok)}</td>
            <td style="text-align:right;font-weight:600;color:#b91c1c">${n(row.total_qty_ng)}</td>
            <td style="text-align:right;font-weight:700;color:${ngC}">${row.ng_rate ?? 0}%</td>
            <td style="text-align:right;color:#6b7280">${sphStdAvg}</td>
            <td style="text-align:right;font-weight:600;color:${sphActColor}">${n(Math.round(row.avg_actual_sph))}</td>
            <td>${progressBar(row.achievement_pct)}</td>
            <td style="text-align:center">${statusLabel(row.traffic_light)}</td>
        </tr>`;
    }).join('');

    // Footer
    const totalDur   = table.reduce((s,r) => s + r.total_duration_hours, 0);
    const totalTgt   = table.reduce((s,r) => s + r.total_target_qty, 0);
    const totalOk    = table.reduce((s,r) => s + r.total_qty_ok, 0);
    const totalNg    = table.reduce((s,r) => s + r.total_qty_ng, 0);
    const totalCheck = totalOk + totalNg;
    const oPct       = totalTgt > 0 ? Math.round(totalCheck / totalTgt * 1000) / 10 : 0;
    const oNgRate    = totalCheck > 0 ? Math.round(totalNg / totalCheck * 1000) / 10 : 0;

    document.getElementById('ftAllDur').textContent    = totalDur.toFixed(1) + 'j';
    document.getElementById('ftAllTgt').textContent    = n(Math.round(totalTgt));
    document.getElementById('ftAllOk').innerHTML       = `<span style="color:#15803d">${n(totalOk)}</span>`;
    document.getElementById('ftAllNg').innerHTML       = `<span style="color:#b91c1c">${n(totalNg)}</span>`;
    document.getElementById('ftAllNgRate').innerHTML   = `<span style="color:${ngColor(oNgRate)};font-weight:700">${oNgRate}%</span>`;
    document.getElementById('ftAllPct').innerHTML      = progressBar(oPct);
    tfoot.style.display = '';
}

async function loadAllInspectors() {
    const params = new URLSearchParams({
        date_from: document.getElementById('all_date_from').value,
        date_to:   document.getElementById('all_date_to').value,
        shift_id:  document.getElementById('all_shift_id').value,
    });
    document.getElementById('allPeriodLabel').textContent =
        document.getElementById('all_date_from').value + ' — ' + document.getElementById('all_date_to').value;

    try {
        const res  = await fetch(`{{ route('vcb.quality.data') }}?${params}`);
        const data = await res.json();
        const sorted = [...data.table].sort((a,b) => b.achievement_pct - a.achievement_pct);

        if (sorted.length) {
            renderAllKpi(sorted);
            document.getElementById('allChartPanel').style.display = '';
            buildAllChart(sorted.map(r=>r.karyawan), sorted.map(r=>r.achievement_pct), sorted.map(r=>r.traffic_light));
        } else {
            document.getElementById('allKpi').innerHTML = '';
            document.getElementById('allChartPanel').style.display = 'none';
        }
        renderAllTable(sorted);
    } catch(err) { console.error('loadAllInspectors error:', err); }
}

document.getElementById('formAll').addEventListener('submit', e => { e.preventDefault(); loadAllInspectors(); });

function applyBulanFilter(bulanId, fromId, toId, reloadFn) {
    const v = document.getElementById(bulanId).value;
    if (!v) return;
    const [y, m] = v.split('-').map(Number);
    const lastDay = new Date(y, m, 0).getDate();
    const today = new Date().toISOString().slice(0, 10);
    const to = `${v}-${String(lastDay).padStart(2, '0')}`;
    document.getElementById(fromId).value = `${v}-01`;
    document.getElementById(toId).value   = to > today ? today : to;
    reloadFn();
}
document.getElementById('all_bulan_filter').addEventListener('change', () =>
    applyBulanFilter('all_bulan_filter', 'all_date_from', 'all_date_to', loadAllInspectors));

/* ═══ MODE 2 ════════════════════════════════════════════ */
function buildOneChart(labels, achievements, colors) {
    if (oneChart) oneChart.destroy();
    const maxY = Math.max(110, ...achievements.map(v => Math.ceil(v/10)*10+10));

    oneChart = new Chart(document.getElementById('oneChart'), {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                data: achievements,
                backgroundColor: colors.map(c => c + '22'),
                borderColor: colors,
                borderWidth: 1.5,
                borderRadius: 1,
                borderSkipped: false,
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: { display: false },
                tooltip: { callbacks: { label: ctx => ` ${ctx.parsed.y}%` }, bodyFont:{family:'Arial',size:12,weight:'700'}, titleFont:{family:'Arial',size:11} }
            },
            scales: {
                y: { beginAtZero:true, max:maxY, ticks:{callback:v=>v+'%',font:{family:'Arial',size:10},color:'#6b7280'}, grid:{color:'#f0f0f0'}, border:{color:'#d1d5db'} },
                x: { ticks:{font:{family:'Arial',size:11,weight:'600'},color:'#374151'}, grid:{display:false}, border:{color:'#d1d5db'} }
            }
        },
        plugins: [{
            id:'dataLabels',
            afterDatasetsDraw(chart) {
                const {ctx} = chart;
                chart.data.datasets.forEach((ds,di) => {
                    chart.getDatasetMeta(di).data.forEach((bar,i) => {
                        ctx.save(); ctx.font='700 11px Arial'; ctx.fillStyle=ds.borderColor[i]; ctx.textAlign='center';
                        ctx.fillText(ds.data[i]+'%', bar.x, bar.y-5); ctx.restore();
                    });
                });
            }
        },{
            id:'refLine',
            afterDraw(chart) {
                const {ctx,chartArea,scales} = chart;
                const y = scales.y.getPixelForValue(100);
                if (y < chartArea.top || y > chartArea.bottom) return;
                ctx.save(); ctx.setLineDash([6,4]); ctx.strokeStyle='#374151'; ctx.lineWidth=1;
                ctx.beginPath(); ctx.moveTo(chartArea.left,y); ctx.lineTo(chartArea.right,y); ctx.stroke();
                ctx.setLineDash([]); ctx.font='600 10px Arial'; ctx.fillStyle='#374151';
                ctx.fillText('100%', chartArea.right+4, y+4); ctx.restore();
            }
        }]
    });
}

function buildOneRows(table) {
    const tbody = document.getElementById('oneBody');
    let html = '';

    table.forEach((day, di) => {
        const color   = pctColor(day.achievement_pct);
        const ngC     = ngColor(day.ng_rate ?? 0);
        const expanded = di === 0;

        // Date header row
        html += `
        <tr style="background:#f3f4f6;cursor:pointer" onclick="toggleDay(${di})">
            <td style="font-weight:700;font-size:12px;padding:8px 10px;color:#111827">
                <i class="bi bi-chevron-right" id="icon-${di}"
                   style="font-size:10px;color:#6b7280;transition:transform 0.15s;margin-right:5px;${expanded?'transform:rotate(90deg)':''}"></i>${day.tanggal}
            </td>
            <td colspan="4" style="padding:8px 10px;color:#6b7280;font-size:11px">${day.sessions.length} sesi pengecekan</td>
            <td style="padding:8px 10px;text-align:right;color:#15803d;font-weight:600">${n(day.total_qty_ok)}</td>
            <td style="padding:8px 10px;text-align:right;color:#b91c1c;font-weight:600">${n(day.total_qty_ng)}</td>
            <td style="padding:8px 10px;text-align:right;font-weight:700;color:${ngC}">${day.ng_rate ?? 0}%</td>
            <td style="padding:8px 10px;text-align:right;color:#6b7280;font-weight:600">${n(day.avg_sph_std)}</td>
            <td style="padding:8px 10px;text-align:right;font-weight:700;color:${color}">${n(day.avg_sph_act)}</td>
            <td style="padding:8px 10px;text-align:right;font-weight:700;color:${color}">${day.achievement_pct}%</td>
            <td></td>
        </tr>`;

        // Session rows
        day.sessions.forEach((s, si) => {
            const sc  = pctColor(s.pct);
            const snc = ngColor(s.ng_rate ?? 0);
            const bg  = si % 2 === 0 ? '#fff' : '#f9fafb';
            html += `
            <tr class="day-rows-${di}" style="display:${expanded?'table-row':'none'};background:${bg}">
                <td style="padding:6px 10px;padding-left:22px;color:#9ca3af;font-size:11px">↳</td>
                <td style="padding:6px 10px;font-size:11px;color:#6b7280">${escHtml(s.customer)}</td>
                <td style="padding:6px 10px;font-size:11.5px">${escHtml(s.part_no)}</td>
                <td style="padding:6px 10px;font-size:11.5px">${escHtml(s.part_name)}</td>
                <td style="padding:6px 10px;text-align:center;font-weight:600">${escHtml(s.id_proses)}</td>
                <td style="padding:6px 10px;font-size:11.5px">${escHtml(s.nama_proses)}</td>
                <td style="padding:6px 10px;text-align:right;color:#6b7280">${s.waktu_kerja}j</td>
                <td style="padding:6px 10px;text-align:right;font-weight:600;color:#15803d">${n(s.qty_ok)}</td>
                <td style="padding:6px 10px;text-align:right;font-weight:600;color:#b91c1c">${n(s.qty_ng)}</td>
                <td style="padding:6px 10px;text-align:right;font-weight:700;color:${snc}">${s.ng_rate}%</td>
                <td style="padding:6px 10px;text-align:right;color:#6b7280">${n(s.sph_std)}</td>
                <td style="padding:6px 10px;text-align:right;font-weight:600;color:${sc}">${n(s.sph_act)}</td>
                <td style="padding:6px 10px;text-align:right;font-weight:700;color:${sc}">${s.pct}%</td>
                <td></td>
            </tr>`;
        });

        // Average row
        html += `
        <tr class="day-rows-${di}" style="display:${expanded?'table-row':'none'};background:#f0f4f8;border-top:1px solid #d1d5db">
            <td colspan="5" style="padding:6px 10px 6px 22px;font-size:11px;font-weight:700;color:#374151;text-transform:uppercase;letter-spacing:0.5px">
                Average ${day.tanggal}
            </td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:#15803d">${n(day.total_qty_ok)}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:#b91c1c">${n(day.total_qty_ng)}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${ngC}">${day.ng_rate ?? 0}%</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:#374151">${n(day.avg_sph_std)}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${color}">${n(day.avg_sph_act)}</td>
            <td style="padding:6px 10px;text-align:right;font-weight:700;color:${color}">${day.achievement_pct}%</td>
            <td></td>
        </tr>`;
    });

    tbody.innerHTML = html;
}

function toggleDay(di) {
    const rows = document.querySelectorAll(`.day-rows-${di}`);
    const icon = document.getElementById(`icon-${di}`);
    const isOpen = rows.length && rows[0].style.display !== 'none';
    rows.forEach(r => r.style.display = isOpen ? 'none' : 'table-row');
    icon.style.transform = isOpen ? '' : 'rotate(90deg)';
}

async function loadOneInspector() {
    const karyawanId = document.getElementById('one_karyawan_id').value;
    if (!karyawanId) { alert('Pilih inspector terlebih dahulu.'); return; }

    const params = new URLSearchParams({
        date_from:   document.getElementById('one_date_from').value,
        date_to:     document.getElementById('one_date_to').value,
        karyawan_id: karyawanId,
        shift_id:    document.getElementById('one_shift_id').value,
    });
    // Tombol export tidak dirender untuk role guest.
    const exportBtn = document.getElementById('oneExportBtn');
    if (exportBtn) exportBtn.href = `/export/qc?${params}&format=excel`;

    try {
        const res  = await fetch(`{{ route('vcb.quality.operator-data') }}?${params}`);
        const data = await res.json();
        if (data.error) { alert(data.error); return; }

        document.getElementById('oneEmpty').style.display       = 'none';
        document.getElementById('oneChartPanel').style.display  = '';
        document.getElementById('oneDetailPanel').style.display = '';
        document.getElementById('oneChartLabel').textContent    = data.inspector.toUpperCase();
        document.getElementById('oneDetailLabel').textContent   = `— ${data.inspector}`;

        buildOneChart(data.labels, data.achievements, data.colors);
        buildOneRows(data.table);

    } catch(err) { console.error('loadOneInspector error:', err); }
}

document.getElementById('formOne').addEventListener('submit', e => { e.preventDefault(); loadOneInspector(); });
document.getElementById('one_bulan_filter').addEventListener('change', () =>
    applyBulanFilter('one_bulan_filter', 'one_date_from', 'one_date_to', loadOneInspector));

/* ── Init ──────────────────────────────────────────────── */
loadAllInspectors();
</script>
@endpush
