# Schedule Preventive Cerdas — Proyeksi + Restriksi Hari + Rotasi (Fase 1) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `PreventiveScheduleService::generate()` jadi prediktif — proyeksi tanggal
overdue dari laju stroke 30 hari (bukan cuma status SEKARANG), restriksi hari PLAN
per jenis (mesin Senin-Kamis, dies Senin-Jumat), dan rotasi hari biar nol monoton di
Senin terus.

**Architecture:** Semua perubahan di dalam `app/Services/PreventiveScheduleService.php`
— nol tabel baru, nol migrasi, nol perubahan controller/view/route. `eligibleItems()`
diperluas jadi union (pct≥80% ATAU proyeksi overdue sebelum generate bulan depan),
urut proyeksi ascending (ganti pct desc). Slot assignment di `generate()` difilter per
hari-diizinkan-per-jenis, lalu posisi ambil slot pakai offset berputar per minggu.

**Tech Stack:** Laravel 13, PHP 8.4-compatible, MySQL prod / SQLite test.

## Global Constraints

- Nol tabel/kolom baru — nol migrasi, nol perlu `deploy.yml`/`check-migration-coverage.sh`.
- Nol perubahan `computeCurrentCycle()`/threshold/status di
  `MaintenanceDashboardService`/`MaintenanceDiesDashboardService` — murni konsumen.
- Nol perubahan tampilan PDF/UI — proyeksi & rotasi murni internal, nol kolom baru di
  PDF/kertas.
- Query laju WAJIB batch (1 query per jenis, `GROUP BY`) — DILARANG N+1 per item, dies
  bisa ratusan baris.
- Fase 2 (revisi immutable, backlog persisten, notifikasi, lock concurrency) DI LUAR
  cakupan plan ini — jangan diimplementasi sekarang. Referensi desain fase 2 ada di
  branch `docs/preventive-schedule-fase2-reference` kalau suatu saat dilanjutkan.
- Spec: `docs/superpowers/specs/2026-08-31-preventive-schedule-cerdas-design.md`

---

### Task 1: Proyeksi tanggal overdue + prioritas berbasis proyeksi

**Files:**
- Modify: `app/Services/PreventiveScheduleService.php`
- Test: `tests/Unit/PreventiveScheduleServiceTest.php` (tambah test baru, JANGAN ubah
  8 test yang sudah ada — lihat catatan di Step 1)

**Interfaces:**
- Consumes: `MaintenanceDashboardService::computeCurrentCycle()` /
  `MaintenanceDiesDashboardService::computeCurrentCycle()` (nol berubah, baris array
  dengan key `id_mesin`/`id_part_proses`, `current_stroke`, `threshold`, `pct`,
  `status`), model `TransaksiProduksi` (kolom `tanggal_produksi`, `qty_aktual`,
  `qty_ng`, `id_mesin`, `id_part_proses`).
- Produces: `eligibleItems(string $jenis, Carbon $bulan): Collection` — signature
  BERUBAH (tambah parameter `$bulan`), dipakai `generate()` di baris yang sama
  (`$eligible = $this->eligibleItems($jenis, $bulan);`, sebelumnya
  `$this->eligibleItems($jenis)`). Method baru `lajuHarian(string $jenis): array`
  (private, dipakai `eligibleItems()`).

**Catatan penting sebelum mulai:** 8 test yang SUDAH ADA di
`PreventiveScheduleServiceTest.php` nol perlu diubah — semua fixture-nya pakai
`pct ≥ 100%` (overdue), yang di logika baru tetap masuk lewat cabang
`pct ≥ 100 → proyeksi = hari ini` TERLEPAS dari laju (laju bisa 0 karena fixture
`tanggal_produksi` selalu `2026-07-01`, jauh di luar window 30-hari relatif ke jam
sistem beneran pas suite jalan — itu TIDAK masalah, item overdue tetap eligible lewat
jalur `pct≥100`, bukan lewat jalur laju). Jalankan 8 test itu di Step 5 buat
MEMBUKTIKAN asumsi ini benar — kalau ada yang merah, itu sinyal ada bug di logika
baru, bukan alasan buat mengubah fixture test lama.

- [ ] **Step 1: Tambah 3 test proyeksi (RED dulu)**

Tambahkan method baru berikut ke `tests/Unit/PreventiveScheduleServiceTest.php`,
SEBELUM `}` penutup class (setelah `test_jenis_tidak_dikenal_dilempar_...`). Reuse
helper `mesinDenganStroke()`/`diesDenganStroke()` yang sudah ada TIDAK cukup untuk
test ini (butuh 2 baris produksi per mesin: satu di luar window 30 hari, satu di
dalam) — tulis fixture inline di dalam test:

