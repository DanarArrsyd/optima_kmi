# Schedule Preventive Maintenance PDF Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Tombol "PDF" + "Generate Ulang" di sebelah tombol Refresh pada halaman
Control Stroke Mesin & Dies, membuka/download jadwal preventive bulanan (PDF, layout
sama persis template fisik perusahaan), digenerate otomatis tanggal 1 tiap bulan.

**Architecture:** Dua tabel baru (`preventive_schedules` header + `preventive_schedule_items`
snapshot per item) diisi oleh `PreventiveScheduleService::generate()` — kriteria
warning/overdue dari `computeCurrentCycle()` yang sudah ada, disebar ke hari kerja
(skip Minggu+libur via `KalenderKerjaService`) dengan kapasitas tetap per minggu.
Generate jalan lewat artisan command dipanggil GitHub Actions cron tanggal 1 (pola
sama `backup.yml`), plus tombol manual. PDF di-render on-the-fly dari tabel yang sudah
ada (dompdf), bukan disimpan sebagai file.

**Tech Stack:** Laravel 13, `barryvdh/laravel-dompdf` (dependency baru), MySQL,
GitHub Actions cron.

## Global Constraints

- Migrasi baru WAJIB didaftarkan di `.github/workflows/deploy.yml` step "Migrate
  (targeted)" — lupa = gagal senyap (lihat CLAUDE.md).
- Kapasitas hardcode: **mesin 1/minggu, dies 5/minggu**. Nol UI setting.
- Kriteria masuk jadwal: status `warning`/`overdue` DARI `computeCurrentCycle()` yang
  sudah ada — nol perubahan di service itu, fitur ini cuma KONSUMEN.
- Item yang nggak kebagian slot bulan ini TIDAK carry-over — evaluasi ulang murni dari
  status live tiap kali generate (bulan depan).
- Nomor dokumen: `PM-MESIN/{nomor}/{bulan-romawi}/{tahun}` / `PM-DIES/...`, `{nomor}`
  reset tiap tahun (`max+1` dari tahun berjalan), TETAP SAMA kalau bulan itu di-generate
  ulang (bukan nomor baru tiap klik "Generate Ulang").
- Route prefix aktual (BUKAN `/maintenance/control-stroke-*` — itu salah baca di spec
  awal): baca-only di grup `role:admin,staff_maintenance,manager,guest` prefix
  `maintenance` (routes/web.php:217-239, path `availability/control-stroke-mesin`
  DI DALAM prefix `maintenance` itu — jadi URL akhirnya
  `/maintenance/availability/control-stroke-mesin/...`); tulis (generate manual) di
  grup `role:admin,staff_maintenance,manager` (routes/web.php:193-214, prefix sama).
- `KalenderKerjaService::hariKerja(Carbon $tgl): bool` — method STATIS, bukan instance
  `isHariKerja()`.
- Spec: `docs/superpowers/specs/2026-08-31-schedule-preventive-maintenance-design.md`

---

### Task 1: Migrasi + dependency dompdf + registrasi deploy.yml

**Files:**
- Create: `database/migrations/2026_08_31_000001_create_preventive_schedules_table.php`
- Create: `database/migrations/2026_08_31_000002_create_preventive_schedule_items_table.php`
- Modify: `composer.json` (dompdf, via `composer require`)
- Modify: `.github/workflows/deploy.yml` (daftarkan 2 migrasi baru)

**Interfaces:**
- Produces: tabel `preventive_schedules` (`id`, `bulan` date, `jenis` string,
  `nomor_dokumen` string, `generated_at` nullable datetime, `generated_by` nullable
  FK users, timestamps, unique `(bulan, jenis)`); tabel `preventive_schedule_items`
  (`id`, `schedule_id` FK cascade-delete, `id_mesin` nullable FK master_mesin,
  `id_part_proses` nullable FK master_part_proses, `stroke_snapshot` int,
  `pct_snapshot` decimal, `threshold_snapshot` int, `tanggal_rencana` date,
  `minggu_ke` tinyint, timestamps).

- [ ] **Step 1: Install dompdf**

```bash
composer require barryvdh/laravel-dompdf
```

Expected: `composer.json`/`composer.lock` berubah, package `barryvdh/laravel-dompdf`
masuk `require`.

- [ ] **Step 2: Migrasi `preventive_schedules`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventive_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('bulan');
            $table->string('jenis', 10); // 'mesin' | 'dies'
            $table->string('nomor_dokumen');
            $table->dateTime('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['bulan', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_schedules');
    }
};
```

- [ ] **Step 3: Migrasi `preventive_schedule_items`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventive_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('preventive_schedules')->cascadeOnDelete();
            $table->foreignId('id_mesin')->nullable()->constrained('master_mesin');
            $table->foreignId('id_part_proses')->nullable()->constrained('master_part_proses');
            $table->integer('stroke_snapshot');
            $table->decimal('pct_snapshot', 6, 1);
            $table->integer('threshold_snapshot');
            $table->date('tanggal_rencana');
            $table->unsignedTinyInteger('minggu_ke');
            $table->timestamps();
            $table->index('schedule_id');
            $table->index('tanggal_rencana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_schedule_items');
    }
};
```

- [ ] **Step 4: Daftarkan di `deploy.yml`**

Tambah 2 baris `--path=` baru DI AKHIR daftar yang sudah ada di
`.github/workflows/deploy.yml` (step "Migrate (targeted) di server", baris ~146-162 —
baca file dulu, urutannya kronologis, tambah di bawah baris terakhir yang ada saat
ini, JANGAN sisipkan di tengah):

```
              --path=database/migrations/2026_08_31_000001_create_preventive_schedules_table.php \
              --path=database/migrations/2026_08_31_000002_create_preventive_schedule_items_table.php"
```

(Baris terakhir yang sudah ada kehilangan `"` penutup di ujungnya — pindahkan `"`
penutup ke baris terakhir yang BARU ditambahkan, seperti pola yang sudah ada.)

- [ ] **Step 5: Jalankan migrasi lokal + gerbang cakupan**

```bash
php artisan migrate
./scripts/check-migration-coverage.sh
```

Expected: migrasi jalan tanpa galat, gerbang cakupan lolos (2 migrasi baru terdeteksi
terdaftar di deploy.yml).

- [ ] **Step 6: Commit**

