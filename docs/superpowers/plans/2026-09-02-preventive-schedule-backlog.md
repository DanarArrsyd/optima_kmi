# Schedule Preventive — Backlog Persisten Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Item eligible yang kehabisan slot bulanan tidak lagi hilang — dicatat sebagai baris backlog (`tanggal_rencana = null`) dengan `backlog_since` yang persisten lintas generate, termasuk lintas bulan.

**Architecture:** Migrasi ALTER `preventive_schedule_items` (2 kolom nullable + 1 kolom baru). `PreventiveScheduleService::generate()` merekam map backlog lama (sumbernya beda tergantung regenerate-bulan-sama vs generate-bulan-baru) sebelum delete, lalu setelah loop assignment selesai membuat baris backlog untuk sisa item eligible yang tidak kebagian slot. Controller PDF difilter `whereNotNull('tanggal_rencana')` biar baris backlog tidak ikut ke view kalender.

**Tech Stack:** Laravel 13, MySQL/SQLite, PHPUnit.

## Global Constraints

- Spec: `docs/superpowers/specs/2026-09-02-preventive-schedule-backlog-design.md` — baca dulu kalau ada keraguan detail.
- Migrasi baru WAJIB didaftarkan sebagai `--path=` baru di `.github/workflows/deploy.yml` step "Migrate (targeted) di server" — PR yang sama, bukan PR terpisah.
- Nol perubahan tampilan PDF/UI — PDF Schedule Preventive tetap nampilin item terjadwal doang (backlog difilter keluar di controller, bukan di view).
- 12 test lama `PreventiveScheduleServiceTest` WAJIB tetap lolos TANPA diubah (dikonfirmasi manual: nol satupun fixture melewati kapasitas mingguan).
- `backlog_since` pakai `now()->timezone('Asia/Jakarta')->toDateString()` — konsisten sama cara service nentuin "hari ini" di fase 1.
- SoftDeletes tidak berlaku ke `preventive_schedule_items` (model existing nol pakai trait itu) — jangan ditambahkan, di luar cakupan.

---

### Task 1: Migrasi kolom backlog + fillable/cast model

**Files:**
- Create: `database/migrations/2026_09_02_000001_add_backlog_columns_to_preventive_schedule_items_table.php`
- Modify: `app/Models/PreventiveScheduleItem.php`
- Modify: `.github/workflows/deploy.yml:164` (tambah baris `--path=` baru setelah baris `2026_08_31_000002...`)
- Test: `tests/Feature/PreventiveScheduleItemsMigrationTest.php`

**Interfaces:**
- Produces: kolom `preventive_schedule_items.tanggal_rencana` (nullable), `preventive_schedule_items.minggu_ke` (nullable), `preventive_schedule_items.backlog_since` (date, nullable) — dipakai Task 2.
- Produces: `PreventiveScheduleItem::$fillable` nambah `'backlog_since'`, `$casts` nambah `'backlog_since' => 'date:Y-m-d'`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PreventiveScheduleItemsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kolom_backlog_ada_dan_tanggal_rencana_minggu_ke_nullable(): void
    {
        $this->assertTrue(Schema::hasColumn('preventive_schedule_items', 'backlog_since'));

        $columns = DB::select("PRAGMA table_info(preventive_schedule_items)");
        $byName = collect($columns)->keyBy('name');

        $this->assertSame(0, $byName['tanggal_rencana']->notnull, 'tanggal_rencana harus nullable');
        $this->assertSame(0, $byName['minggu_ke']->notnull, 'minggu_ke harus nullable');
        $this->assertSame(1, $byName['backlog_since']->notnull === 0 ? 1 : 0);
    }
}
```

Tambahkan `use Illuminate\Support\Facades\DB;` di atas.

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=PreventiveScheduleItemsMigrationTest`
Expected: FAIL — kolom `backlog_since` belum ada (`Schema::hasColumn` return false), atau `notnull` tanggal_rencana masih 1.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->date('tanggal_rencana')->nullable()->change();
            $table->unsignedTinyInteger('minggu_ke')->nullable()->change();
            $table->date('backlog_since')->nullable()->after('minggu_ke');
        });
    }

    public function down(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->dropColumn('backlog_since');
            $table->date('tanggal_rencana')->nullable(false)->change();
            $table->unsignedTinyInteger('minggu_ke')->nullable(false)->change();
        });
    }
};
```

Catatan: `->change()` di SQLite butuh `doctrine/dbal` — proyek ini sudah pakai `->change()` di migrasi lain (mis. `2026_07_12_000001_change_tipe_mesin_to_string_on_master_mesin.php`), jadi dependency-nya sudah terpasang. Kalau `composer.json` belum punya `doctrine/dbal`, cek dulu (`composer show doctrine/dbal`) sebelum lanjut — kalau memang belum ada, laporkan sebagai BLOCKED, jangan install sendiri tanpa izin.

- [ ] **Step 4: Update model**

Modify `app/Models/PreventiveScheduleItem.php`:

```php
    protected $fillable = [
        'schedule_id', 'id_mesin', 'id_part_proses',
        'stroke_snapshot', 'pct_snapshot', 'threshold_snapshot',
        'tanggal_rencana', 'minggu_ke', 'backlog_since',
    ];

    protected $casts = [
        'tanggal_rencana' => 'date:Y-m-d',
        'pct_snapshot' => 'float',
        'backlog_since' => 'date:Y-m-d',
    ];
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=PreventiveScheduleItemsMigrationTest`
Expected: PASS

- [ ] **Step 6: Daftarkan migrasi baru di deploy.yml**

Edit `.github/workflows/deploy.yml`, tambah baris setelah baris `2026_08_31_000002_create_preventive_schedule_items_table.php"` (pindahkan tanda kutip penutup `"` ke baris baru):

```
              --path=database/migrations/2026_08_31_000001_create_preventive_schedules_table.php \
              --path=database/migrations/2026_08_31_000002_create_preventive_schedule_items_table.php \
              --path=database/migrations/2026_09_02_000001_add_backlog_columns_to_preventive_schedule_items_table.php"