```php
    /**
     * Item di bawah pct reaktif (80%) tapi laju produksinya tinggi sehingga
     * DIPROYEKSIKAN overdue sebelum generate bulan depan — harus tetap masuk
     * jadwal PROAKTIF, dan diurut proyeksi PALING DEKAT duluan (bukan pct
     * tertinggi duluan seperti sebelumnya).
     *
     * Angka dihitung manual supaya deterministik — baca komentar di tiap baris
     * sebelum mengubah nilai apa pun:
     *
     * EARLY: current_stroke 220.000 (73,3%) — di bawah 80%, TIDAK reaktif.
     *   Baris lama (di luar window 30 hari dari "sekarang" 2026-08-15): 50.000.
     *   Baris baru (dalam window, 2026-08-01): 170.000 → laju = 170.000/30 ≈ 5.666,67/hari.
     *   Sisa = 300.000 - 220.000 = 80.000. Hari = ceil(80.000/5.666,67) = 15.
     *   Proyeksi = 2026-08-15 + 15 hari = 2026-08-30 (SEBELUM cutoff 2026-09-01).
     *
     * LATE: current_stroke 236.000 (78,7%) — juga di bawah 80%, TIDAK reaktif.
     *   Baris lama: 116.000. Baris baru (2026-08-01): 120.000 → laju = 120.000/30 = 4.000/hari.
     *   Sisa = 300.000 - 236.000 = 64.000. Hari = ceil(64.000/4.000) = 16.
     *   Proyeksi = 2026-08-15 + 16 hari = 2026-08-31 (SEBELUM cutoff, TAPI setelah EARLY).
     *
     * FAR: current_stroke 100.000 (33,3%) — jauh dari threshold.
     *   Semua produksi dalam window: 100.000 → laju = 100.000/30 ≈ 3.333,33/hari.
     *   Sisa = 200.000. Hari = ceil(200.000/3.333,33) = 60.
     *   Proyeksi = 2026-08-15 + 60 hari = 2026-10-14 (SETELAH cutoff 2026-09-01) → TIDAK eligible.
     */
    public function test_item_dibawah_pct_reaktif_tapi_proyeksi_dekat_tetap_masuk_dan_urut_lebih_dulu(): void
    {
        Carbon::setTestNow('2026-08-15');

        $admin = User::factory()->create(['role' => 'admin']);

        $buatMesin = function (string $kode, int $strokeLama, int $strokeBaru) use ($admin) {
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
            if ($strokeLama > 0) {
                TransaksiProduksi::create([
                    'tanggal_produksi' => '2026-06-01', 'id_shift' => $this->shift->id,
                    'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                    'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $strokeLama,
                    'actual_cycle_time' => 10, 'created_by' => $admin->id,
                ]);
            }
            TransaksiProduksi::create([
                'tanggal_produksi' => '2026-08-01', 'id_shift' => $this->shift->id,
                'id_karyawan' => $this->karyawan->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
                'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => $strokeBaru,
                'actual_cycle_time' => 10, 'created_by' => $admin->id,
            ]);

            return $mesin;
        };

        $buatMesin('EARLY', 50000, 170000);  // current_stroke 220.000, proyeksi 2026-08-30
        $buatMesin('LATE', 116000, 120000);  // current_stroke 236.000, proyeksi 2026-08-31
        $buatMesin('FAR', 0, 100000);        // current_stroke 100.000, proyeksi 2026-10-14 (excluded)

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));
        $items = $schedule->items()->with('mesin')->get()->keyBy(fn ($i) => $i->mesin->id_mesin);

        $this->assertCount(2, $items, 'FAR seharusnya tidak eligible (proyeksi lewat cutoff).');
        $this->assertArrayNotHasKey('FAR', $items);
        $this->assertArrayHasKey('EARLY', $items);
        $this->assertArrayHasKey('LATE', $items);
        $this->assertTrue(
            $items['EARLY']->tanggal_rencana->lt($items['LATE']->tanggal_rencana),
            'EARLY (proyeksi lebih dekat) harus dapat slot lebih awal dari LATE, walau pct EARLY (73,3%) lebih rendah dari LATE (78,7%).'
        );

        Carbon::setTestNow();
    }
```

- [ ] **Step 2: Jalankan test baru, pastikan gagal**

```bash
php artisan test --filter=test_item_dibawah_pct_reaktif_tapi_proyeksi_dekat_tetap_masuk_dan_urut_lebih_dulu
```