```bash
git add composer.json composer.lock database/migrations/2026_08_31_000001_create_preventive_schedules_table.php database/migrations/2026_08_31_000002_create_preventive_schedule_items_table.php .github/workflows/deploy.yml
git commit -m "feat(preventive-schedule): migrasi tabel jadwal + dompdf"
```

---

### Task 2: Model `PreventiveSchedule` + `PreventiveScheduleItem`

**Files:**
- Create: `app/Models/PreventiveSchedule.php`
- Create: `app/Models/PreventiveScheduleItem.php`
- Modify: `app/Models/MasterMesin.php` (relasi `preventiveScheduleItems`)
- Modify: `app/Models/MasterPartProses.php` (relasi `preventiveScheduleItems`)

**Interfaces:**
- Consumes: tabel dari Task 1.
- Produces: `PreventiveSchedule::items()` HasMany, `PreventiveScheduleItem::schedule()`
  BelongsTo, `::mesin()`/`::partProses()` BelongsTo (nullable sesuai `jenis` induk).

- [ ] **Step 1: `PreventiveSchedule`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreventiveSchedule extends Model
{
    protected $fillable = [
        'bulan', 'jenis', 'nomor_dokumen', 'generated_at', 'generated_by',
    ];

    protected $casts = [
        'bulan' => 'date:Y-m-d',
        'generated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PreventiveScheduleItem::class, 'schedule_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
```

- [ ] **Step 2: `PreventiveScheduleItem`**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreventiveScheduleItem extends Model
{
    protected $fillable = [
        'schedule_id', 'id_mesin', 'id_part_proses',
        'stroke_snapshot', 'pct_snapshot', 'threshold_snapshot',
        'tanggal_rencana', 'minggu_ke',
    ];

    protected $casts = [
        'tanggal_rencana' => 'date:Y-m-d',
        'pct_snapshot' => 'float',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PreventiveSchedule::class, 'schedule_id');
    }

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }
}
```

- [ ] **Step 3: Relasi balik di `MasterMesin`**

Baca `app/Models/MasterMesin.php` dulu — cari method `preventiveLogs(): HasMany`
(sudah ada) dan tambah PERSIS setelahnya:

```php
    public function preventiveScheduleItems(): HasMany
    {
        return $this->hasMany(PreventiveScheduleItem::class, 'id_mesin');
    }
```

(`HasMany` sudah pasti ke-import di file ini karena dipakai `preventiveLogs()` — cek
use statement-nya ada, jangan tambah import dobel kalau sudah ada.)

- [ ] **Step 4: Relasi balik di `MasterPartProses`**

Sama, di `app/Models/MasterPartProses.php`, setelah `preventiveLogs(): HasMany`:

```php
    public function preventiveScheduleItems(): HasMany
    {
        return $this->hasMany(PreventiveScheduleItem::class, 'id_part_proses');
    }
```

- [ ] **Step 5: Verifikasi lewat tinker**

```bash
php artisan tinker --execute="echo App\Models\PreventiveSchedule::class . ' ' . App\Models\PreventiveScheduleItem::class;"
```

Expected: nol error, kedua class ke-load.

- [ ] **Step 6: Commit**

```bash
git add app/Models/PreventiveSchedule.php app/Models/PreventiveScheduleItem.php app/Models/MasterMesin.php app/Models/MasterPartProses.php
git commit -m "feat(preventive-schedule): model PreventiveSchedule + PreventiveScheduleItem"
```

---

### Task 3: `PreventiveScheduleService` — algoritma distribusi + generate

**Files:**
- Create: `app/Services/PreventiveScheduleService.php`
- Test: `tests/Unit/PreventiveScheduleServiceTest.php`

**Interfaces:**
- Consumes: `MaintenanceDashboardService::computeCurrentCycle()` (baris array dengan
  key `id_mesin`, `kode_mesin`, `nama_mesin`, `current_stroke`, `threshold`, `pct`,
  `status` — status `overdue`/`warning`/`ok`), `MaintenanceDiesDashboardService::computeCurrentCycle()`
  (key sama TAPI `id_part_proses` bukan `id_mesin`, `kode_mesin`=part_no,
  `nama_mesin`=part_name), `KalenderKerjaService::hariKerja(Carbon): bool` (statis).
- Produces: `PreventiveScheduleService::generate(string $jenis, Carbon $bulan): PreventiveSchedule`
  — dipakai Task 4 (command) & Task 5 (controller "Generate Ulang").
  `PreventiveScheduleService::KAPASITAS` const `['mesin' => 1, 'dies' => 5]`.

- [ ] **Step 1: Test kapasitas & urutan prioritas (RED dulu)**

```php
<?php

namespace Tests\Unit;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveSchedule;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleServiceTest extends TestCase
{
    use RefreshDatabase;

    private MasterShift $shift;
    private MasterKaryawan $karyawan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $this->karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
    }

    /** Mesin dengan stroke tinggi (mendekati/lewat threshold 300000) via 1 baris produksi besar. */
    private function mesinDenganStroke(string $kode, int $stroke): MasterMesin
    {
        $mesin = MasterMesin::create([
            'id_mesin' => $kode, 'nama_mesin' => "Press {$kode}", 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
            'tanggal_instalasi' => '2020-01-01',
        ]);
        $part = MasterPart::create(['part_no' => "P-{$kode}", 'part_name' => "Part {$kode}", 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-01', 'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $stroke,
            'actual_cycle_time' => 10, 'created_by' => User::factory()->create(['role' => 'admin'])->id,
        ]);

        return $mesin;
    }

    public function test_kapasitas_mesin_satu_per_minggu_membatasi_jumlah_terjadwal(): void
    {
        // 5 mesin overdue (>=100% dari 300000) — kapasitas 1/minggu, Agustus 2026 punya 5 minggu kerja.
        for ($i = 1; $i <= 5; $i++) {
            $this->mesinDenganStroke("A{$i}", 310000);
        }

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertLessThanOrEqual(5, $schedule->items()->count());
        $this->assertGreaterThan(0, $schedule->items()->count());

        // Nol dua item di minggu yang sama (kapasitas 1/minggu buat mesin).
        $perMinggu = $schedule->items()->get()->groupBy('minggu_ke');
        foreach ($perMinggu as $items) {
            $this->assertCount(1, $items, 'Kapasitas 1 mesin/minggu dilanggar.');
        }
    }

    public function test_urutan_prioritas_pct_paling_tinggi_dijadwalkan_duluan(): void
    {
        $this->mesinDenganStroke('LOW', 240000);  // 80% — warning
        $this->mesinDenganStroke('HIGH', 320000); // 106.7% — overdue, paling mendesak

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $item1 = $schedule->items()->orderBy('tanggal_rencana')->first();
        $this->assertSame('HIGH', $item1->mesin->id_mesin, 'Item paling mendesak (pct tertinggi) harus dapat slot pertama.');
    }

    public function test_mesin_berstatus_ok_tidak_ikut_dijadwalkan(): void
    {
        $this->mesinDenganStroke('OK', 100000); // 33% — ok, jauh dari threshold

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertSame(0, $schedule->items()->count());
    }

    public function test_tanggal_rencana_selalu_hari_kerja(): void
    {
        $this->mesinDenganStroke('A1', 310000);

        $svc = app(PreventiveScheduleService::class);
        $schedule = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $item = $schedule->items()->first();
        $this->assertFalse($item->tanggal_rencana->isSunday(), 'tanggal_rencana jatuh di hari Minggu.');
    }

    public function test_generate_ulang_bulan_yang_sama_mempertahankan_nomor_dokumen(): void
    {
        $this->mesinDenganStroke('A1', 310000);

        $svc = app(PreventiveScheduleService::class);
        $pertama = $svc->generate('mesin', Carbon::parse('2026-08-01'));
        $nomorAwal = $pertama->nomor_dokumen;

        $keduaMesin = $this->mesinDenganStroke('A2', 320000); // ubah data, generate ulang bulan SAMA
        $kedua = $svc->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertSame($nomorAwal, $kedua->nomor_dokumen, 'Nomor dokumen berubah padahal cuma generate ulang bulan yg sama.');
        $this->assertSame(1, PreventiveSchedule::where('bulan', '2026-08-01')->where('jenis', 'mesin')->count(), 'Regenerate seharusnya UPDATE, bukan bikin baris jadwal baru.');
    }

    public function test_nomor_dokumen_format_dan_increment_per_tahun(): void
    {
        $this->mesinDenganStroke('A1', 310000);
        $svc = app(PreventiveScheduleService::class);

        $agustus = $svc->generate('mesin', Carbon::parse('2026-08-01'));
        $this->assertMatchesRegularExpression('#^PM-MESIN/1/VIII/2026$#', $agustus->nomor_dokumen);

        $this->mesinDenganStroke('B1', 310000);
        $september = $svc->generate('mesin', Carbon::parse('2026-09-01'));
        $this->assertMatchesRegularExpression('#^PM-MESIN/2/IX/2026$#', $september->nomor_dokumen);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal (class belum ada)**

```bash
php artisan test --filter=PreventiveScheduleServiceTest
```

Expected: FAIL — `Class "App\Services\PreventiveScheduleService" not found`.

- [ ] **Step 3: Tulis `PreventiveScheduleService`**

```php
<?php

namespace App\Services;

use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PreventiveScheduleService
{
    private const ROMAN = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Slot preventive per minggu, per jenis. Hardcode — nol UI setting (lihat spec). */
    private const KAPASITAS = ['mesin' => 1, 'dies' => 5];

    public function __construct(
        private MaintenanceDashboardService $mesinSvc,
        private MaintenanceDiesDashboardService $diesSvc,
    ) {}

    /**
     * Generate (atau regenerate) jadwal preventive satu bulan, satu jenis.
     * Regenerate bulan yang SAMA: UPDATE record (nomor_dokumen dipertahankan),
     * items lama dibuang dan diganti snapshot baru — jadwal yg udah dicetak nggak
     * berubah kalau stroke jalan terus SETELAH dicetak, tapi regenerate manual
     * sengaja menang (operator yg klik ulang mau data terbaru).
     */
    public function generate(string $jenis, Carbon $bulan): PreventiveSchedule
    {
        $bulan = $bulan->copy()->startOfMonth();

        $schedule = PreventiveSchedule::firstOrNew([
            'bulan' => $bulan->toDateString(),
            'jenis' => $jenis,
        ]);
        if (! $schedule->exists) {
            $schedule->nomor_dokumen = $this->nomorDokumen($jenis, $bulan);
        }
        $schedule->generated_at = now();
        $schedule->generated_by = auth()->id();
        $schedule->save();

        $schedule->items()->delete();

        $eligible = $this->eligibleItems($jenis);
        $hariKerja = $this->hariKerjaDalamBulan($bulan);
        $mingguBuckets = $this->kelompokkanPerMinggu($hariKerja);
        $kapasitas = self::KAPASITAS[$jenis];

        $idx = 0;
        foreach ($mingguBuckets as $mingguIndex => $hariList) {
            $mingguKe = $mingguIndex + 1;
            for ($slot = 0; $slot < $kapasitas; $slot++) {
                if ($idx >= $eligible->count()) {
                    break 2;
                }
                if (! isset($hariList[$slot])) {
                    // Minggu ini kehabisan hari kerja buat slot ke-$slot (mis. minggu
                    // pertama/terakhir bulan cuma 1-2 hari kerja) — kapasitas sisa
                    // minggu ini HANGUS, bukan dipindah ke minggu lain.
                    continue;
                }

                $item = $eligible[$idx];
                PreventiveScheduleItem::create([
                    'schedule_id' => $schedule->id,
                    'id_mesin' => $jenis === 'mesin' ? $item['id_mesin'] : null,
                    'id_part_proses' => $jenis === 'dies' ? $item['id_part_proses'] : null,
                    'stroke_snapshot' => $item['current_stroke'],
                    'pct_snapshot' => $item['pct'],
                    'threshold_snapshot' => $item['threshold'],
                    'tanggal_rencana' => $hariList[$slot]->toDateString(),
                    'minggu_ke' => $mingguKe,
                ]);
                $idx++;
            }
        }

        return $schedule->fresh('items');
    }

    /** Baris warning/overdue dari computeCurrentCycle(), diurut pct paling mendesak duluan. */
    private function eligibleItems(string $jenis): Collection
    {
        $rows = $jenis === 'mesin' ? $this->mesinSvc->computeCurrentCycle() : $this->diesSvc->computeCurrentCycle();

        return collect($rows)
            ->whereIn('status', ['overdue', 'warning'])
            ->sortByDesc('pct')
            ->values();
    }

    /** Semua hari kerja (nol Minggu, nol hari_libur) dalam satu bulan, urut tanggal. */
    private function hariKerjaDalamBulan(Carbon $bulan): Collection
    {
        $akhir = $bulan->copy()->endOfMonth();
        $hari = collect();
        for ($d = $bulan->copy(); $d->lte($akhir); $d->addDay()) {
            if (KalenderKerjaService::hariKerja($d)) {
                $hari->push($d->copy());
            }
        }

        return $hari;
    }

    /**
     * Kelompokkan hari kerja per minggu kalender (Senin-Minggu ISO). minggu_ke yang
     * disimpan adalah URUTAN RELATIF dalam bulan ini (1, 2, 3, ...), BUKAN nomor
     * minggu ISO mentah (mis. "minggu 35") — lebih gampang dibaca di PDF.
     *
     * @return Collection<int, Collection<int, Carbon>>
     */
    private function kelompokkanPerMinggu(Collection $hariKerja): Collection
    {
        return $hariKerja
            ->groupBy(fn (Carbon $d) => $d->format('o-W'))
            ->values()
            ->map(fn ($grp) => $grp->values());
    }

    private function nomorDokumen(string $jenis, Carbon $bulan): string
    {
        $prefix = $jenis === 'mesin' ? 'PM-MESIN' : 'PM-DIES';
        $tahun = $bulan->year;

        $maxNomor = PreventiveSchedule::where('jenis', $jenis)
            ->whereYear('bulan', $tahun)
            ->get()
            ->map(function ($s) {
                $bagian = explode('/', $s->nomor_dokumen);

                return (int) ($bagian[1] ?? 0);
            })
            ->max() ?? 0;

        $roman = self::ROMAN[$bulan->month] ?? 'I';

        return "{$prefix}/".($maxNomor + 1)."/{$roman}/{$tahun}";
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

```bash
php artisan test --filter=PreventiveScheduleServiceTest
```

Expected: PASS semua.

- [ ] **Step 5: Commit**

```bash
git add app/Services/PreventiveScheduleService.php tests/Unit/PreventiveScheduleServiceTest.php
git commit -m "feat(preventive-schedule): PreventiveScheduleService generate + distribusi mingguan"
```

---

### Task 4: Artisan command + GitHub Actions cron tanggal 1

**Files:**
- Create: `app/Console/Commands/JadwalkanPreventive.php`
- Create: `.github/workflows/preventive-schedule.yml`
- Test: `tests/Unit/JadwalkanPreventiveCommandTest.php`

**Interfaces:**
- Consumes: `PreventiveScheduleService::generate()` (Task 3).
- Produces: `php artisan preventive:jadwalkan` — generate jadwal mesin DAN dies buat
  bulan berjalan.

- [ ] **Step 1: Test command (RED dulu)**

```php
<?php

namespace Tests\Unit;

use App\Models\PreventiveSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class JadwalkanPreventiveCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_membuat_jadwal_mesin_dan_dies_untuk_bulan_berjalan(): void
    {
        Carbon::setTestNow('2026-08-15 10:00:00');

        $this->artisan('preventive:jadwalkan')->assertExitCode(0);

        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'mesin']);
        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'dies']);

        Carbon::setTestNow();
    }

    public function test_command_tidak_meledak_walau_nol_item_layak_dijadwalkan(): void
    {
        // Nol mesin/dies di DB sama sekali — command tetap harus SUKSES (schedule
        // header tetap dibuat dengan nol items, bukan exception).
        $this->artisan('preventive:jadwalkan')->assertExitCode(0);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

```bash
php artisan test --filter=JadwalkanPreventiveCommandTest
```

Expected: FAIL — command `preventive:jadwalkan` belum terdaftar.

- [ ] **Step 3: Tulis command**

```php
<?php

namespace App\Console\Commands;

use App\Services\PreventiveScheduleService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Carbon;
use Throwable;

class JadwalkanPreventive extends Command
{
    protected $signature = 'preventive:jadwalkan';

    protected $description = 'Generate jadwal preventive maintenance (mesin & dies) untuk bulan berjalan';

    public function handle(PreventiveScheduleService $svc): int
    {
        $bulan = Carbon::now()->startOfMonth();

        try {
            foreach (['mesin', 'dies'] as $jenis) {
                $schedule = $svc->generate($jenis, $bulan);
                $this->info("Jadwal {$jenis} {$bulan->format('F Y')}: {$schedule->nomor_dokumen}, {$schedule->items()->count()} item.");
            }

            return self::SUCCESS;
        } catch (Throwable $e) {
            // Kode keluar bukan nol supaya workflow-nya MERAH — pola sama JalankanBackup.
            Log::error('Generate jadwal preventive gagal: '.$e->getMessage());
            $this->error('Generate jadwal preventive gagal. Periksa storage/logs/laravel.log.');

            return self::FAILURE;
        }
    }
}
```

- [ ] **Step 4: Jalankan test, pastikan lolos**

```bash
php artisan test --filter=JadwalkanPreventiveCommandTest
```

Expected: PASS.

- [ ] **Step 5: Workflow GitHub Actions cron tanggal 1**

Baca `.github/workflows/backup.yml` dulu (isinya sudah dibaca saat riset plan ini) —
pola PERSIS sama, cuma cron schedule dan command yang beda:

```yaml
name: Jadwal preventive bulanan

# Memanggil artisan preventive:jadwalkan di server lewat SSH tiap tanggal 1, pola
# sama backup.yml/deploy.yml.
#
# ⚠️ Jadwal GitHub Actions tidak dijamin tepat waktu (bisa telat puluhan menit) dan
# DINONAKTIFKAN OTOMATIS setelah 60 hari repo nol aktivitas. Ketelatan jam-jaman tidak
# masalah untuk jadwal bulanan; penonaktifan masalah — tombol "Generate Ulang" manual
# di halaman Control Stroke adalah jaring pengamannya.
on:
  schedule:
    - cron: '0 1 1 * *'   # 01:00 UTC tanggal 1 = 08:00 WIB, sebelum shift mulai
  workflow_dispatch:

concurrency:
  group: deploy-production
  cancel-in-progress: false

env:
  REMOTE_PATH: /home/u518638233/domains/optima.kencomanufactur.co.id/public_html
  REMOTE_PHP: /opt/alt/php84/usr/bin/php

jobs:
  jadwalkan:
    runs-on: ubuntu-latest
    timeout-minutes: 20
    steps:
      - name: Setup SSH key
        run: |
          mkdir -p ~/.ssh
          printf '%s\n' "${{ secrets.SSH_PRIVATE_KEY }}" > ~/.ssh/deploy_key
          chmod 600 ~/.ssh/deploy_key
          for i in 1 2 3; do
            ssh-keyscan -p "${{ secrets.SSH_PORT }}" "${{ secrets.SSH_HOST }}" >> ~/.ssh/known_hosts 2>/dev/null && break
            sleep 3
          done
          true

      - name: Jalankan preventive:jadwalkan
        run: |
          set -e
          ssh -p "${{ secrets.SSH_PORT }}" -i ~/.ssh/deploy_key \
            -o StrictHostKeyChecking=accept-new \
            -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=6 \
            "${{ secrets.SSH_USERNAME }}@${{ secrets.SSH_HOST }}" \
            "cd ${{ env.REMOTE_PATH }} && ${{ env.REMOTE_PHP }} artisan preventive:jadwalkan"
```

- [ ] **Step 6: Commit**

```bash
git add app/Console/Commands/JadwalkanPreventive.php .github/workflows/preventive-schedule.yml tests/Unit/JadwalkanPreventiveCommandTest.php
git commit -m "feat(preventive-schedule): command + cron generate jadwal tanggal 1"
```

---

### Task 5: Controller (PDF + generate manual) + routes

**Files:**
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`
- Modify: `routes/web.php`
- Test: `tests/Feature/Maintenance/PreventiveSchedulePdfTest.php`

**Interfaces:**
- Consumes: `PreventiveScheduleService::generate()` (Task 3), model `PreventiveSchedule`
  (Task 2), package `barryvdh\DomPDF\Facade\Pdf` (Task 1).
- Produces: route `maintenance.control-stroke-mesin.schedule-pdf` (GET),
  `maintenance.control-stroke-mesin.schedule-generate` (POST),
  `maintenance.control-stroke-dies.schedule-pdf` (GET),
  `maintenance.control-stroke-dies.schedule-generate` (POST). Dipakai Task 6 (tombol
  UI) dan Task 6 (view PDF).

- [ ] **Step 1: Test — PDF kosong (belum ada jadwal) tampil pesan, bukan 500**

```php
<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveSchedule;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveSchedulePdfTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_belum_ada_jadwal_tampil_pesan_bukan_500(): void
    {
        Carbon::setTestNow('2026-08-15');
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin.schedule-pdf'))
            ->assertOk()
            ->assertSee('Belum ada jadwal', false);

        Carbon::setTestNow();
    }

    public function test_generate_manual_admin_boleh_lalu_pdf_tampil(): void
    {
        Carbon::setTestNow('2026-08-15');
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A1', 'nama_mesin' => 'Press A1', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100, 'tanggal_instalasi' => '2020-01-01']);
        $part = MasterPart::create(['part_no' => 'P-A1', 'part_name' => 'Part A1', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-01', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_aktual' => 310000, 'actual_cycle_time' => 10, 'created_by' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post(route('maintenance.control-stroke-mesin.schedule-generate'))
            ->assertRedirect();

        $this->assertDatabaseHas('preventive_schedules', ['bulan' => '2026-08-01', 'jenis' => 'mesin']);

        $this->actingAs($admin)
            ->get(route('maintenance.control-stroke-mesin.schedule-pdf'))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');

        Carbon::setTestNow();
    }

    public function test_staff_qc_tidak_boleh_generate_manual(): void
    {
        $staffQc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($staffQc)
            ->post(route('maintenance.control-stroke-mesin.schedule-generate'))
            ->assertForbidden();
    }

    public function test_guest_boleh_lihat_pdf_tapi_tidak_boleh_generate(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-mesin.schedule-pdf'))
            ->assertOk();

        $this->actingAs($guest)
            ->post(route('maintenance.control-stroke-mesin.schedule-generate'))
            ->assertForbidden();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal (route belum ada)**

```bash
php artisan test --filter=PreventiveSchedulePdfTest
```

Expected: FAIL — route `maintenance.control-stroke-mesin.schedule-pdf` belum
terdaftar.

- [ ] **Step 3: Tambah route**

Di `routes/web.php`, grup TULIS (`role:admin,staff_maintenance,manager`, prefix
`maintenance`, sekitar baris 208-213) tambah SETELAH baris
`control-stroke-dies.preventive.checksheet` (baris 213), SEBELUM `});` penutup grup
(baris 214):

```php
        Route::post('availability/control-stroke-mesin/schedule-generate', [ControlStrokeMesinController::class, 'scheduleGenerate'])->name('control-stroke-mesin.schedule-generate');
        Route::post('availability/control-stroke-dies/schedule-generate', [ControlStrokeDiesController::class, 'scheduleGenerate'])->name('control-stroke-dies.schedule-generate');
```

Di grup BACA (`role:admin,staff_maintenance,manager,guest`, prefix `maintenance`,
sekitar baris 217-239) tambah SETELAH baris `control-stroke-mesin.history` (baris
234) dan SETELAH `control-stroke-dies.history` (baris 238) masing-masing:

```php
        Route::get('availability/control-stroke-mesin/schedule-pdf', [ControlStrokeMesinController::class, 'schedulePdf'])->name('control-stroke-mesin.schedule-pdf');
```

```php
        Route::get('availability/control-stroke-dies/schedule-pdf', [ControlStrokeDiesController::class, 'schedulePdf'])->name('control-stroke-dies.schedule-pdf');
```

- [ ] **Step 4: Tambah method di `ControlStrokeMesinController`**

Tambah di akhir class, sebelum `}` penutup:

```php
    public function schedulePdf()
    {
        $bulan = now()->startOfMonth();
        $schedule = \App\Models\PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'mesin')
            ->with('items.mesin.preventiveLogs')
            ->first();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('maintenance.preventive-schedule-pdf', [
            'jenis' => 'mesin',
            'schedule' => $schedule,
            'bulan' => $bulan,
            'svc' => $this->svc,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream(($schedule->nomor_dokumen ?? 'schedule-mesin').'.pdf');
    }

    public function scheduleGenerate(\App\Services\PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        $preventiveSvc->generate('mesin', now()->startOfMonth());

        return redirect()->route('maintenance.control-stroke-mesin')->with(
            'success', 'Jadwal preventive mesin bulan ini berhasil digenerate ulang.'
        );
    }
```

`$schedule->nomor_dokumen` dipakai lewat null-safe `??` di nama file karena
`$schedule` itu sendiri BISA `null` (belum pernah digenerate) — view Task 6 yang
menangani render "belum ada jadwal", controller ini SENGAJA tidak bercabang
if/else, cukup lempar `$schedule` (mungkin null) apa adanya ke view. `str()` PDF
tetap valid HTTP 200 dengan body pesan "belum ada jadwal", bukan 500 — dompdf
merender apa pun HTML yang dihasilkan view, termasuk halaman pesan pendek.

- [ ] **Step 5: Tambah method di `ControlStrokeDiesController`**

Sama, di akhir class:

```php
    public function schedulePdf()
    {
        $bulan = now()->startOfMonth();
        $schedule = \App\Models\PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'dies')
            ->with('items.partProses.part', 'items.partProses.preventiveLogs')
            ->first();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('maintenance.preventive-schedule-pdf', [
            'jenis' => 'dies',
            'schedule' => $schedule,
            'bulan' => $bulan,
            'svc' => $this->svc,
        ])->setPaper('a4', 'landscape');

        return $pdf->stream(($schedule->nomor_dokumen ?? 'schedule-dies').'.pdf');
    }

    public function scheduleGenerate(\App\Services\PreventiveScheduleService $preventiveSvc)
    {
        abort_if(! auth()->user()->hasRole(['admin', 'staff_maintenance']), 403);

        $preventiveSvc->generate('dies', now()->startOfMonth());

        return redirect()->route('maintenance.control-stroke-dies')->with(
            'success', 'Jadwal preventive dies bulan ini berhasil digenerate ulang.'
        );
    }
```

- [ ] **Step 6: View PDF minimal SEMENTARA, supaya test Step 7 bisa lolos duluan**

Buat `resources/views/maintenance/preventive-schedule-pdf.blade.php` versi minimal
(bukan versi final berkalender — itu Task 6 yang menimpa file yang sama):

```blade
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><style>body{font-family:Arial,sans-serif;font-size:11px}</style></head>
<body>
@if (! $schedule)
    <p>Belum ada jadwal untuk {{ $bulan->translatedFormat('F Y') }}. Klik "Generate Ulang" di halaman Control Stroke.</p>
@else
    <h1>{{ $schedule->nomor_dokumen }}</h1>
    <p>{{ $schedule->items->count() }} item terjadwal.</p>
@endif
</body>
</html>
```

Task 6 menimpa isi file ini jadi layout kalender penuh — struktur `$schedule`
nullable dan `@if (! $schedule)` di paling atas WAJIB dipertahankan Task 6, cuma
cabang `@else`-nya yang diperkaya jadi tabel kalender.

- [ ] **Step 7: Jalankan test, pastikan lolos**

```bash
php artisan test --filter=PreventiveSchedulePdfTest
```

Expected: PASS semua 4 test.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeMesinController.php app/Http/Controllers/Maintenance/ControlStrokeDiesController.php routes/web.php resources/views/maintenance/preventive-schedule-pdf.blade.php tests/Feature/Maintenance/PreventiveSchedulePdfTest.php
git commit -m "feat(preventive-schedule): route + controller PDF & generate manual"
```

---

### Task 6: View PDF (layout kalender persis template) + tombol UI

**Files:**
- Create/finalize: `resources/views/maintenance/preventive-schedule-pdf.blade.php`
- Modify: `resources/views/maintenance/control-stroke-mesin.blade.php` (tombol)
- Modify: `resources/views/maintenance/control-stroke-dies.blade.php` (tombol)

**Interfaces:**
- Consumes: `$schedule` (PreventiveSchedule + items eager-loaded), `$jenis`
  (`'mesin'`/`'dies'`), `$bulan` (Carbon), `$svc` (MaintenanceDashboardService atau
  MaintenanceDiesDashboardService — buat `currentStrokeFor()` per item, ACTUAL STROKE
  live) — semua dari Task 5.

- [ ] **Step 1: Cek logo PT KENCO ada di `public/`**

```bash
find public -iname "*kenco*" -o -iname "*logo*"
```

Kalau CUMA `public/logo.png` (logo aplikasi OPTIMA, bukan logo perusahaan dari
gambar template) — STOP, laporkan ke user lewat status DONE_WITH_CONCERNS: minta file
logo PT KENCO (PNG/SVG, background transparan) ditaruh di `public/img/logo-kenco.png`
sebelum lanjut Step 2, JANGAN pakai `logo.png` yang salah atau bikin placeholder
sendiri. Kalau ternyata sudah ada file yang cocok, lanjut pakai itu.

- [ ] **Step 2: Timpa `preventive-schedule-pdf.blade.php` — versi final**

dompdf render HTML+CSS terbatas (nol flexbox/grid yang reliable, pakai `<table>` buat
layout kalender — sama seperti template aslinya yang memang berbasis tabel). File ini
MENIMPA versi minimal Task 5 Step 6 — struktur `@if (! $schedule)` di paling atas WAJIB
dipertahankan (cabang itu, bukan `@else`-nya, yang bikin test
`test_pdf_belum_ada_jadwal_tampil_pesan_bukan_500` di Task 5 tetap lolos):

```blade
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<style>
    @page { margin: 15px; }
    body { font-family: Arial, sans-serif; font-size: 9px; color: #111; }
    .header { display: table; width: 100%; margin-bottom: 8px; }
    .header .logo { display: table-cell; width: 70px; vertical-align: top; }
    .header .logo img { width: 60px; }
    .header .brand { display: table-cell; vertical-align: top; padding-left: 6px; }
    .header .brand .nama { color: #b91c1c; font-weight: bold; font-size: 13px; }
    .header .brand .sub { color: #1e3a8a; font-size: 9px; }
    .header .doc-no { display: table-cell; text-align: right; vertical-align: top; }
    .header .doc-no span { border: 1px solid #000; padding: 3px 8px; font-size: 9px; font-weight: bold; }
    h1 { text-align: center; font-size: 16px; margin: 4px 0 10px; }

    table.sched { width: 100%; border-collapse: collapse; }
    table.sched th, table.sched td { border: 1px solid #333; padding: 2px 3px; text-align: center; }
    table.sched th.bulan-head { background: #bfdbfe; }
    table.sched td.hari-libur, table.sched th.hari-libur { background: #fca5a5; }
    table.sched td.plan-cell { background: #f87171; }
    table.sched td.act-cell { background: #86efac; }
    table.sched td.nama-col { text-align: left; }

    .ketentuan { margin-top: 14px; font-size: 9px; }
    .ketentuan ol { margin: 4px 0 0 16px; padding: 0; }
    .ketentuan li { margin-bottom: 3px; }

    .footer-sign { margin-top: 24px; font-size: 9px; }
    .footer-sign .tempat-tgl { margin-bottom: 4px; }
    table.sign { width: 100%; border-collapse: collapse; }
    table.sign th, table.sign td { border: 1px solid #333; padding: 4px; text-align: center; height: 45px; vertical-align: bottom; }

    .printed-at { position: fixed; bottom: 0; left: 0; font-size: 7px; color: #666; }
</style>
</head>
<body>

@if (! $schedule)
    <p>Belum ada jadwal untuk {{ $bulan->translatedFormat('F Y') }}. Klik "Generate Ulang" di halaman Control Stroke.</p>
@else

<div class="header">
    <div class="logo"><img src="{{ public_path('img/logo-kenco.png') }}"></div>
    <div class="brand">
        <div class="nama">PT. KENCO MANUFACTUR INDONESIA</div>
        <div class="sub">DIES, JIGS &amp; PARTS MANUFACTURING</div>
    </div>
    <div class="doc-no"><span>Nomor Dokumen: {{ $schedule->nomor_dokumen }}</span></div>
</div>

<h1>SCHEDULE PREVENTIVE MAINTENANCE</h1>

@php
    $jumlahHari = $bulan->daysInMonth;
    $namaBulan = mb_strtoupper($bulan->translatedFormat('F Y'));
    $labelKode = $jenis === 'mesin' ? 'ID MESIN' : 'ID DIES';
    $labelNama = $jenis === 'mesin' ? 'NAMA MESIN' : 'NAMA PART/DIES';
@endphp

<table class="sched">
    <thead>
        <tr>
            <th rowspan="2">NO</th>
            <th rowspan="2">{{ $labelNama }}</th>
            <th rowspan="2">{{ $labelKode }}</th>
            <th rowspan="2">STANDARD<br>STROKE</th>
            <th rowspan="2">ACTUAL<br>STROKE</th>
            <th rowspan="2">MASTER<br>SCHED</th>
            <th colspan="{{ $jumlahHari }}" class="bulan-head">{{ $namaBulan }}</th>
        </tr>
        <tr>
            @for ($d = 1; $d <= $jumlahHari; $d++)
                @php $tgl = $bulan->copy()->day($d); @endphp
                <th class="{{ \App\Services\KalenderKerjaService::hariKerja($tgl) ? '' : 'hari-libur' }}">{{ $d }}</th>
            @endfor
        </tr>
    </thead>
    <tbody>
        @foreach ($schedule->items as $i => $item)
            @php
                $target = $jenis === 'mesin' ? $item->mesin : $item->partProses;
                $kode = $jenis === 'mesin' ? $target?->id_mesin : $target?->part?->part_no;
                $nama = $jenis === 'mesin' ? $target?->nama_mesin : $target?->part?->part_name;
                $actualStroke = $target ? $svc->currentStrokeFor($target) : $item->stroke_snapshot;
                $logBulanIni = $jenis === 'mesin'
                    ? ($target?->preventiveLogs->firstWhere(fn ($l) => $l->tanggal_preventive->isSameMonth($bulan)))
                    : ($target?->preventiveLogs->firstWhere(fn ($l) => $l->tanggal_preventive->isSameMonth($bulan)));
            @endphp
            <tr>
                <td rowspan="2">{{ $i + 1 }}</td>
                <td rowspan="2" class="nama-col">{{ $nama }}</td>
                <td rowspan="2">{{ $kode }}</td>
                <td rowspan="2">{{ number_format($item->threshold_snapshot, 0, ',', '.') }}<br>STROKE</td>
                <td rowspan="2">{{ number_format($actualStroke, 0, ',', '.') }}</td>
                <td>PLAN</td>
                @for ($d = 1; $d <= $jumlahHari; $d++)
                    @php $tgl = $bulan->copy()->day($d); $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl); $isPlan = $item->tanggal_rencana->day === $d; @endphp
                    <td class="{{ $isPlan ? 'plan-cell' : ($isLibur ? 'hari-libur' : '') }}"></td>
                @endfor
            </tr>
            <tr>
                <td>ACT</td>
                @for ($d = 1; $d <= $jumlahHari; $d++)
                    @php $tgl = $bulan->copy()->day($d); $isLibur = ! \App\Services\KalenderKerjaService::hariKerja($tgl); $isDone = $logBulanIni && $logBulanIni->tanggal_preventive->day === $d; @endphp
                    <td class="{{ $isDone ? 'act-cell' : ($isLibur ? 'hari-libur' : '') }}"></td>
                @endfor
            </tr>
        @endforeach
    </tbody>
</table>

<div class="ketentuan">
    <strong><em>KETENTUAN PREVENTIVE MAINTENANCE*</em></strong>
    <ol>
        <li>Selama Preventive Maintenance (PM) berlangsung, status {{ $jenis === 'mesin' ? 'mesin' : 'dies' }} adalah <strong><em>NON-OPERASIONAL</em></strong>.</li>
        <li>{{ ucfirst($jenis === 'mesin' ? 'Mesin' : 'Dies') }} tidak diperkenankan digunakan untuk aktivitas produksi dalam bentuk apa pun.</li>
        <li>Departemen Maintenance memiliki kewenangan penuh atas {{ $jenis === 'mesin' ? 'mesin' : 'dies' }} selama preventive berlangsung.</li>
        <li>Departemen Produksi, PPIC, Quality, dan departemen terkait lainnya wajib menghentikan seluruh aktivitas yang melibatkan {{ $jenis === 'mesin' ? 'mesin' : 'dies' }} tersebut.</li>
        <li>{{ ucfirst($jenis === 'mesin' ? 'Mesin' : 'Dies') }} harus dalam kondisi aman (lock-out/tag-out).</li>
        <li>{{ ucfirst($jenis === 'mesin' ? 'Mesin' : 'Dies') }} hanya boleh dioperasikan kembali setelah:
            <br>- Preventive dinyatakan selesai oleh Maintenance.
            <br>- Dilakukan pengecekan akhir (final check).
        </li>
    </ol>
</div>

<div class="footer-sign">
    <div class="tempat-tgl">BEKASI, {{ now()->translatedFormat('d F Y') }}</div>
    <table class="sign">
        <thead>
            <tr>
                <th>DIBUAT</th>
                <th colspan="5">MENGETAHUI</th>
                <th>DIPERIKSA</th>
                <th>MENYETUJUI</th>
            </tr>
        </thead>
        <tbody>
            <tr><td></td><td></td><td></td><td></td><td></td><td></td><td></td><td></td></tr>
        </tbody>
        <tfoot>
            <tr>
                <td>MTC MESIN</td>
                <td>PRODUKSI</td>
                <td>PPIC</td>
                <td>QUALITY</td>
                <td>MTC DIES</td>
                <td>ENGINEERING</td>
                <td>SPV PROD.</td>
                <td>DIREKTUR</td>
            </tr>
        </tfoot>
    </table>
</div>

@endif

<div class="printed-at">Printed on OPTIMA, at {{ now()->format('d.m.Y H:i:s') }}</div>

</body>
</html>
```

- [ ] **Step 3: Tombol di `control-stroke-mesin.blade.php`**

Baris 41 saat ini:
```blade
    <a href="{{ route('maintenance.control-stroke-mesin') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
```
Tambah PERSIS setelahnya, sebelum `<span class="erp-tb-sp"></span>`:
```blade
    <a href="{{ route('maintenance.control-stroke-mesin.schedule-pdf') }}" target="_blank" class="erp-tb-btn">
        <i class="bi bi-file-earmark-pdf"></i>Schedule PDF
    </a>
    @if(auth()->user()->hasRole(['admin', 'staff_maintenance']))
    <form method="POST" action="{{ route('maintenance.control-stroke-mesin.schedule-generate') }}" style="display:inline"
          onsubmit="return confirm('Generate ulang jadwal preventive bulan ini? Jadwal lama akan ditimpa.');">
        @csrf
        <button type="submit" class="erp-tb-btn"><i class="bi bi-arrow-repeat"></i>Generate Ulang</button>
    </form>
    @endif
```

- [ ] **Step 4: Tombol di `control-stroke-dies.blade.php`**

Baris 40 saat ini:
```blade
    <a href="{{ route('maintenance.control-stroke-dies') }}" class="erp-tb-btn"><i class="bi bi-arrow-clockwise"></i>Refresh</a>
```
Tambah PERSIS setelahnya, sebelum `<span class="erp-tb-sp"></span>` (baris 41):
```blade
    <a href="{{ route('maintenance.control-stroke-dies.schedule-pdf') }}" target="_blank" class="erp-tb-btn">
        <i class="bi bi-file-earmark-pdf"></i>Schedule PDF
    </a>
    @if(auth()->user()->hasRole(['admin', 'staff_maintenance']))
    <form method="POST" action="{{ route('maintenance.control-stroke-dies.schedule-generate') }}" style="display:inline"
          onsubmit="return confirm('Generate ulang jadwal preventive bulan ini? Jadwal lama akan ditimpa.');">
        @csrf
        <button type="submit" class="erp-tb-btn"><i class="bi bi-arrow-repeat"></i>Generate Ulang</button>
    </form>
    @endif
```

- [ ] **Step 5: Jalankan SEMUA test yang relevan**

```bash
php artisan test --filter=PreventiveSchedule
php artisan test --filter=JadwalkanPreventive
```

Expected: semua PASS (termasuk `test_generate_manual_admin_boleh_lalu_pdf_tampil` dari
Task 5 yang butuh view PDF asli ini).

- [ ] **Step 6: Verifikasi manual render PDF (kalau memungkinkan di environment ini)**

```bash
php artisan tinker --execute="
\$u = App\Models\User::where('role', 'admin')->first() ?? App\Models\User::factory()->create(['role'=>'admin']);
echo 'Cek manual: buka route schedulePdf sebagai user ini di browser kalau DB/MySQL tersedia di environment ini.';
"
```

Kalau MySQL/browser TIDAK tersedia di environment ini (sandbox git-worktree — cek
`mysql.server status` dulu), laporkan DONE_WITH_CONCERNS: layout PDF cuma
tervalidasi lewat `assertHeader('content-type','application/pdf')` (test Task 5),
BUKAN divalidasi visual — user WAJIB buka PDF-nya manual sebelum merge buat pastikan
kalender/warna/tanda-tangan match template.

- [ ] **Step 7: Commit**

```bash
git add resources/views/maintenance/preventive-schedule-pdf.blade.php resources/views/maintenance/control-stroke-mesin.blade.php resources/views/maintenance/control-stroke-dies.blade.php
git commit -m "feat(preventive-schedule): layout PDF kalender + tombol Schedule PDF/Generate Ulang"
```

---

### Task 7: Regresi — full suite + gerbang migrasi + pint

**Files:** (nol file baru — verifikasi murni)

- [ ] **Step 1: Full suite**

```bash
php artisan test
```

Expected: semua PASS, nol regresi di luar test baru yang ditambahkan Task 3-5.

- [ ] **Step 2: Gerbang cakupan migrasi**

```bash
./scripts/check-migration-coverage.sh
```

Expected: PASS — 2 migrasi baru (Task 1) terdaftar di `deploy.yml`.

- [ ] **Step 3: Pint**

```bash
vendor/bin/pint app tests --test
```

Expected: nol file butuh fix, atau kalau ada jalankan `vendor/bin/pint app tests`
(tanpa `--test`) buat auto-fix lalu commit terpisah.

- [ ] **Step 4: Commit (kalau Step 3 menghasilkan perubahan)**

```bash
git add -A
git commit -m "style: pint"
```

Kalau Step 1-3 nol menemukan masalah, task ini nol menghasilkan commit tambahan —
lewati Step 4.
