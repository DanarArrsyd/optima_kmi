# Loading State VCB Mesin/Produksi/Quality Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tambah spinner overlay + error banner + disable-tombol selama fetch di 3 halaman VCB (Mesin, Produksi, Quality) yang saat ini nol feedback visual pas loading data.

**Architecture:** Vanilla DOM manipulation (bukan Alpine) — CSS `.erp-loading-overlay` baru di layout, overlay+banner markup disisipkan per halaman, tiap fungsi `loadData()`/`loadAllOperators()`/dst dibungkus `try/catch/finally` yang toggle overlay/banner/tombol.

**Tech Stack:** Laravel 13 (PHP 8.4 CI/prod), Bootstrap 5.3.3 (`spinner-border`), vanilla JS, PHPUnit (SQLite in-memory).

## Global Constraints

- CI & prod jalan PHP 8.4 — jangan pakai sintaks yang cuma ada di PHP 8.5.
- Pint scope cuma `app/` + `tests/` — jalankan `vendor/bin/pint app tests` sebelum commit.
- **Tidak ada migrasi baru.**
- `php artisan test` jalan di SQLite in-memory.
- Warna spinner monokrom `#374151` (BUKAN warna default Bootstrap biru) — tema industrial monochrome proyek ini.
- Backdrop overlay `rgba(255,255,255,.75)`, `z-index:5`.
- Error banner teks persis: `"Gagal memuat data — coba lagi."`, class `text-danger` (konvensi sudah dipakai `vcb/ng.blade.php`).
- Bukan Alpine — 3 halaman ini pakai vanilla `document.getElementById`/`fetch`, overlay di-toggle lewat `style.display`, BUKAN `x-show`.
- Isi logika fetch/render yang SUDAH ADA di tiap fungsi TIDAK BOLEH diubah — cuma dibungkus try/catch/finally baru.

---

### Task 1: CSS shared + VCB Mesin

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (CSS baru)
- Modify: `resources/views/vcb/mesin.blade.php:68-96` (wrap overlay+banner), `:122-223` (`loadData()`)
- Test: Create `tests/Feature/Vcb/VcbLoadingStateTest.php`

**Interfaces:**
- Produces: CSS class `.erp-loading-overlay` (+ `.erp-loading-overlay .spinner-border`) di layout — dikonsumsi Task 2 & 3 (nol perlu ubah layout lagi).

- [ ] **Step 1: Write the failing test**

Buat `tests/Feature/Vcb/VcbLoadingStateTest.php`:

```php
<?php

namespace Tests\Feature\Vcb;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spinner overlay + error banner pas fetch data di VCB Mesin, VCB Produksi
 * (Mode 1 & 2), VCB Quality (Mode 1 & 2) — 3 halaman paling rame yang
 * sebelumnya NOL feedback visual pas loading (UI diem/data basi).
 */
class VcbLoadingStateTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    private function html(string $route): string
    {
        $html = $this->actingAs($this->admin)->get(route($route))->assertOk()->getContent();

        return preg_replace('/\s+/', ' ', $html);
    }

    /** Ambil badan satu fungsi JS supaya asersi tidak menyentuh sisa halaman. */
    private function fungsiJs(string $html, string $nama): string
    {
        $awal = strpos($html, 'function '.$nama.'(');
        $this->assertNotFalse($awal, "Fungsi {$nama}() tidak ditemukan di halaman.");

        return substr($html, $awal, strpos($html, 'function ', $awal + 1) - $awal);
    }

    public function test_vcb_mesin_markup_overlay_dan_banner_ada(): void
    {
        $html = $this->html('vcb.mesin');

        $this->assertStringContainsString('id="mesinLoadingOverlay"', $html);
        $this->assertStringContainsString('erp-loading-overlay', $html);
        $this->assertStringContainsString('spinner-border', $html);
        $this->assertStringContainsString('id="mesinLoadError"', $html);
        $this->assertStringContainsString('Gagal memuat data — coba lagi.', $html);
    }

    public function test_vcb_mesin_load_data_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.mesin'), 'loadData');

        $this->assertStringContainsString("mesinLoadingOverlay", $fn);
        $this->assertStringContainsString("overlay.style.display = 'flex'", $fn);
        $this->assertStringContainsString("submitBtn.disabled = true", $fn);
        $this->assertStringContainsString('catch (e)', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
        $this->assertStringContainsString("overlay.style.display = 'none'", $fn);
        $this->assertStringContainsString('submitBtn.disabled = false', $fn);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=VcbLoadingStateTest`