Expected: FAIL — `assertCount(2, ...)` gagal (saat ini cuma `pct≥80` yang eligible,
ketiga mesin di bawah 80% jadi `$items` kosong / count 0).

- [ ] **Step 3: Tambah `lajuHarian()` dan ganti `eligibleItems()`**

Di `app/Services/PreventiveScheduleService.php`, tambah `use App\Models\TransaksiProduksi;`
ke daftar `use` di baris atas file (setelah `use App\Models\PreventiveScheduleItem;`).

Ganti method `eligibleItems()` yang ada (baris ~90-99) jadi:

```php
    /**
     * Laju stroke per hari, SATU query batch per jenis (bukan N+1 per item — dies
     * bisa ratusan baris). Window 30 hari kalender SELESAI sebelum hari ini
     * (T-30 s.d. T-1) — hari T sendiri tidak dihitung karena datanya masih parsial.
     *
     * @return array<int, float> [id_mesin_atau_id_part_proses => stroke/hari]
     */
    private function lajuHarian(string $jenis): array
    {
        $akhir = Carbon::now()->subDay()->toDateString();
        $mulai = Carbon::now()->subDays(30)->toDateString();
        $kolom = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

        return TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$mulai, $akhir])
            ->selectRaw("{$kolom} as target_id, COALESCE(SUM(qty_aktual + COALESCE(qty_ng,0)),0) as total")
            ->groupBy($kolom)
            ->pluck('total', 'target_id')
            ->map(fn ($total) => (float) $total / 30)
            ->all();
    }

    /**
     * Union dua kriteria — pct≥80% (perilaku lama, safety net) ATAU proyeksi
     * overdue sebelum generate bulan depan (proaktif, baru) — diurut proyeksi
     * PALING DEKAT duluan. Item overdue sekarang (pct≥100) otomatis proyeksi=hari
     * ini, jadi selalu paling depan.
     */
    private function eligibleItems(string $jenis, Carbon $bulan): Collection
    {
        $rows = collect($jenis === 'mesin' ? $this->mesinSvc->computeCurrentCycle() : $this->diesSvc->computeCurrentCycle());
        $laju = $this->lajuHarian($jenis);
        $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';
        $today = Carbon::now();
        $batasProyeksi = $bulan->copy()->addMonthNoOverflow()->startOfMonth();

        return $rows
            ->map(function ($row) use ($laju, $kolomId, $today) {
                $rate = $laju[$row[$kolomId]] ?? 0.0;
                $sisa = max(0, $row['threshold'] - $row['current_stroke']);

                if ($row['pct'] >= 100) {
                    $proyeksi = $today->copy();
                } elseif ($rate > 0) {
                    $proyeksi = $today->copy()->addDays((int) ceil($sisa / $rate));
                } elseif ($row['pct'] >= 80) {
                    $proyeksi = $today->copy();
                } else {
                    $proyeksi = null;
                }

                $row['proyeksi_overdue'] = $proyeksi;

                return $row;
            })
            ->filter(fn ($row) => $row['pct'] >= 80 || ($row['proyeksi_overdue'] && $row['proyeksi_overdue']->lt($batasProyeksi)))
            ->sortBy(fn ($row) => $row['proyeksi_overdue']?->timestamp ?? PHP_INT_MAX)
            ->values();
    }
```

Lalu di method `generate()` (baris ~52), ganti baris:
```php
            $eligible = $this->eligibleItems($jenis);
```
jadi:
```php
            $eligible = $this->eligibleItems($jenis, $bulan);
```

- [ ] **Step 4: Jalankan test baru, pastikan lolos**

```bash
php artisan test --filter=test_item_dibawah_pct_reaktif_tapi_proyeksi_dekat_tetap_masuk_dan_urut_lebih_dulu
```

Expected: PASS.

- [ ] **Step 5: Jalankan SEMUA test di file ini (regresi 8 test lama + 1 baru)**

```bash
php artisan test --filter=PreventiveScheduleServiceTest
```

Expected: 9/9 PASS. Kalau ada yang merah selain yang baru ditambah, BACA pesan
galatnya — itu tandanya asumsi "overdue selalu lolos lewat pct≥100 regardless of
laju" di catatan Step 1 salah, JANGAN ubah fixture buat nutupin, cari akar masalah
di `eligibleItems()`/`lajuHarian()`.

- [ ] **Step 6: Commit**

```bash
git add app/Services/PreventiveScheduleService.php tests/Unit/PreventiveScheduleServiceTest.php
git commit -m "feat(preventive-schedule): proyeksi tanggal overdue dari laju 30 hari"
```