```

- [ ] **Step 7: Verifikasi gerbang cakupan migrasi**

Run: `./scripts/check-migration-coverage.sh`
Expected: lolos (migrasi baru terdaftar).

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_09_02_000001_add_backlog_columns_to_preventive_schedule_items_table.php \
  app/Models/PreventiveScheduleItem.php .github/workflows/deploy.yml \
  tests/Feature/PreventiveScheduleItemsMigrationTest.php
git commit -m "feat(preventive-schedule): kolom backlog_since + tanggal_rencana/minggu_ke nullable"
```

---

### Task 2: `generate()` — rekam backlog lama, buat baris backlog buat item tak kebagian slot

**Files:**
- Modify: `app/Services/PreventiveScheduleService.php`
- Test: `tests/Unit/PreventiveScheduleServiceTest.php` (tambah test baru di akhir file, JANGAN ubah test yang sudah ada)

**Interfaces:**
- Consumes: `PreventiveScheduleItem` kolom `backlog_since` (Task 1), `tanggal_rencana`/`minggu_ke` nullable (Task 1).
- Consumes: `PreventiveSchedule::where('jenis', $jenis)->where('bulan', '<', $bulan)->orderByDesc('bulan')->first()` — pola query Eloquent standar, tidak butuh method baru.
- Produces: `generate()` tetap return `PreventiveSchedule` (`$schedule->fresh('items')`) — signature nol berubah, dipakai controller existing (`ControlStrokeMesinController::scheduleGenerate()`, `ControlStrokeDiesController::scheduleGenerate()`) — nol perlu disentuh.

- [ ] **Step 1: Write the failing tests**

Tambahkan di akhir `class PreventiveScheduleServiceTest` (sebelum `}` penutup class), pakai helper `mesinDenganStroke()` yang sudah ada di file (bikin mesin dengan stroke tinggi → otomatis eligible pct≥100, threshold 100 dari `kapasitas_max_stroke` di helper existing):