Expected: FAIL — kedua test gagal, markup/JS baru belum ada.

- [ ] **Step 3: Implement — CSS layout & VCB Mesin**

**3a.** Di `resources/views/layouts/app.blade.php`, cari blok `<style>` yang berisi definisi `.erp-panel` (cari string `.erp-panel {`). Sisipkan CSS baru PERSIS SETELAH blok `.erp-panel` (sebelum aturan CSS berikutnya):

```css
.erp-loading-overlay {
    position: absolute;
    inset: 0;
    display: none;
    align-items: center;
    justify-content: center;
    background: rgba(255, 255, 255, .75);
    z-index: 5;
}
.erp-loading-overlay .spinner-border {
    color: #374151;
    width: 2.5rem;
    height: 2.5rem;
}
```

**3b.** Di `resources/views/vcb/mesin.blade.php`, ganti baris 68-96 (dua `.erp-panel` — chart & tabel) dari:

```blade
<div class="erp-panel mb-3">
    <div class="erp-panel-header">Achievement Mesin &mdash; Actual vs Target QTY</div>
    <div class="erp-panel-body">
        {{-- Tinggi dikunci di pembungkus, sama seperti grafik VCB lain
             (lihat feedback proxy Chart.js/Alpine). Nama mesin ditempel
             di dalam batang Actual (dataset 0) — garis Target (dataset 1)
             nol pengaruh ke plugin ini, cuma baca dataset pertama. --}}
        <div style="position:relative;height:340px">
            <canvas id="vcbChart"></canvas>
        </div>
        <div style="font-size:11px;color:var(--text-3);border-top:1px solid var(--border);margin-top:10px;padding-top:8px">
            Batang = Actual QTY (biru = lebihi target, hijau = capai target, merah = di bawah target). Garis abu-abu = Target QTY.
        </div>
    </div>
</div>

<div class="erp-panel">
    <div class="erp-panel-header">Detail per Mesin</div>
    <div style="overflow-x:auto">
        <table class="erp-table">
            <thead>
                <tr><th>Mesin</th><th>ID</th><th>Line</th><th>Tipe</th><th>Target QTY</th><th>Actual QTY</th><th>Duration (jam)</th><th>Achievement %</th><th>Avg SPH</th></tr>
            </thead>
            <tbody id="vcbTableBody">
                <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:20px">Klik Tampilkan untuk melihat data.</td></tr>
            </tbody>
        </table>
    </div>
</div>
```

jadi (dibungkus wrapper `position:relative` baru berisi overlay + error banner):