---

### Task 2: Restriksi hari per jenis + rotasi

**Files:**
- Modify: `app/Services/PreventiveScheduleService.php`
- Test: `tests/Unit/PreventiveScheduleServiceTest.php`

**Interfaces:**
- Consumes: `eligibleItems()` (Task 1, sudah urut proyeksi), `hariKerjaDalamBulan()`
  dan `kelompokkanPerMinggu()` (sudah ada, nol berubah signature).
- Produces: const `HARI_DIIZINKAN` baru (`['mesin' => [1,2,3,4], 'dies' => [1,2,3,4,5]]`).
  Nol method publik baru — perubahan murni internal di `generate()`.

- [ ] **Step 1: Tambah 2 test restriksi hari (RED dulu)**

Tambahkan ke `tests/Unit/PreventiveScheduleServiceTest.php`, setelah test dari Task 1:

```php
    /** Mesin cuma boleh PLAN Senin-Kamis — Jumat (14 jam) & Sabtu (10 jam) kependekan buat PM penuh. */
    public function test_plan_mesin_nol_pernah_jatuh_jumat_atau_sabtu(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->mesinDenganStroke("A{$i}", 310000);
        }

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        foreach ($schedule->items as $item) {
            $this->assertNotContains(
                $item->tanggal_rencana->isoWeekday(),
                [5, 6],
                "tanggal_rencana {$item->tanggal_rencana->toDateString()} jatuh di Jumat/Sabtu — mesin cuma boleh Senin-Kamis."
            );
        }
    }

    /**
     * Dies boleh sampai Jumat (kapasitas 5/minggu, hari diizinkan persis 5 —
     * Senin-Jumat — jadi minggu penuh otomatis makai SEMUA hari termasuk Jumat).
     * Agustus 2026: 1 Agustus jatuh Sabtu (dikeluarkan dari hari diizinkan
     * dies/mesin dua-duanya), jadi bucket minggu PERTAMA yang punya kandidat
     * hari diizinkan dies adalah Senin 3 - Jumat 7 Agustus, persis 5 hari kerja.
     */
    public function test_plan_dies_boleh_jumat_tapi_nol_sabtu_minggu(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->diesDenganStroke("D{$i}", 31000);
        }

        $schedule = app(PreventiveScheduleService::class)->generate('dies', Carbon::parse('2026-08-01'));

        $this->assertCount(5, $schedule->items, 'Setup salah: 5 dies overdue harus semua kebagian slot minggu pertama (kapasitas 5).');

        $weekdaysDipakai = $schedule->items->map(fn ($item) => $item->tanggal_rencana->isoWeekday())->sort()->values();

        $this->assertSame([1, 2, 3, 4, 5], $weekdaysDipakai->all(), 'Minggu penuh 5 dies harus makai persis Senin s.d. Jumat.');
        foreach ($schedule->items as $item) {
            $this->assertNotContains($item->tanggal_rencana->isoWeekday(), [6, 7], 'Dies nol boleh Sabtu/Minggu.');
        }
    }

    /** Rotasi: mesin di minggu berbeda nol boleh selalu jatuh hari yang sama (mis. Senin terus). */
    public function test_rotasi_hari_mesin_antar_minggu_nol_monoton(): void
    {
        $this->mesinDenganStroke('W1', 310000);
        $this->mesinDenganStroke('W2', 305000);

        $schedule = app(PreventiveScheduleService::class)->generate('mesin', Carbon::parse('2026-08-01'));

        $this->assertCount(2, $schedule->items);

        $terurut = $schedule->items->sortBy('tanggal_rencana')->values();
        $hariMinggu1 = $terurut[0]->tanggal_rencana->isoWeekday();
        $hariMinggu2 = $terurut[1]->tanggal_rencana->isoWeekday();

        $this->assertNotSame($hariMinggu1, $hariMinggu2, 'Rotasi nol jalan — dua mesin di minggu berbeda jatuh hari yang SAMA.');
    }
```

- [ ] **Step 2: Jalankan 3 test baru, pastikan gagal**

```bash
php artisan test --filter=PreventiveScheduleServiceTest
```

Expected: 3 test baru dari Step 1 FAIL (`test_plan_mesin_nol_pernah_jatuh_jumat_atau_sabtu`
mungkin PASS kebetulan tergantung kalender — `test_plan_dies_boleh_jumat_tapi_nol_sabtu_minggu`
dan `test_rotasi_hari_mesin_antar_minggu_nol_monoton` harus FAIL karena kode belum
membatasi hari/belum rotasi). 12/12 dari 6 sebelumnya + 3 baru — minimal 2 FAIL baru.