```php
    /** Kapasitas mesin cuma 1/minggu — 2 mesin eligible bulan yang cuma 1 hari kerja diizinkan (mesin) di minggu pertama bikin 1 item nggak kebagian slot minggu itu, tapi masih ada minggu berikutnya jadi nggak backlog. Paksa backlog beneran: bikin eligible LEBIH BANYAK dari total kapasitas sebulan penuh. Agustus 2026 py 4 minggu kerja mesin (lihat ledger fase 1) — kapasitas 1/minggu x4 = 4 slot. Bikin 5 mesin eligible.) */
    public function test_item_eligible_yang_kehabisan_slot_jadi_baris_backlog(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->mesinDenganStroke("BL{$i}", 100);
        }

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $terjadwal = $schedule->items->whereNotNull('tanggal_rencana');
        $backlog = $schedule->items->whereNull('tanggal_rencana');

        $this->assertCount(4, $terjadwal, 'kapasitas mesin Agustus 2026 = 4 slot (1/minggu x4 minggu kerja)');
        $this->assertCount(1, $backlog, 'sisa 1 mesin eligible nggak kebagian slot harus jadi backlog');
        $this->assertSame('2026-08-20', $backlog->first()->backlog_since->toDateString(), 'backlog_since = hari generate (Carbon::setTestNow di setUp)');
        $this->assertNull($backlog->first()->minggu_ke);
    }

    public function test_backlog_since_nyambung_lintas_bulan_bukan_reset(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->mesinDenganStroke("XB{$i}", 100);
        }
        app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        Carbon::setTestNow('2026-09-05');
        $septemberSchedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-09-01'));

        $backlogSeptember = $septemberSchedule->items->whereNull('tanggal_rencana');
        $this->assertGreaterThanOrEqual(1, $backlogSeptember->count(), 'item backlog Agustus yang masih eligible harus tetap backlog di September');
        $this->assertSame('2026-08-20', $backlogSeptember->first()->backlog_since->toDateString(), 'backlog_since HARUS tetap tanggal Agustus, bukan direset ke September');
    }

    public function test_item_yang_kebagian_slot_backlog_since_null(): void
    {
        $this->mesinDenganStroke('OK1', 100);

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertNull($schedule->items->first()->backlog_since);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=PreventiveScheduleServiceTest`
Expected: 3 test baru FAIL (backlog rows belum dibuat sama sekali — `whereNull('tanggal_rencana')` count 0, bukan error kolom karena Task 1 sudah bikin kolomnya ada). 12 test lama tetap PASS.

- [ ] **Step 3: Implement — modify `generate()`**

Replace method `generate()` di `app/Services/PreventiveScheduleService.php` (baris 47-133) dengan:

```php
    public function generate(string $jenis, Carbon $bulan): PreventiveSchedule
    {
        if (! array_key_exists($jenis, self::KAPASITAS)) {
            throw new \InvalidArgumentException("Jenis preventive schedule tidak dikenal: {$jenis}");
        }

        $bulan = $bulan->copy()->startOfMonth();

        return DB::transaction(function () use ($jenis, $bulan) {
            $schedule = PreventiveSchedule::firstOrNew([
                'bulan' => $bulan->toDateString(),
                'jenis' => $jenis,
            ]);
            $sudahAda = $schedule->exists;
            if (! $sudahAda) {
                $schedule->nomor_dokumen = $this->nomorDokumen($jenis, $bulan);
            }
            $schedule->generated_at = now();
            $schedule->generated_by = auth()->id();
            $schedule->save();

            $backlogLama = $this->backlogSinceLama($jenis, $bulan, $sudahAda ? $schedule : null);

            $schedule->items()->delete();

            $eligible = $this->eligibleItems($jenis, $bulan);
            $hariKerja = $this->hariKerjaDalamBulan($bulan)
                ->filter(fn (Carbon $d) => in_array($d->isoWeekday(), self::HARI_DIIZINKAN[$jenis], true))
                ->values();
            $mingguBuckets = $this->kelompokkanPerMinggu($hariKerja);
            $kapasitas = self::KAPASITAS[$jenis];
            $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

            $idx = 0;
            // Slot diisi urut prioritas (proyeksi paling dekat duluan), TAPI tanggal
            // PLAN yang didapat murni posisi slot pertama tersedia — nol usaha
            // menyelaraskan PLAN persis sebelum proyeksi_overdue item itu sendiri.
            // Item yang proyeksinya jauh (mis. akhir bulan) bisa kebagian slot di
            // awal bulan kalau dia kandidat prioritas tertinggi bulan itu — trade-off
            // sengaja, bukan bug: "earliest available slot wins" tetap lebih
            // sederhana & predictable daripada alokasi presisi-tanggal untuk fase 1.
            // Kalau ini jadi masalah nyata (banyak siklus stroke kebuang), itu
            // kandidat kerjaan fase 2.
            foreach ($mingguBuckets as $mingguIndex => $hariList) {
                $mingguKe = $mingguIndex + 1;
                $n = $hariList->count();
                // Offset berputar per minggu — minggu 1 mulai dari hari
                // pertama daftar hari-diizinkan, minggu 2 geser satu, dst,
                // muter balik ke 0 kalau sudah lewat jumlah hari diizinkan.
                // Ini yang mencegah PLAN mesin selalu jatuh Senin.
                $offset = $n > 0 ? $mingguIndex % $n : 0;

                for ($slot = 0; $slot < $kapasitas; $slot++) {
                    if ($idx >= $eligible->count()) {
                        break 2;
                    }
                    if ($slot >= $n) {
                        // Minggu ini kehabisan hari diizinkan buat slot ke-$slot
                        // — kapasitas sisa minggu ini HANGUS, bukan dipindah ke
                        // minggu lain.
                        continue;
                    }

                    $posisi = ($offset + $slot) % $n;
                    $hari = $hariList[$posisi];

                    $item = $eligible[$idx];
                    PreventiveScheduleItem::create([
                        'schedule_id' => $schedule->id,
                        'id_mesin' => $jenis === 'mesin' ? $item['id_mesin'] : null,
                        'id_part_proses' => $jenis === 'dies' ? $item['id_part_proses'] : null,
                        'stroke_snapshot' => $item['current_stroke'],
                        'pct_snapshot' => $item['pct'],
                        'threshold_snapshot' => $item['threshold'],
                        'tanggal_rencana' => $hari->toDateString(),
                        'minggu_ke' => $mingguKe,
                        'backlog_since' => null,
                    ]);
                    $idx++;
                }
            }

            if ($idx < $eligible->count()) {
                $idxSebelumBacklog = $idx;
                $sisaHariIni = now()->timezone('Asia/Jakarta')->toDateString();
                for (; $idx < $eligible->count(); $idx++) {
                    $item = $eligible[$idx];
                    PreventiveScheduleItem::create([
                        'schedule_id' => $schedule->id,
                        'id_mesin' => $jenis === 'mesin' ? $item['id_mesin'] : null,
                        'id_part_proses' => $jenis === 'dies' ? $item['id_part_proses'] : null,
                        'stroke_snapshot' => $item['current_stroke'],
                        'pct_snapshot' => $item['pct'],
                        'threshold_snapshot' => $item['threshold'],
                        'tanggal_rencana' => null,
                        'minggu_ke' => null,
                        'backlog_since' => $backlogLama[$item[$kolomId]] ?? $sisaHariIni,
                    ]);
                }
                Log::info(sprintf(
                    'PreventiveSchedule %s %s: %d item eligible dicatat sebagai backlog (kapasitas bulan ini habis).',
                    $jenis, $bulan->toDateString(), $eligible->count() - $idxSebelumBacklog
                ));
            }

            return $schedule->fresh('items');
        });
    }

    /**
     * Map target_id => backlog_since LAMA, dipakai carry-over supaya
     * backlog_since nggak reset tiap generate. Sumbernya beda tergantung
     * situasi (lihat spec bagian "Logika generate()"):
     * - Regenerate bulan SAMA ($scheduleExists non-null) → baris lama
     *   milik schedule itu sendiri (sebentar lagi dihapus & dibangun ulang).
     * - Generate bulan BARU ($scheduleExists null) → schedule jenis yang
     *   sama di bulan SEBELUM $bulan yang PALING BARU.
     *
     * @return array<int, string> [id_mesin_atau_id_part_proses => 'Y-m-d']
     */
    private function backlogSinceLama(string $jenis, Carbon $bulan, ?PreventiveSchedule $scheduleExists): array
    {
        $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

        if ($scheduleExists !== null) {
            $sumber = $scheduleExists;
        } else {
            $sumber = PreventiveSchedule::where('jenis', $jenis)
                ->where('bulan', '<', $bulan->toDateString())
                ->orderByDesc('bulan')
                ->first();
        }

        if ($sumber === null) {
            return [];
        }

        return $sumber->items()
            ->whereNull('tanggal_rencana')
            ->whereNotNull('backlog_since')
            ->get([$kolomId, 'backlog_since'])
            ->mapWithKeys(fn ($row) => [$row->{$kolomId} => $row->backlog_since->toDateString()])
            ->all();
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=PreventiveScheduleServiceTest`
Expected: semua 15 test (12 lama + 3 baru) PASS.

- [ ] **Step 5: Full regression**

Run: `php artisan test`
Expected: semua test suite lolos (nol regresi di file lain — `generate()` signature nol berubah).

- [ ] **Step 6: Commit**

```bash
git add app/Services/PreventiveScheduleService.php tests/Unit/PreventiveScheduleServiceTest.php
git commit -m "feat(preventive-schedule): buat baris backlog utk item eligible tak kebagian slot, backlog_since nyambung lintas bulan"
```

---

### Task 3: Filter backlog keluar dari query PDF controller