```blade
<div style="position:relative">
    <div class="erp-loading-overlay" id="mesinLoadingOverlay">
        <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
    </div>
    <div class="text-danger mb-3" id="mesinLoadError" style="display:none">
        Gagal memuat data — coba lagi.
    </div>

    <div class="erp-panel mb-3">
        <div class="erp-panel-header">Achievement Mesin &mdash; Actual vs Target QTY</div>
        <div class="erp-panel-body">
            {{-- Tinggi dikunci di pembungkus, sama seperti grafik VCB lain
                 (lihat feedback proxy Chart.js/Alpine). Nama mesin ditempel
                 di dalam batang Actual (dataset 0) — garis Target (dataset 1)
                 nol pengaruh ke plugin ini, cuma baca dataset pertama. --}}
            <div style="position:relative;height:340px">
                <canvas id="vcbChart"></canvas>
            </div>
            <div style="font-size:11px;color:var(--text-3);border-top:1px solid var(--border);margin-top:10px;padding-top:8px">
                Batang = Actual QTY (biru = lebihi target, hijau = capai target, merah = di bawah target). Garis abu-abu = Target QTY.
            </div>
        </div>
    </div>

    <div class="erp-panel">
        <div class="erp-panel-header">Detail per Mesin</div>
        <div style="overflow-x:auto">
            <table class="erp-table">
                <thead>
                    <tr><th>Mesin</th><th>ID</th><th>Line</th><th>Tipe</th><th>Target QTY</th><th>Actual QTY</th><th>Duration (jam)</th><th>Achievement %</th><th>Avg SPH</th></tr>
                </thead>
                <tbody id="vcbTableBody">
                    <tr><td colspan="9" style="text-align:center;color:#9ca3af;padding:20px">Klik Tampilkan untuk melihat data.</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>
```

**3c.** Di `resources/views/vcb/mesin.blade.php`, ganti SELURUH fungsi `loadData()` (mulai `async function loadData() {` di baris 122, sampai baris `}` penutupnya di baris 223) dengan versi baru di bawah ini. Versi baru ini membungkus PERSIS isi lama (params, fetch, render Chart, render tabel — nol satu baris logikanya diubah) dalam `try`, ditambah `overlay`/`errBox`/`submitBtn` di awal dan `catch`/`finally` di akhir:

```js
async function loadData() {
    const overlay = document.getElementById('mesinLoadingOverlay');
    const errBox  = document.getElementById('mesinLoadError');
    const submitBtn = document.querySelector('#filterForm button[type="submit"]');
    overlay.style.display = 'flex';
    errBox.style.display = 'none';
    submitBtn.disabled = true;
    try {
        const params = new URLSearchParams({
            date_from: document.getElementById('date_from').value,
            date_to:   document.getElementById('date_to').value,
            shift_id:  document.getElementById('shift_id').value,
        });
        document.querySelectorAll('#filterForm input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
        // Tombol export tidak dirender untuk role guest.
        const exportBtn = document.getElementById('exportExcel');
        if (exportBtn) exportBtn.href = `/export/produksi?${params}&group=mesin&format=excel`;

        const res  = await fetch(`{{ route('vcb.mesin.data') }}?${params}`);
        const data = await res.json();

        if (vcbChart) vcbChart.destroy();
        vcbChart = new Chart(document.getElementById('vcbChart'), {
            type: 'bar',
            data: { labels: data.labels, datasets: data.datasets },
            /* Nama mesin ditempel TEGAK di dalam batang Actual (dataset 0) —
             * pola sama VCB Produksi, bukan tick miring di bawah sumbu-X.
             * Batang di sini dicat solid gelap (biru/hijau/merah) jadi teks
             * dalamnya HARUS putih, sama seperti Availability Mesin. */
            plugins: [pluginNamaDalamBatang({ warnaDalam: '#ffffff' })],
            options: {
                responsive: true,
                maintainAspectRatio: false,
                layout: { padding: { top: 24 } },
                scales: {
                    x: {
                        /* Nama sudah tergambar di dalam batang — tick di bawah
                         * bikin namanya dobel. */
                        ticks: { display: false },
                        grid: { display: false },
                    },
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        grid: { color: '#e5e7eb' },
                        ticks: { color: '#6b7280', font: { size: 11 } },
                    },
                },
                plugins: {
                    /* Legend dibuat manual — dataset batang mewarnai tiap batang
                     * beda-beda (achievement per mesin), bukan satu warna per
                     * dataset, jadi swatch bawaan Chart.js bakal salah/aneh. */
                    legend: {
                        position: 'top',
                        labels: {
                            generateLabels: () => [
                                { text: 'Actual QTY', fillStyle: '#6b7280', strokeStyle: '#6b7280' },
                                { text: 'Target QTY', fillStyle: '#374151', strokeStyle: '#374151' },
                            ],
                        },
                    },
                    datalabels: {
                        display: c => c.datasetIndex === 0,
                        anchor: 'end',
                        align: 'end',
                        offset: 2,
                        color: '#374151',
                        font: { size: 10, weight: 700 },
                        formatter: (v, c) => (data.achievements[c.dataIndex] ?? 0) + '%',
                    },
                    tooltip: {
                        displayColors: false,
                        callbacks: {
                            label: c => {
                                const i = c.dataIndex;
                                if (c.datasetIndex === 0) {
                                    return `Actual: ${Number(c.raw).toLocaleString('id-ID')} (${data.achievements[i]}%)`;
                                }

                                return `Target: ${Number(c.raw).toLocaleString('id-ID')}`;
                            },
                        },
                    },
                },
            },
        });

        const tbody = document.getElementById('vcbTableBody');
        if (!data.table.length) {
            tbody.innerHTML = '<tr><td colspan="9" class="text-center text-muted py-3">Tidak ada data.</td></tr>';
            return;
        }
        tbody.innerHTML = data.table.map(row => {
            // Warna dari angkanya, BUKAN dari traffic_light: cabang 'success' saja tak pernah
            // memuat mesin yang melampaui target ('info'), jadi yang di atas target dulu
            // jatuh ke cabang terakhir dan diberi warna merah.
            const badge = row.achievement_pct > 100 ? 'bg-primary'
                        : row.achievement_pct >= 100 ? 'bg-success'
                        : 'bg-danger';
            return `<tr class="table-${escHtml(row.traffic_light)}">
                <td>${escHtml(row.mesin)}</td><td>${escHtml(row.id_mesin)}</td><td>${escHtml(row.line)}</td><td>${escHtml(row.tipe_mesin)}</td>
                <td>${Number(row.total_target_qty).toLocaleString()}</td>
                <td>${Number(row.total_qty_aktual).toLocaleString()}</td>
                <td>${row.total_duration_hours}</td>
                <td><span class="badge ${badge}">${row.achievement_pct}%</span></td>
                <td>${row.avg_actual_sph}</td>
            </tr>`;
        }).join('');
    } catch (e) {
        console.error('loadData error:', e);
        errBox.style.display = '';
    } finally {
        overlay.style.display = 'none';
        submitBtn.disabled = false;
    }
}
```

`return` di tengah try (kasus `!data.table.length`) aman — `finally` tetap jalan walau ada `return` di dalam `try`.

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=VcbLoadingStateTest`
Expected: PASS (2/2).

Run regresi VCB Mesin: `php artisan test --filter=VcbMesin`
Expected: PASS (`VcbMesinChartTest`, `VcbMesinFilterTest` tidak boleh rusak — keduanya baca `loadData()`/markup halaman ini).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/Vcb/VcbLoadingStateTest.php
git add resources/views/layouts/app.blade.php resources/views/vcb/mesin.blade.php tests/Feature/Vcb/VcbLoadingStateTest.php
git commit -m "feat(vcb-mesin): spinner overlay + error banner pas loading data

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 2: VCB Produksi (Mode 1 & Mode 2)

**Files:**
- Modify: `resources/views/vcb/produksi.blade.php:132` (`#modeAll`), `:196` (`#modeOne`), `:518-551` (`loadAllOperators()`), `:724-756` (`loadOneOperator()`)
- Test: Modify `tests/Feature/Vcb/VcbLoadingStateTest.php`

**Interfaces:**
- Consumes: CSS `.erp-loading-overlay` dari Task 1 (`layouts/app.blade.php`, tidak diubah lagi).

- [ ] **Step 1: Write the failing tests**

Tambahkan di `tests/Feature/Vcb/VcbLoadingStateTest.php`, sebelum `}` penutup class:

```php
    public function test_vcb_produksi_markup_overlay_dan_banner_ada_di_kedua_mode(): void
    {
        $html = $this->html('vcb.produksi');

        $this->assertStringContainsString('id="allLoadingOverlay"', $html);
        $this->assertStringContainsString('id="allLoadError"', $html);
        $this->assertStringContainsString('id="oneLoadingOverlay"', $html);
        $this->assertStringContainsString('id="oneLoadError"', $html);
        $this->assertSame(2, substr_count($html, 'Gagal memuat data — coba lagi.'));
    }

    public function test_vcb_produksi_load_all_operators_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.produksi'), 'loadAllOperators');

        $this->assertStringContainsString('allLoadingOverlay', $fn);
        $this->assertStringContainsString("overlay.style.display = 'flex'", $fn);
        $this->assertStringContainsString('#formAll button[type="submit"]', $fn);
        $this->assertStringContainsString("submitBtn.disabled = true", $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }

    public function test_vcb_produksi_load_one_operator_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.produksi'), 'loadOneOperator');

        $this->assertStringContainsString('oneLoadingOverlay', $fn);
        $this->assertStringContainsString('#formOne button[type="submit"]', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VcbLoadingStateTest`
Expected: 3 test baru FAIL — markup/JS belum ada di `produksi.blade.php`.

- [ ] **Step 3: Implement — `#modeAll`/`#modeOne` markup & kedua fungsi loader**

**3a.** Ganti baris 132 dari `<div id="modeAll">` jadi:

```blade
<div id="modeAll" style="position:relative">
    <div class="erp-loading-overlay" id="allLoadingOverlay">
        <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
    </div>
    <div class="text-danger mb-3" id="allLoadError" style="display:none">
        Gagal memuat data — coba lagi.
    </div>
```

**3b.** Ganti baris 196 dari `<div id="modeOne" style="display:none">` jadi:

```blade
<div id="modeOne" style="display:none;position:relative">
    <div class="erp-loading-overlay" id="oneLoadingOverlay">
        <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
    </div>
    <div class="text-danger mb-3" id="oneLoadError" style="display:none">
        Gagal memuat data — coba lagi.
    </div>
```

**3c.** Ganti `async function loadAllOperators() {` (baris 518) sampai penutupnya (baris 551) dari:

```js
async function loadAllOperators() {
    const params = new URLSearchParams({
        date_from: document.getElementById('all_date_from').value,
        date_to:   document.getElementById('all_date_to').value,
        shift_id:  document.getElementById('all_shift_id').value,
    });
    document.querySelectorAll('#formAll input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
    document.getElementById('allPeriodLabel').textContent =
        document.getElementById('all_date_from').value + ' — ' + document.getElementById('all_date_to').value;

    try {
        const res  = await fetch(`{{ route('vcb.produksi.data') }}?${params}`);
        if (!res.ok) throw new Error(`Server error ${res.status}`);
        const data = await res.json();

        const sorted = [...data.table].sort((a,b) => b.avg_actual_sph - a.avg_actual_sph);

        if (sorted.length) {
            renderAllKpi(sorted);
            document.getElementById('allChartPanel').style.display = '';
            buildAllChart(
                sorted.map(r => r.karyawan),
                sorted.map(r => r.achievement_pct),
                sorted.map(r => r.traffic_light),
                sorted.map(r => r.id_karyawan)
            );
        } else {
            document.getElementById('allKpi').innerHTML = '';
            document.getElementById('allChartPanel').style.display = 'none';
        }
        renderAllTable(sorted);

    } catch(err) { console.error('loadAllOperators error:', err); }
}
```

jadi:

```js
async function loadAllOperators() {
    const overlay = document.getElementById('allLoadingOverlay');
    const errBox  = document.getElementById('allLoadError');
    const submitBtn = document.querySelector('#formAll button[type="submit"]');
    overlay.style.display = 'flex';
    errBox.style.display = 'none';
    submitBtn.disabled = true;
    const params = new URLSearchParams({
        date_from: document.getElementById('all_date_from').value,
        date_to:   document.getElementById('all_date_to').value,
        shift_id:  document.getElementById('all_shift_id').value,
    });
    document.querySelectorAll('#formAll input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
    document.getElementById('allPeriodLabel').textContent =
        document.getElementById('all_date_from').value + ' — ' + document.getElementById('all_date_to').value;

    try {
        const res  = await fetch(`{{ route('vcb.produksi.data') }}?${params}`);
        if (!res.ok) throw new Error(`Server error ${res.status}`);
        const data = await res.json();

        const sorted = [...data.table].sort((a,b) => b.avg_actual_sph - a.avg_actual_sph);

        if (sorted.length) {
            renderAllKpi(sorted);
            document.getElementById('allChartPanel').style.display = '';
            buildAllChart(
                sorted.map(r => r.karyawan),
                sorted.map(r => r.achievement_pct),
                sorted.map(r => r.traffic_light),
                sorted.map(r => r.id_karyawan)
            );
        } else {
            document.getElementById('allKpi').innerHTML = '';
            document.getElementById('allChartPanel').style.display = 'none';
        }
        renderAllTable(sorted);

    } catch(err) {
        console.error('loadAllOperators error:', err);
        errBox.style.display = '';
    } finally {
        overlay.style.display = 'none';
        submitBtn.disabled = false;
    }
}
```

**3d.** Ganti `async function loadOneOperator() {` (baris 724) sampai penutupnya (baris 755) dari:

```js
async function loadOneOperator() {
    const karyawanId = document.getElementById('one_karyawan_id').value;
    if (!karyawanId) { alert('Pilih operator terlebih dahulu.'); return; }

    const params = new URLSearchParams({
        date_from:   document.getElementById('one_date_from').value,
        date_to:     document.getElementById('one_date_to').value,
        karyawan_id: karyawanId,
        shift_id:    document.getElementById('one_shift_id').value,
    });
    document.querySelectorAll('#formOne input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
    // Tombol export tidak dirender untuk role guest.
    const exportBtn = document.getElementById('oneExportBtn');
    if (exportBtn) exportBtn.href = `/export/produksi?${params}&format=excel`;

    try {
        const res  = await fetch(`{{ route('vcb.produksi.operator-data') }}?${params}`);
        const data = await res.json();
        if (data.error) { alert(data.error); return; }

        document.getElementById('oneEmpty').style.display       = 'none';
        document.getElementById('oneChartPanel').style.display  = '';
        document.getElementById('oneDetailPanel').style.display = '';

        document.getElementById('oneChartLabel').textContent  = data.operator.toUpperCase();
        document.getElementById('oneDetailLabel').textContent = `— ${data.operator}`;

        buildOneChart(data.labels, data.achievements, data.colors);
        buildOneRows(data.table);

    } catch(err) { console.error('loadOneOperator error:', err); }
}
```

jadi (validasi `karyawanId` kosong TETAP di paling atas, SEBELUM overlay/disable — belum ada fetch yang jalan di jalur itu):