- [ ] **Step 3: Tambah restriksi hari + rotasi di `generate()`**

Di `app/Services/PreventiveScheduleService.php`, tambah const baru SETELAH
`private const KAPASITAS = ['mesin' => 1, 'dies' => 5];` (baris ~16):

```php
    /**
     * Hari (isoWeekday, 1=Senin...7=Minggu) yang boleh jadi tanggal PLAN, per
     * jenis. Mesin Senin-Kamis — Jumat (14 jam) & Sabtu (10 jam) di master_shift
     * kependekan buat PM penuh. Dies Senin-Jumat — Sabtu tetap dihindari.
     * Minggu & hari_libur SUDAH dikeluarkan duluan oleh hariKerjaDalamBulan(),
     * jadi daftar ini nol perlu nyebut 7.
     */
    private const HARI_DIIZINKAN = [
        'mesin' => [1, 2, 3, 4],
        'dies' => [1, 2, 3, 4, 5],
    ];
```

Di method `generate()`, ganti baris (sekitar baris 53-54):
```php
            $hariKerja = $this->hariKerjaDalamBulan($bulan);
            $mingguBuckets = $this->kelompokkanPerMinggu($hariKerja);
```
jadi:
```php
            $hariKerja = $this->hariKerjaDalamBulan($bulan)
                ->filter(fn (Carbon $d) => in_array($d->isoWeekday(), self::HARI_DIIZINKAN[$jenis], true))
                ->values();
            $mingguBuckets = $this->kelompokkanPerMinggu($hariKerja);
```

Lalu ganti seluruh isi `foreach ($mingguBuckets as $mingguIndex => $hariList) { ... }`
(baris ~58-84) jadi (tambah rotasi offset + ganti `$hariList[$slot]` jadi
`$hariList[$posisi]`):

```php
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
                    ]);
                    $idx++;
                }
            }
```

- [ ] **Step 4: Jalankan semua test file ini, pastikan lolos**

```bash
php artisan test --filter=PreventiveScheduleServiceTest
```

Expected: 12/12 PASS (6 lama + 1 dari Task 1 + 3 dari Task 2 — total sebelas... hitung
ulang: 8 test ASLI + 1 Task1 + 3 Task2 = 12 test PASS).

- [ ] **Step 5: Commit**

```bash
git add app/Services/PreventiveScheduleService.php tests/Unit/PreventiveScheduleServiceTest.php
git commit -m "feat(preventive-schedule): restriksi hari per jenis + rotasi antar minggu"
```

---

### Task 3: Regresi — full suite + pint (nol migrasi baru, gerbang migrasi otomatis lolos)

**Files:** (nol file baru — verifikasi murni)

- [ ] **Step 1: Full suite**

```bash
php artisan test
```

Expected: semua PASS, nol regresi di luar 2 modul baru (kalau sebelumnya 1265, sekarang
1265 + 4 test baru dari Task 1-2 = 1269).

- [ ] **Step 2: Gerbang cakupan migrasi (harus tetap lolos, nol migrasi baru ditambah)**

```bash
./scripts/check-migration-coverage.sh
```

Expected: PASS — plan ini nol menambah file migrasi apa pun.

- [ ] **Step 3: Pint**

```bash
vendor/bin/pint app tests --test
```

Expected: nol file butuh fix, atau kalau ada jalankan `vendor/bin/pint app tests`
(tanpa `--test`) buat auto-fix lalu commit terpisah.

- [ ] **Step 4: Verifikasi manual — render PDF mesin & dies, cek tanggal PLAN masuk akal**

Kalau MySQL/browser tersedia di environment ini, generate ulang jadwal mesin & dies
lewat halaman Control Stroke (tombol "Generate Ulang"), buka PDF-nya, cek visual:
tanggal PLAN nol pernah Jumat/Sabtu buat mesin, nol pernah Sabtu buat dies. Kalau
environment ini nol MySQL (sandbox git-worktree, cek `mysql.server status` dulu),
render lewat test langsung (pola sama task-task sebelumnya di fitur ini — tulis test
sementara yang generate + hit route PDF + `file_put_contents` ke `/tmp`, baca hasilnya,
lalu HAPUS test sementara itu sebelum commit — JANGAN biarkan ikut ter-commit).

- [ ] **Step 5: Commit (kalau Step 3 menghasilkan perubahan)**

```bash
git add -A
git commit -m "style: pint"
```

Kalau Step 1-3 nol menemukan masalah, task ini nol menghasilkan commit tambahan —
lewati Step 5.