**Files:**
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php:100-103`
- Modify: `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php:122-125`
- Test: `tests/Feature/PreventiveScheduleBacklogPdfFilterTest.php`

**Interfaces:**
- Consumes: `PreventiveScheduleItem::backlog_since`, `tanggal_rencana` nullable (Task 1), backlog rows dari `generate()` (Task 2).
- Produces: nol perubahan ke view `resources/views/maintenance/preventive-schedule-pdf.blade.php` — item yang sampai ke situ tetap selalu `tanggal_rencana` non-null seperti sebelumnya.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\PreventiveScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PreventiveScheduleBacklogPdfFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_pdf_mesin_nol_render_baris_backlog(): void
    {
        Carbon::setTestNow('2026-08-20');
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $admin = User::factory()->create(['role' => 'admin']);

        for ($i = 1; $i <= 5; $i++) {
            $mesin = MasterMesin::create([
                'id_mesin' => "PDF{$i}", 'nama_mesin' => "Press {$i}", 'line' => '1',
                'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
                'tanggal_instalasi' => '2020-01-01',
            ]);
            $part = MasterPart::create(['part_no' => "P-PDF{$i}", 'part_name' => "Part {$i}", 'customer' => 'Toyota']);
            $proses = MasterPartProses::create([
                'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
                'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
            ]);
            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-07-01', 'id_shift' => $shift->id,
                'id_karyawan' => $karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 100,
                'actual_cycle_time' => 10, 'created_by' => $admin->id,
            ]);
        }

        app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $schedule = PreventiveSchedule::where('jenis', 'mesin')->where('bulan', '2026-08-01')->first();
        $this->assertTrue($schedule->items()->whereNull('tanggal_rencana')->exists(), 'prasyarat: harus ada baris backlog');

        $response = $this->actingAs($admin)->get(route('control-stroke-mesin.schedule-pdf'));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
```

- [ ] **Step 2: Run test to verify current behavior**

Run: `php artisan test --filter=PreventiveScheduleBacklogPdfFilterTest`
Expected: test ini kemungkinan besar SUDAH PASS di level HTTP (dompdf nggak crash walau ada baris backlog, karena Blade view kemungkinan skip baris tanpa tanggal_rencana lewat logic kalender yang sudah ada). Test ini SENGAJA sebagai smoke test regresi, bukan RED-state murni — kalau PASS sebelum Step 3, itu OK, lanjut Step 3 buat filter di level query (lebih eksplisit & aman daripada bergantung ke Blade nggak crash).

- [ ] **Step 3: Implement — filter query controller**

Modify `app/Http/Controllers/Maintenance/ControlStrokeMesinController.php`, ganti baris 100-103:

```php
        $schedule = PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'mesin')
            ->with(['items' => fn ($q) => $q->whereNotNull('tanggal_rencana')->with('mesin.preventiveLogs')])
            ->first();
```

Modify `app/Http/Controllers/Maintenance/ControlStrokeDiesController.php`, ganti baris 122-125:

```php
        $schedule = PreventiveSchedule::where('bulan', $bulan->toDateString())
            ->where('jenis', 'dies')
            ->with(['items' => fn ($q) => $q->whereNotNull('tanggal_rencana')->with('partProses.part', 'partProses.preventiveLogs')])
            ->first();
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=PreventiveScheduleBacklogPdfFilterTest`
Expected: PASS.

- [ ] **Step 5: Full regression**

Run: `php artisan test`
Expected: semua test lolos, termasuk test PDF existing (kalau ada) di `ControlStrokeMesinController`/`ControlStrokeDiesController`.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/Maintenance/ControlStrokeMesinController.php \
  app/Http/Controllers/Maintenance/ControlStrokeDiesController.php \
  tests/Feature/PreventiveScheduleBacklogPdfFilterTest.php
git commit -m "fix(preventive-schedule): filter baris backlog keluar dari query PDF schedule"
```

---

### Task 4: Verifikasi akhir

**Files:** none (verifikasi doang)

- [ ] **Step 1: Full test suite**

Run: `php artisan test`
Expected: semua test PASS, nol regresi.

- [ ] **Step 2: Migration coverage gate**

Run: `./scripts/check-migration-coverage.sh`
Expected: lolos.

- [ ] **Step 3: Pint**

Run: `vendor/bin/pint app tests`
Expected: nol perubahan format (atau auto-fix kalau ada, commit terpisah kecil).

- [ ] **Step 4: PDF visual check (SQLite, temp test)**

Tulis Feature test SEMENTARA (jangan commit) yang generate schedule dengan >kapasitas item eligible, panggil route `schedulePdf`, simpan bytes PDF ke `/private/tmp/claude-*/scratchpad/schedule-backlog-check.pdf`, pakai Read tool buat lihat visual — pastikan PDF nol nampilin baris kosong/error buat item backlog (karena sudah difilter di query, seharusnya PDF identik visual sama sebelum fitur ini). Hapus test sementara setelah selesai cek.