```js
async function loadOneOperator() {
    const karyawanId = document.getElementById('one_karyawan_id').value;
    if (!karyawanId) { alert('Pilih operator terlebih dahulu.'); return; }

    const overlay = document.getElementById('oneLoadingOverlay');
    const errBox  = document.getElementById('oneLoadError');
    const submitBtn = document.querySelector('#formOne button[type="submit"]');
    overlay.style.display = 'flex';
    errBox.style.display = 'none';
    submitBtn.disabled = true;
    const params = new URLSearchParams({
        date_from:   document.getElementById('one_date_from').value,
        date_to:     document.getElementById('one_date_to').value,
        karyawan_id: karyawanId,
        shift_id:    document.getElementById('one_shift_id').value,
    });
    document.querySelectorAll('#formOne input[name="mesin_ids[]"]').forEach(el => params.append('mesin_ids[]', el.value));
    // Tombol export tidak dirender untuk role guest.
    const exportBtn = document.getElementById('oneExportBtn');
    if (exportBtn) exportBtn.href = `/export/produksi?${params}&format=excel`;

    try {
        const res  = await fetch(`{{ route('vcb.produksi.operator-data') }}?${params}`);
        const data = await res.json();
        if (data.error) { alert(data.error); return; }

        document.getElementById('oneEmpty').style.display       = 'none';
        document.getElementById('oneChartPanel').style.display  = '';
        document.getElementById('oneDetailPanel').style.display = '';

        document.getElementById('oneChartLabel').textContent  = data.operator.toUpperCase();
        document.getElementById('oneDetailLabel').textContent = `— ${data.operator}`;

        buildOneChart(data.labels, data.achievements, data.colors);
        buildOneRows(data.table);

    } catch(err) {
        console.error('loadOneOperator error:', err);
        errBox.style.display = '';
    } finally {
        overlay.style.display = 'none';
        submitBtn.disabled = false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=VcbLoadingStateTest`
Expected: PASS (5/5 — 2 dari Task 1 + 3 baru).

Run regresi VCB Produksi: `php artisan test --filter=VcbProduksi`
Expected: PASS.

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/Vcb/VcbLoadingStateTest.php
git add resources/views/vcb/produksi.blade.php tests/Feature/Vcb/VcbLoadingStateTest.php
git commit -m "feat(vcb-produksi): spinner overlay + error banner Mode 1 & 2

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

### Task 3: VCB Quality (Mode 1 & Mode 2)

**Files:**
- Modify: `resources/views/vcb/quality.blade.php:119` (`#modeAll`), `:182` (`#modeOne`), `:485-509` (`loadAllInspectors()`), `:668-697` (`loadOneInspector()`)
- Test: Modify `tests/Feature/Vcb/VcbLoadingStateTest.php`

**Interfaces:**
- Consumes: CSS `.erp-loading-overlay` dari Task 1.

- [ ] **Step 1: Write the failing tests**

Tambahkan di `tests/Feature/Vcb/VcbLoadingStateTest.php`, sebelum `}` penutup class:

```php
    public function test_vcb_quality_markup_overlay_dan_banner_ada_di_kedua_mode(): void
    {
        $html = $this->html('vcb.quality');

        $this->assertStringContainsString('id="allLoadingOverlay"', $html);
        $this->assertStringContainsString('id="allLoadError"', $html);
        $this->assertStringContainsString('id="oneLoadingOverlay"', $html);
        $this->assertStringContainsString('id="oneLoadError"', $html);
        $this->assertSame(2, substr_count($html, 'Gagal memuat data — coba lagi.'));
    }

    public function test_vcb_quality_load_all_inspectors_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.quality'), 'loadAllInspectors');

        $this->assertStringContainsString('allLoadingOverlay', $fn);
        $this->assertStringContainsString('#formAll button[type="submit"]', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }

    public function test_vcb_quality_load_one_inspector_toggle_overlay_dan_disable_tombol(): void
    {
        $fn = $this->fungsiJs($this->html('vcb.quality'), 'loadOneInspector');

        $this->assertStringContainsString('oneLoadingOverlay', $fn);
        $this->assertStringContainsString('#formOne button[type="submit"]', $fn);
        $this->assertStringContainsString("errBox.style.display = ''", $fn);
        $this->assertStringContainsString('finally', $fn);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=VcbLoadingStateTest`
Expected: 3 test baru FAIL — markup/JS belum ada di `quality.blade.php`.

- [ ] **Step 3: Implement — `#modeAll`/`#modeOne` markup & kedua fungsi loader**

