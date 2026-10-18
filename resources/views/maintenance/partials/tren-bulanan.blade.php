{{-- Panel KIRI halaman MTTR/MTBF Mesin & Dies: tren rata-rata metrik per bulan.
     Parameter: $judul (teks header panel).
     Klik satu titik bulan → grafik kanan + tabel + export memakai bulan itu saja;
     klik lagi / tombol "Seluruh periode" → kembali ke rentang filter.
     Tinggi dikunci di pembungkus, BUKAN atribut height canvas (lihat VcbGrafikUkuranTest). --}}
<div class="erp-panel h-100" style="margin:0">
    <div class="erp-panel-header">{{ $judul }}</div>
    <div class="erp-panel-body" style="padding:12px">
        <div style="position:relative;height:320px">
            <canvas id="trenChart"></canvas>
        </div>
        <div class="text-muted small mt-2">Klik titik bulan untuk melihat rincian bulan itu di grafik kanan &amp; tabel.</div>
    </div>
</div>

@push('scripts')
<script>
/* Tren bulanan + pilihan bulan. Instance Chart.js disimpan di closure ini, BUKAN
   di state Alpine (Proxy Alpine membuat Chart.js rekursi tanpa ujung).
   cfg: { url, metrik: 'mttr'|'mtbf', label, onPilih } */
function trenBulananMtc(cfg) {
    let chart = null;
    let tren = [];
    let pilih = null;

    function tandaiPeriode() {
        const b = pilih === null ? null : tren[pilih];
        const teks = document.getElementById('periodeKanan');
        const lepas = document.getElementById('periodeLepas');
        if (teks) teks.textContent = b ? b.label : 'Seluruh periode';
        if (lepas) lepas.style.display = b ? '' : 'none';
    }

    function warnai() {
        if (!chart) return;
        const ds = chart.data.datasets[0];
        ds.pointRadius = tren.map((_, i) => i === pilih ? 7 : 4);
        ds.pointBackgroundColor = tren.map((_, i) => i === pilih ? '#111827' : '#ffffff');
        chart.update('none');
    }

    function pilihBulan(i) {
        pilih = (i === pilih) ? null : i;
        warnai();
        tandaiPeriode();
        cfg.onPilih();
    }

    function gambar() {
        if (chart) chart.destroy();
        chart = new Chart(document.getElementById('trenChart'), {
            type: 'line',
            data: {
                labels: tren.map(t => t.label),
                datasets: [{
                    label: cfg.label,
                    data: tren.map(t => t[cfg.metrik]),
                    borderColor: '#1f2937', backgroundColor: '#1f2937',
                    pointBorderColor: '#1f2937', pointBorderWidth: 2,
                    pointRadius: 4, pointHoverRadius: 7, pointBackgroundColor: '#ffffff',
                    tension: 0.2,
                    /* Bulan tanpa angka (nol breakdown / instalasi belum diset) = celah, bukan 0. */
                    spanGaps: false,
                }],
            },
            options: {
                responsive: true, maintainAspectRatio: false,
                /* Label angka di titik pertama/terakhir jangan terpotong tepi kanvas. */
                layout: { padding: { top: 6, left: 12, right: 20 } },
                interaction: { mode: 'nearest', intersect: false, axis: 'x' },
                onClick: (e, els) => { if (els.length) pilihBulan(els[0].index); },
                onHover: (e, els) => { e.native.target.style.cursor = els.length ? 'pointer' : 'default'; },
                plugins: {
                    legend: { display: false },
                    tooltip: { callbacks: {
                        label: c => `${cfg.label}: ${c.raw ?? '—'}`,
                        afterLabel: c => `Breakdown: ${tren[c.dataIndex].total_bd}`,
                    } },
                    datalabels: { display: c => c.dataset.data[c.dataIndex] !== null, anchor: 'end', align: 'top', font: { size: 10, weight: 'bold' }, color: '#111827' },
                },
                scales: { y: { beginAtZero: true, grace: '10%' }, x: { grid: { display: false } } },
            },
        });
        warnai();
    }

    const lepas = document.getElementById('periodeLepas');
    if (lepas) lepas.addEventListener('click', () => { if (pilih !== null) pilihBulan(pilih); });

    return {
        /* Dipanggil SINKRON sebelum tabel dimuat ulang: kalau tidak, pemuat tabel
           yang jalan paralel masih membaca bulan pilihan filter sebelumnya. */
        reset() { pilih = null; tandaiPeriode(); },
        bulanTerpilih() { return pilih === null ? null : tren[pilih]; },
        async muat(params) {
            const res = await fetch(`${cfg.url}?${params}`);
            if (!res.ok) throw new Error('HTTP ' + res.status);
            tren = (await res.json()).tren || [];
            gambar();
        },
    };
}
</script>
@endpush