**3a.** Ganti baris 119 dari `<div id="modeAll">` jadi (pola identik Task 2, `#formAll`/`allLoadingOverlay`/`allLoadError`):

```blade
<div id="modeAll" style="position:relative">
    <div class="erp-loading-overlay" id="allLoadingOverlay">
        <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
    </div>
    <div class="text-danger mb-3" id="allLoadError" style="display:none">
        Gagal memuat data — coba lagi.
    </div>
```

**3b.** Ganti baris 182 dari `<div id="modeOne" style="display:none">` jadi:

```blade
<div id="modeOne" style="display:none;position:relative">
    <div class="erp-loading-overlay" id="oneLoadingOverlay">
        <div class="spinner-border" role="status"><span class="visually-hidden">Memuat...</span></div>
    </div>
    <div class="text-danger mb-3" id="oneLoadError" style="display:none">
        Gagal memuat data — coba lagi.
    </div>
```

**3c.** Ganti `async function loadAllInspectors() {` (baris 485) sampai penutupnya (baris 509) dari:

```js
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
```

jadi:

```js
async function loadAllInspectors() {
    const overlay = document.getElementById('allLoadingOverlay');
    const errBox  = document.getElementById('allLoadError');
    const submitBtn = document.querySelector('#formAll button[type="submit"]');
    overlay.style.display = 'flex';
    errBox.style.display = 'none';
    submitBtn.disabled = true;
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
    } catch(err) {
        console.error('loadAllInspectors error:', err);
        errBox.style.display = '';
    } finally {
        overlay.style.display = 'none';
        submitBtn.disabled = false;
    }
}
```

**3d.** Ganti `async function loadOneInspector() {` (baris 668) sampai penutupnya (baris 697) dari:

```js
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
```

jadi:

```js
async function loadOneInspector() {
    const karyawanId = document.getElementById('one_karyawan_id').value;
    if (!karyawanId) { alert('Pilih inspector terlebih dahulu.'); return; }

    const overlay = document.getElementById('oneLoadingOverlay');
    const errBox  = document.getElementById('oneLoadError');
    const submitBtn = document.querySelector('#formOne button[type="submit"]');
    overlay.style.display = 'flex';
    errBox.style.display = 'none';
    submitBtn.disabled = true;
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

    } catch(err) {
        console.error('loadOneInspector error:', err);
        errBox.style.display = '';
    } finally {
        overlay.style.display = 'none';
        submitBtn.disabled = false;
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=VcbLoadingStateTest`
Expected: PASS (8/8 — semua test dari 3 task).

Run full regression: `php artisan test`
Expected: PASS (seluruh suite — layout disentuh Task 1, cek nol halaman lain kena dampak tak terduga).

- [ ] **Step 5: Format & commit**

```bash
vendor/bin/pint tests/Feature/Vcb/VcbLoadingStateTest.php
git add resources/views/vcb/quality.blade.php tests/Feature/Vcb/VcbLoadingStateTest.php
git commit -m "feat(vcb-quality): spinner overlay + error banner Mode 1 & 2

Co-Authored-By: Claude Sonnet 5 <noreply@anthropic.com>"
```

---

## Verifikasi Manual (Browser)

Setelah 3 task selesai, sebelum PR:

1. Buka DevTools → Network → throttle "Slow 3G". Buka VCB Mesin, klik "Tampilkan" — spinner overlay muncul menutupi chart+tabel, tombol ke-disable, ilang pas data dateng.
2. VCB Produksi — cek Mode 1 & Mode 2 independen: spinner Mode 1 nol nongol pas ganti-ganti filter di Mode 2 dan sebaliknya.
3. VCB Quality — sama kayak poin 2.
4. Matiin koneksi (DevTools → Network → Offline) di ketiga halaman, klik filter — banner error merah muncul, tombol balik aktif (bisa klik ulang), spinner ilang (bukan nyangkut).
5. Nyalain lagi koneksi, klik ulang — banner error ilang, data ke-load normal.
