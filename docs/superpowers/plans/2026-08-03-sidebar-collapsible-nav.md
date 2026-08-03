# Sidebar Collapsible Nav — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ubah sidebar OPTIMA jadi satu tingkat berisi 2 tautan tunggal + 6 grup accordion single-open, sehingga sidebar tidak pernah bisa di-scroll.

**Architecture:** Definisi menu pindah dari markup Blade ke array polos `config/sidebar.php`. Logika penyaringan role dan deteksi halaman aktif pindah ke `App\Support\SidebarMenu`. Blade tinggal me-loop hasilnya lewat partial `resources/views/partials/sidebar.blade.php`. Deteksi `active` berganti dari pencocokan prefiks URI ke pencocokan nama route (`request()->routeIs`), yang menghapus seluruh cek persis, negasi manual, dan blok `$vcbActive` yang sensitif urutan.

**Tech Stack:** Laravel 13, PHP 8.4 (CI/prod) / 8.5.4 (lokal), Blade, Alpine.js 3.14.1, Bootstrap Icons 1.11.3, PHPUnit (SQLite in-memory).

**Spec:** `docs/superpowers/specs/2026-08-03-sidebar-collapsible-nav-design.md`

## Global Constraints

- **Nol migrasi database.** Tidak ada file baru di `database/migrations/`, jadi `.github/workflows/deploy.yml` tidak perlu disentuh. Jalankan `./scripts/check-migration-coverage.sh` untuk membuktikan.
- **Nol perubahan route, middleware, atau role.** `routes/web.php` tidak disunting. Setiap item menu mempertahankan daftar role-nya persis seperti sekarang.
- **`config/sidebar.php` wajib array polos** — tanpa closure, tanpa panggilan `route()`, tanpa `auth()`. Alasan: kalau kelak `config:cache` dipakai, closure gagal di-serialize dan `route()` belum tersedia saat config dimuat.
- **Key grup wajib pakai nilai lama:** `produksi`, `quality`, `mtcmesin`, `mtcdies`. Nilai ini sudah tersimpan di `localStorage` pengguna.
- **Semua teks UI berbahasa Indonesia**, mengikuti label yang sudah ada persis: `AI Assistant`, `Anomali LHP`, `LHP Produksi`, `Dandori`, `LHP Quality`, `Breakdown Mesin`, `Breakdown Dies`, `VCB Produksi`, `VCB Mesin`, `VCB Dandori`, `VCB NG Proses`, `Daftar NG Proses`, `VCB Quality`, `VCB NG Total`, `Daftar NG Total`, `MTTR`, `MTBF`, `Availability`, `Control Stroke`, `Karyawan`, `Mesin`, `Part / Dies`, `Loss Time`, `Shift`, `Jenis NG`, `Users`.
- **Komentar kode berbahasa Indonesia**, mengikuti gaya file sekitarnya.
- **Format wajib** sebelum commit: `vendor/bin/pint app tests` (scope Pint = `app/` + `tests/` saja, tidak menyentuh `config/` maupun `resources/`).
- **Suite harus hijau di tiap commit.** `php artisan test` — 471 test sebelum pekerjaan ini.

---

## File Structure

| File | Status | Tanggung jawab |
|---|---|---|
| `config/sidebar.php` | Create | Data menu: label, ikon, route, pola `match`, role. Tidak ada logika. |
| `app/Support/SidebarMenu.php` | Create | Saring menurut role, tentukan item aktif, tentukan grup aktif. Tidak ada HTML. |
| `resources/views/partials/sidebar.blade.php` | Create | Render `<nav id="sidebar">`. Tidak ada keputusan role/aktif. |
| `resources/views/layouts/app.blade.php` | Modify | Ganti 238 baris markup sidebar dengan satu `@include`. Ganti `vcbGroups` → `navGroups`. Buang CSS mati. |
| `tests/Unit/SidebarMenuTest.php` | Create | Uji `forRole`, `activeGroup`, plafon baris — tanpa HTTP. |
| `tests/Feature/Layout/SidebarNavTest.php` | Create | Uji HTML terender: tepat satu `active` per halaman, per role. |
| `tests/Feature/Layout/NgListSidebarTest.php` | Modify | Dua baris: `vcbGroups(` → `navGroups(`. Sisanya harus lolos tanpa disunting. |

`app/Support/` sudah ada (`SafeExcelValueBinder.php`, `SaneDate.php`). `resources/views/partials/` belum ada — dibuat di Task 2.

---

### Task 1: Data menu & logika penyaringan

Bagian ini tidak merender apa pun. Selesai berarti: menu bisa disaring per role dan halaman aktif bisa dikenali, semuanya teruji tanpa menyentuh HTML.

**Files:**
- Create: `config/sidebar.php`
- Create: `app/Support/SidebarMenu.php`
- Test: `tests/Unit/SidebarMenuTest.php`

**Interfaces:**
- Consumes: `config()` helper Laravel, `request()->routeIs()`.
- Produces — dipakai Task 2:
  - `App\Support\SidebarMenu::forRole(string $role): array` — daftar entri sudah tersaring. Tiap entri punya `type` (`'link'`|`'group'`). Entri `link` punya `key`, `label`, `icon`, `route`, `match`, dan opsional `accent`. Entri `group` punya `key`, `label`, `icon`, `items` (array item, tiap item punya `label`, `icon`, `route`, `match`).
  - `App\Support\SidebarMenu::isActive(array $item): bool` — terima entri `link` atau item anak grup.
  - `App\Support\SidebarMenu::activeGroup(string $role): ?string` — key grup yang memuat halaman sekarang, `null` kalau tidak ada.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Unit/SidebarMenuTest.php`:

```php
<?php

namespace Tests\Unit;

use App\Support\SidebarMenu;
use Tests\TestCase;

/**
 * Penyaringan menu sidebar per role dan penentuan grup aktif.
 *
 * Test ini tidak merender HTML — kalau gagal, penyebabnya ada di data
 * (config/sidebar.php) atau logika (SidebarMenu), bukan di Blade.
 */
class SidebarMenuTest extends TestCase
{
    /** Batas yang membuat sidebar tidak pernah bisa di-scroll. */
    private const PLAFON_BARIS = 8;

    private function keys(string $role): array
    {
        return array_column(SidebarMenu::forRole($role), 'key');
    }

    public function test_admin_melihat_delapan_baris_tingkat_atas(): void
    {
        $this->assertSame(
            ['chatbot', 'anomali', 'input', 'produksi', 'quality', 'mtcmesin', 'mtcdies', 'master'],
            $this->keys('admin')
        );
    }

    public function test_tiap_role_tidak_melewati_plafon_baris(): void
    {
        foreach (['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
            $this->assertLessThanOrEqual(
                self::PLAFON_BARIS,
                count(SidebarMenu::forRole($role)),
                "Sidebar role {$role} melewati plafon baris."
            );
        }
    }

    public function test_grup_kosong_tidak_ikut_dirender(): void
    {
        $this->assertNotContains('mtcdies', $this->keys('staff_produksi'));
        $this->assertNotContains('master', $this->keys('staff_qc'));
        $this->assertNotContains('chatbot', $this->keys('guest'));
    }

    public function test_grup_input_menyusut_sesuai_role(): void
    {
        $grup = collect(SidebarMenu::forRole('staff_qc'))->firstWhere('key', 'input');

        $this->assertSame(['LHP Quality'], array_column($grup['items'], 'label'));
    }

    public function test_manager_melihat_breakdown_tapi_bukan_lhp(): void
    {
        $grup = collect(SidebarMenu::forRole('manager'))->firstWhere('key', 'input');

        $this->assertSame(['Breakdown Mesin', 'Breakdown Dies'], array_column($grup['items'], 'label'));
    }

    public function test_guest_melihat_lhp_tapi_bukan_breakdown(): void
    {
        $grup = collect(SidebarMenu::forRole('guest'))->firstWhere('key', 'input');

        $this->assertSame(['LHP Produksi', 'Dandori', 'LHP Quality'], array_column($grup['items'], 'label'));
    }

    public function test_tiap_item_punya_pola_match_yang_terisi(): void
    {
        foreach (SidebarMenu::forRole('admin') as $entri) {
            $daftar = $entri['type'] === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $this->assertNotEmpty($item['match'], "Item {$item['label']} tidak punya pola match.");
                $this->assertNotEmpty($item['route'], "Item {$item['label']} tidak punya route.");
            }
        }
    }

    public function test_route_tiap_item_benar_benar_terdaftar(): void
    {
        $terdaftar = collect(app('router')->getRoutes())->map->getName()->filter()->all();

        foreach (SidebarMenu::forRole('admin') as $entri) {
            $daftar = $entri['type'] === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $this->assertContains($item['route'], $terdaftar, "Route {$item['route']} tidak terdaftar.");
            }
        }
    }

    public function test_grup_aktif_diturunkan_dari_halaman_sekarang(): void
    {
        $this->get(route('vcb.ng-total.daftar'));

        $this->assertSame('quality', SidebarMenu::activeGroup('admin'));
    }

    public function test_grup_aktif_null_kalau_halaman_di_luar_menu(): void
    {
        $this->get('/profile');

        $this->assertNull(SidebarMenu::activeGroup('admin'));
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

```bash
php artisan test --filter=SidebarMenuTest
```

Expected: FAIL — `Class "App\Support\SidebarMenu" not found`.

- [ ] **Step 3: Buat `config/sidebar.php`**

```php
<?php

/*
|--------------------------------------------------------------------------
| Definisi menu sidebar
|--------------------------------------------------------------------------
|
| Array polos — TANPA closure, TANPA route(), TANPA auth(). Kalau kelak
| `config:cache` dipakai, closure gagal di-serialize dan route() belum
| tersedia saat config dimuat.
|
| type   : 'link' (tautan tunggal) atau 'group' (accordion)
| key    : identitas grup untuk accordion & localStorage. Nilai grup TIDAK
|          boleh diganti — sudah tersimpan di localStorage pengguna.
| route  : nama route tujuan
| match  : pola nama route yang membuat entri menyala (dipakai routeIs()).
|          Wildcard hanya boleh kalau tidak ada menu lain yang berbagi
|          prefiks nama. `vcb.ng-proses` WAJIB persis: pola `vcb.ng-proses*`
|          akan ikut menelan `vcb.ng-proses.daftar` dan menyalakan dua entri.
| roles  : role yang boleh melihat. Grup TIDAK punya 'roles' — diturunkan
|          dari gabungan role anaknya, supaya cuma ada satu tempat yang
|          bisa salah.
| accent : warna khusus (opsional), dipakai Anomali LHP.
|
| Route *.data, *.detail, *.import, *.export adalah endpoint JSON/unduhan
| yang tidak pernah merender layout, jadi tidak perlu masuk 'match'.
*/

return [
    [
        'type'  => 'link',
        'key'   => 'chatbot',
        'label' => 'AI Assistant',
        'icon'  => 'bi-robot',
        'route' => 'chatbot.index',
        'roles' => ['admin', 'manager'],
        'match' => ['chatbot.*'],
    ],

    // Sengaja di luar grup Input Data: item ini kuning karena memang harus
    // menarik perhatian; menyembunyikannya di balik header tertutup
    // membalik maksudnya.
    [
        'type'   => 'link',
        'key'    => 'anomali',
        'label'  => 'Anomali LHP',
        'icon'   => 'bi-exclamation-triangle-fill',
        'route'  => 'admin.anomali',
        'roles'  => ['admin'],
        'match'  => ['admin.anomali', 'admin.anomali.approve'],
        'accent' => '#ca8a04',
    ],

    [
        'type'  => 'group',
        'key'   => 'input',
        'label' => 'Input Data',
        'icon'  => 'bi-clipboard-data',
        'items' => [
            [
                'label' => 'LHP Produksi',
                'icon'  => 'bi-clipboard-data',
                'route' => 'input.produksi.index',
                'roles' => ['admin', 'staff_produksi', 'guest'],
                'match' => ['input.produksi.*'],
            ],
            [
                'label' => 'Dandori',
                'icon'  => 'bi-wrench-adjustable',
                'route' => 'input.dandori.index',
                'roles' => ['admin', 'staff_produksi', 'guest'],
                'match' => ['input.dandori.*'],
            ],
            [
                'label' => 'LHP Quality',
                'icon'  => 'bi-clipboard-check',
                'route' => 'input.qc.index',
                'roles' => ['admin', 'staff_qc', 'guest'],
                'match' => ['input.qc.*'],
            ],
            // 'maintenance.breakdown.*' tidak cocok dengan
            // 'maintenance.breakdown-dies.index': setelah 'breakdown'
            // ada '-', bukan '.'.
            [
                'label' => 'Breakdown Mesin',
                'icon'  => 'bi-tools',
                'route' => 'maintenance.breakdown.index',
                'roles' => ['admin', 'staff_maintenance', 'manager'],
                'match' => ['maintenance.breakdown.*'],
            ],
            [
                'label' => 'Breakdown Dies',
                'icon'  => 'bi-hammer',
                'route' => 'maintenance.breakdown-dies.index',
                'roles' => ['admin', 'staff_maintenance', 'manager'],
                'match' => ['maintenance.breakdown-dies.*'],
            ],
        ],
    ],

    [
        'type'  => 'group',
        'key'   => 'produksi',
        'label' => 'Produksi',
        'icon'  => 'bi-box-seam',
        'items' => [
            [
                'label' => 'VCB Produksi',
                'icon'  => 'bi-bar-chart',
                'route' => 'vcb.produksi',
                'roles' => ['admin', 'staff_produksi', 'manager', 'guest'],
                'match' => ['vcb.produksi'],
            ],
            [
                'label' => 'VCB Mesin',
                'icon'  => 'bi-gear',
                'route' => 'vcb.mesin',
                'roles' => ['admin', 'staff_produksi', 'manager', 'guest'],
                'match' => ['vcb.mesin'],
            ],
            [
                'label' => 'VCB Dandori',
                'icon'  => 'bi-clock-history',
                'route' => 'vcb.dandori',
                'roles' => ['admin', 'staff_produksi', 'manager', 'guest'],
                'match' => ['vcb.dandori'],
            ],
            [
                'label' => 'VCB NG Proses',
                'icon'  => 'bi-exclamation-triangle',
                'route' => 'vcb.ng-proses',
                'roles' => ['admin', 'staff_produksi', 'manager', 'guest'],
                'match' => ['vcb.ng-proses'],
            ],
            [
                'label' => 'Daftar NG Proses',
                'icon'  => 'bi-list-ul',
                'route' => 'vcb.ng-proses.daftar',
                'roles' => ['admin', 'staff_produksi', 'manager', 'guest'],
                'match' => ['vcb.ng-proses.daftar'],
            ],
        ],
    ],

    [
        'type'  => 'group',
        'key'   => 'quality',
        'label' => 'Quality',
        'icon'  => 'bi-shield-check',
        'items' => [
            [
                'label' => 'VCB Quality',
                'icon'  => 'bi-shield-check',
                'route' => 'vcb.quality',
                'roles' => ['admin', 'staff_qc', 'manager', 'guest'],
                'match' => ['vcb.quality'],
            ],
            [
                'label' => 'VCB NG Total',
                'icon'  => 'bi-exclamation-triangle',
                'route' => 'vcb.ng-total',
                'roles' => ['admin', 'staff_qc', 'manager', 'guest'],
                'match' => ['vcb.ng-total'],
            ],
            [
                'label' => 'Daftar NG Total',
                'icon'  => 'bi-list-ul',
                'route' => 'vcb.ng-total.daftar',
                'roles' => ['admin', 'staff_qc', 'manager', 'guest'],
                'match' => ['vcb.ng-total.daftar'],
            ],
        ],
    ],

    [
        'type'  => 'group',
        'key'   => 'mtcmesin',
        'label' => 'MTC Mesin',
        'icon'  => 'bi-tools',
        'items' => [
            [
                'label' => 'MTTR',
                'icon'  => 'bi-clock-history',
                'route' => 'maintenance.mttr',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.mttr'],
            ],
            [
                'label' => 'MTBF',
                'icon'  => 'bi-arrow-repeat',
                'route' => 'maintenance.mtbf',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.mtbf'],
            ],
            [
                'label' => 'Availability',
                'icon'  => 'bi-graph-up',
                'route' => 'maintenance.availability',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.availability'],
            ],
            // Wildcard di sini aman dan memang perlu: 'history' dan
            // 'preventive.create' dua-duanya halaman.
            [
                'label' => 'Control Stroke',
                'icon'  => 'bi-speedometer2',
                'route' => 'maintenance.control-stroke-mesin',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.control-stroke-mesin*'],
            ],
        ],
    ],

    [
        'type'  => 'group',
        'key'   => 'mtcdies',
        'label' => 'MTC Dies',
        'icon'  => 'bi-hammer',
        'items' => [
            [
                'label' => 'MTTR',
                'icon'  => 'bi-clock-history',
                'route' => 'maintenance.mttr-dies',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.mttr-dies'],
            ],
            [
                'label' => 'MTBF',
                'icon'  => 'bi-arrow-repeat',
                'route' => 'maintenance.mtbf-dies',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.mtbf-dies'],
            ],
            [
                'label' => 'Availability',
                'icon'  => 'bi-graph-up',
                'route' => 'maintenance.availability-dies',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.availability-dies'],
            ],
            [
                'label' => 'Control Stroke',
                'icon'  => 'bi-speedometer2',
                'route' => 'maintenance.control-stroke-dies',
                'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'],
                'match' => ['maintenance.control-stroke-dies*'],
            ],
        ],
    ],

    [
        'type'  => 'group',
        'key'   => 'master',
        'label' => 'Master Data',
        'icon'  => 'bi-database',
        'items' => [
            [
                'label' => 'Karyawan',
                'icon'  => 'bi-people',
                'route' => 'master.karyawan.index',
                'roles' => ['admin'],
                'match' => ['master.karyawan.*'],
            ],
            [
                'label' => 'Mesin',
                'icon'  => 'bi-cpu',
                'route' => 'master.mesin.index',
                'roles' => ['admin'],
                'match' => ['master.mesin.*'],
            ],
            [
                'label' => 'Part / Dies',
                'icon'  => 'bi-box-seam',
                'route' => 'master.part.index',
                'roles' => ['admin'],
                'match' => ['master.part.*'],
            ],
            [
                'label' => 'Loss Time',
                'icon'  => 'bi-stopwatch',
                'route' => 'master.losstime.index',
                'roles' => ['admin'],
                'match' => ['master.losstime.*'],
            ],
            [
                'label' => 'Shift',
                'icon'  => 'bi-calendar-week',
                'route' => 'master.shift.index',
                'roles' => ['admin'],
                'match' => ['master.shift.*'],
            ],
            [
                'label' => 'Jenis NG',
                'icon'  => 'bi-exclamation-octagon',
                'route' => 'master.jenis-ng.index',
                'roles' => ['admin'],
                'match' => ['master.jenis-ng.*'],
            ],
            [
                'label' => 'Users',
                'icon'  => 'bi-person-gear',
                'route' => 'master.users.index',
                'roles' => ['admin'],
                'match' => ['master.users.*'],
            ],
        ],
    ],
];
```

- [ ] **Step 4: Buat `app/Support/SidebarMenu.php`**

```php
<?php

namespace App\Support;

/**
 * Penyaringan menu sidebar per role dan deteksi halaman aktif.
 *
 * Deteksi aktif memakai NAMA ROUTE, bukan prefiks URI. Pola prefiks lama
 * (`str_starts_with($uri, 'vcb/ng-proses')`) juga cocok dengan
 * `vcb/ng-proses/daftar` dan sudah tiga kali menyalakan dua entri
 * sekaligus. Nama route unik, jadi kelas bug itu hilang.
 */
class SidebarMenu
{
    /**
     * Entri sidebar yang boleh dilihat sebuah role.
     *
     * Grup yang tidak menyisakan satu anak pun untuk role tersebut
     * dibuang seluruhnya — bukan dirender kosong.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forRole(string $role): array
    {
        $hasil = [];

        foreach (config('sidebar', []) as $entri) {
            if (($entri['type'] ?? 'link') === 'link') {
                if (in_array($role, $entri['roles'], true)) {
                    $hasil[] = $entri;
                }

                continue;
            }

            $items = array_values(array_filter(
                $entri['items'],
                fn (array $item) => in_array($role, $item['roles'], true)
            ));

            if ($items !== []) {
                $entri['items'] = $items;
                $hasil[] = $entri;
            }
        }

        return $hasil;
    }

    /** Apakah entri ini menunjuk halaman yang sedang dibuka. */
    public static function isActive(array $item): bool
    {
        return request()->routeIs(...$item['match']);
    }

    /**
     * Key grup yang memuat halaman sekarang.
     *
     * Diturunkan dari anaknya, bukan dari peta terpisah — peta terpisah
     * bisa melenceng dari daftar item tanpa ketahuan.
     */
    public static function activeGroup(string $role): ?string
    {
        foreach (self::forRole($role) as $entri) {
            if (($entri['type'] ?? 'link') !== 'group') {
                continue;
            }

            foreach ($entri['items'] as $item) {
                if (self::isActive($item)) {
                    return $entri['key'];
                }
            }
        }

        return null;
    }
}
```

- [ ] **Step 5: Jalankan test, pastikan lolos**

```bash
php artisan test --filter=SidebarMenuTest
```

Expected: PASS — 10 test.

Kalau `test_grup_aktif_null_kalau_halaman_di_luar_menu` gagal karena `/profile` mengalihkan ke login: itu benar, tidak apa-apa — `activeGroup` tetap `null` karena tidak ada route menu yang cocok. Kalau justru gagal karena mengembalikan sebuah key, ada pola `match` yang terlalu longgar; perbaiki polanya, bukan test-nya.

- [ ] **Step 6: Format dan commit**

```bash
vendor/bin/pint app tests
```

```bash
git add config/sidebar.php app/Support/SidebarMenu.php tests/Unit/SidebarMenuTest.php
git commit -m "feat(sidebar): data menu di config + penyaringan role di SidebarMenu"
```

---

### Task 2: Render sidebar baru

Selesai berarti: sidebar terender dari config, accordion jalan, dan tepat satu entri menyala di tiap halaman untuk tiap role.

**Files:**
- Create: `resources/views/partials/sidebar.blade.php`
- Modify: `resources/views/layouts/app.blade.php` (blok `<nav id="sidebar">` s/d `</nav>`, dan fungsi `vcbGroups`)
- Create: `tests/Feature/Layout/SidebarNavTest.php`
- Modify: `tests/Feature/Layout/NgListSidebarTest.php:74,79`

**Interfaces:**
- Consumes: `SidebarMenu::forRole()`, `SidebarMenu::isActive()`, `SidebarMenu::activeGroup()` dari Task 1.
- Produces: markup `<nav id="sidebar">` berisi `<a class="nav-item-link [active]">`, `<button class="nav-group-header [has-active]">`, `<div class="nav-group-body">`; fungsi JS global `navGroups(active)` dengan kunci `localStorage` `optima_nav_group`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Layout/SidebarNavTest.php`:

```php
<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use App\Support\SidebarMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Sidebar terender: tepat satu entri menyala, per halaman, per role.
 *
 * Ini yang menutup kelas bug "dua menu nyala bersamaan" untuk SELURUH
 * menu — bukan cuma empat entri NG yang kebetulan pernah ketahuan
 * (lihat NgListSidebarTest).
 */
class SidebarNavTest extends TestCase
{
    use RefreshDatabase;

    /** Halaman tanpa parameter yang bisa dibuka tiap role. */
    private const HALAMAN = [
        'admin' => [
            'chatbot.index', 'admin.anomali',
            'input.produksi.index', 'input.dandori.index', 'input.qc.index',
            'maintenance.breakdown.index', 'maintenance.breakdown-dies.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
            'master.karyawan.index', 'master.mesin.index', 'master.part.index',
            'master.losstime.index', 'master.shift.index', 'master.jenis-ng.index',
            'master.users.index',
        ],
        'manager' => [
            'chatbot.index',
            'maintenance.breakdown.index', 'maintenance.breakdown-dies.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
        ],
        'staff_produksi' => [
            'input.produksi.index', 'input.dandori.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
        ],
        'staff_qc' => [
            'input.qc.index',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
        ],
        'staff_maintenance' => [
            'maintenance.breakdown.index', 'maintenance.breakdown-dies.index',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
        ],
        'guest' => [
            'input.produksi.index', 'input.dandori.index', 'input.qc.index',
            'vcb.produksi', 'vcb.mesin', 'vcb.dandori', 'vcb.ng-proses', 'vcb.ng-proses.daftar',
            'vcb.quality', 'vcb.ng-total', 'vcb.ng-total.daftar',
            'maintenance.mttr', 'maintenance.mtbf', 'maintenance.availability',
            'maintenance.control-stroke-mesin',
            'maintenance.mttr-dies', 'maintenance.mtbf-dies', 'maintenance.availability-dies',
            'maintenance.control-stroke-dies',
        ],
    ];

    /** Potong hanya bagian <nav id="sidebar"> supaya tidak ikut menghitung isi halaman. */
    private function sidebar(string $html): string
    {
        preg_match('/<nav id="sidebar">(.*?)<\/nav>/s', $html, $cocok);

        return $cocok[1] ?? '';
    }

    private function jumlahAktif(string $html): int
    {
        return preg_match_all('/class="nav-item-link\s+active\s*"/', $this->sidebar($html));
    }

    public function test_tepat_satu_entri_menyala_di_tiap_halaman(): void
    {
        foreach (self::HALAMAN as $role => $daftarRoute) {
            $user = User::factory()->create(['role' => $role]);

            foreach ($daftarRoute as $namaRoute) {
                $html = $this->actingAs($user)->get(route($namaRoute))->assertOk()->getContent();

                $this->assertSame(
                    1,
                    $this->jumlahAktif($html),
                    "Role {$role} di {$namaRoute}: jumlah entri aktif harus tepat 1."
                );
            }
        }
    }

    public function test_grup_yang_memuat_halaman_ikut_terbuka(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.ng-total.daftar'))
            ->assertOk()
            ->assertSee("navGroups('quality')", false);

        $this->actingAs($admin)->get(route('maintenance.mttr-dies'))
            ->assertOk()
            ->assertSee("navGroups('mtcdies')", false);

        $this->actingAs($admin)->get(route('master.users.index'))
            ->assertOk()
            ->assertSee("navGroups('master')", false);
    }

    public function test_header_grup_yang_memuat_halaman_ditandai(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('vcb.quality'))->assertOk()->getContent();

        $this->assertSame(
            1,
            preg_match_all('/class="nav-group-header\s+has-active"/', $this->sidebar($html)),
            'Tepat satu header grup boleh ditandai has-active.'
        );
    }

    public function test_jumlah_baris_tingkat_atas_tidak_melewati_plafon(): void
    {
        foreach (array_keys(self::HALAMAN) as $role) {
            $user = User::factory()->create(['role' => $role]);
            $rute = self::HALAMAN[$role][0];

            $html = $this->sidebar(
                $this->actingAs($user)->get(route($rute))->assertOk()->getContent()
            );

            // Baris yang SELALU tampak = header grup + tautan tunggal.
            // Tautan tunggal berada di luar .nav-group-body, jadi dihitung
            // sebagai selisih total tautan dikurangi tautan di dalam grup.
            $header = preg_match_all('/class="nav-group-header/', $html);
            $isiGrup = 0;
            preg_match_all('/<div class="nav-group-body"(.*?)<\/div>/s', $html, $badan);
            foreach ($badan[1] as $potongan) {
                $isiGrup += preg_match_all('/class="nav-item-link/', $potongan);
            }
            $semuaTautan = preg_match_all('/class="nav-item-link/', $html);

            $this->assertLessThanOrEqual(
                8,
                $header + ($semuaTautan - $isiGrup),
                "Sidebar role {$role} melewati plafon 8 baris tingkat atas."
            );
        }
    }

    public function test_saringan_role_tetap_utuh(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);
        $response = $this->actingAs($qc)->get(route('vcb.quality'))->assertOk();

        $response->assertDontSee(route('master.users.index'));
        $response->assertDontSee(route('maintenance.breakdown.index'));
        $response->assertDontSee(route('chatbot.index'));
        $response->assertDontSee('MTC Dies');
    }

    public function test_label_section_lama_sudah_tidak_dirender(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->sidebar(
            $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent()
        );

        $this->assertStringNotContainsString('nav-section-label', $html);
    }

    public function test_semua_href_menu_benar_benar_dirender(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $html = $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent();

        foreach (SidebarMenu::forRole('admin') as $entri) {
            $daftar = $entri['type'] === 'group' ? $entri['items'] : [$entri];

            foreach ($daftar as $item) {
                $this->assertStringContainsString(
                    route($item['route']),
                    $html,
                    "Tautan {$item['label']} hilang dari sidebar."
                );
            }
        }
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan gagal**

```bash
php artisan test --filter=SidebarNavTest
```

Expected: FAIL — `test_grup_yang_memuat_halaman_ikut_terbuka` gagal karena HTML masih berisi `vcbGroups(...)`, dan `test_label_section_lama_sudah_tidak_dirender` gagal karena `nav-section-label` masih ada.

- [ ] **Step 3: Buat `resources/views/partials/sidebar.blade.php`**

```blade
{{-- ═══ SIDEBAR ═══════════════════════════════════════════════════════
     Definisi menu: config/sidebar.php
     Logika role & aktif: App\Support\SidebarMenu
     Berkas ini HANYA merender — jangan taruh keputusan role di sini. --}}
@php
    use App\Support\SidebarMenu;

    $role        = auth()->user()->role;
    $entries     = SidebarMenu::forRole($role);
    $activeGroup = SidebarMenu::activeGroup($role);
@endphp

<nav id="sidebar">
    <div class="nav-section" x-data="navGroups('{{ $activeGroup }}')">
        @foreach($entries as $entry)
            @if($entry['type'] === 'link')
                @php $aktif = SidebarMenu::isActive($entry); @endphp
                <a href="{{ route($entry['route']) }}"
                   class="nav-item-link {{ $aktif ? 'active' : '' }}"
                   data-tip="{{ $entry['label'] }}"
                   @if(isset($entry['accent']) && ! $aktif) style="color:{{ $entry['accent'] }}" @endif>
                    <i class="bi {{ $entry['icon'] }}"
                       @if(isset($entry['accent'])) style="color:{{ $entry['accent'] }}" @endif></i>
                    <span class="nav-text">{{ $entry['label'] }}</span>
                </a>
            @else
                <button type="button"
                        class="nav-group-header {{ $activeGroup === $entry['key'] ? 'has-active' : '' }}"
                        :class="{ 'open': isOpen('{{ $entry['key'] }}') }"
                        @click="toggle('{{ $entry['key'] }}')">
                    <i class="bi {{ $entry['icon'] }}"></i>
                    <span class="nav-text">{{ $entry['label'] }}</span>
                    <i class="bi bi-chevron-down nav-caret"></i>
                </button>
                <div class="nav-group-body" x-show="isOpen('{{ $entry['key'] }}')" x-cloak>
                    @foreach($entry['items'] as $item)
                        <a href="{{ route($item['route']) }}"
                           class="nav-item-link {{ SidebarMenu::isActive($item) ? 'active' : '' }}"
                           data-tip="{{ $item['label'] }}">
                            <i class="bi {{ $item['icon'] }}"></i>
                            <span class="nav-text">{{ $item['label'] }}</span>
                        </a>
                    @endforeach
                </div>
            @endif
        @endforeach
    </div>
</nav>
```

Perhatikan `class="nav-item-link {{ ... }}"` menghasilkan `class="nav-item-link active"` atau `class="nav-item-link "`. Regex di kedua test bergantung pada bentuk itu — jangan diubah jadi `@class([...])`.

- [ ] **Step 4: Ganti blok sidebar di layout dengan `@include`**

Di `resources/views/layouts/app.blade.php`, hapus seluruh blok dari baris yang berbunyi:

```blade
{{-- ═══ SIDEBAR ═══════════════════════════════════════════════════════ --}}
<nav id="sidebar">
```

sampai `</nav>` penutupnya (tepat sebelum komentar `{{-- ═══ MAIN ═══ --}}`) — 238 baris, saat ini 926–1163. Ganti seluruhnya dengan satu baris:

```blade
@include('partials.sidebar')
```

- [ ] **Step 5: Ganti `vcbGroups` jadi `navGroups`**

Di `resources/views/layouts/app.blade.php`, ganti blok ini:

```js
/* ─ Dashboard accordion (single-open + ingat via localStorage) ─ */
function vcbGroups(active = '') {
    return {
        open: active || localStorage.getItem('optima_vcb_group') || 'produksi',
        isOpen(g) { return this.open === g; },
        toggle(g) {
            this.open = this.open === g ? null : g;
            localStorage.setItem('optima_vcb_group', this.open || '');
        },
    };
}
```

dengan:

```js
/* ─ Sidebar accordion (single-open + ingat via localStorage) ─
   Urutan keadaan awal: grup halaman aktif → grup terakhir dari
   localStorage → semua tertutup. Default lama 'produksi' dibuang: untuk
   staff_maintenance itu grup yang tidak dia punya. */
function navGroups(active = '') {
    return {
        open: active || localStorage.getItem('optima_nav_group') || null,
        isOpen(g) { return this.open === g; },
        toggle(g) {
            this.open = this.open === g ? null : g;
            localStorage.setItem('optima_nav_group', this.open || '');
        },
    };
}
```

- [ ] **Step 6: Perbarui dua baris di `NgListSidebarTest`**

Di `tests/Feature/Layout/NgListSidebarTest.php`, ganti `vcbGroups(` jadi `navGroups(` pada baris 74 dan 79:

```php
            ->assertSee("navGroups('produksi')", false);
```

```php
            ->assertSee("navGroups('quality')", false);
```

Empat test lain di berkas itu menguji perilaku, bukan implementasi. **Jangan disunting.** Kalau ada yang merah, itu berarti refactor mengubah arti — perbaiki render-nya, bukan test-nya.

- [ ] **Step 7: Jalankan test sidebar, pastikan lolos**

```bash
php artisan test --filter="SidebarNavTest|NgListSidebarTest|GuestUiTest|GuestMarkupBalanceTest"
```

Expected: PASS semua.

- [ ] **Step 8: Jalankan seluruh suite**

```bash
php artisan test
```

Expected: PASS, 471 + 10 (Task 1) + 7 (Task 2) test.

- [ ] **Step 9: Format dan commit**

```bash
vendor/bin/pint app tests
```

```bash
git add resources/views/partials/sidebar.blade.php resources/views/layouts/app.blade.php tests/Feature/Layout/SidebarNavTest.php tests/Feature/Layout/NgListSidebarTest.php
git commit -m "feat(sidebar): render dari config, accordion untuk semua grup"
```

---

### Task 3: Buang CSS mati dan verifikasi akhir

Selesai berarti: tidak ada aturan CSS yang tidak dipakai, dan semua gerbang lokal hijau.

**Files:**
- Modify: `resources/views/layouts/app.blade.php` (blok CSS `.nav-section`)

**Interfaces:**
- Consumes: markup dari Task 2.
- Produces: tidak ada antarmuka baru.

- [ ] **Step 1: Pastikan aturan yang mau dibuang benar-benar sudah tidak dipakai**

```bash
grep -rn "nav-section-label" -- resources/ public/ app/ tests/
```

Expected: nol hasil. Kalau masih ada di `layouts/app.blade.php` selain di blok CSS, berarti Step 4 Task 2 belum tuntas — hentikan dan selesaikan itu dulu.

- [ ] **Step 2: Buang aturan CSS yang mati**

Di `resources/views/layouts/app.blade.php`, ganti blok ini:

```css
        /* Sidebar sections */
        .nav-section { padding: 10px 0 4px; }
        .nav-section + .nav-section {
            border-top: 1px solid #374151;
            margin-top: 2px;
        }
        .nav-section-label {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            color: #6b7280;
            padding: 8px 16px 4px;
            display: block;
            white-space: nowrap;
        }
```

dengan:

```css
        /* Sidebar: satu pembungkus saja sejak menu jadi satu tingkat.
           Pemisah antar-section dan label section dibuang — tugasnya
           sudah diambil header grup. */
        .nav-section { padding: 10px 0 4px; }
```

Sisa aturan `.nav-group-header`, `.nav-group-body`, `.nav-caret`, `.nav-item-link`, `.has-active` **dipakai ulang apa adanya — jangan disentuh.**

- [ ] **Step 3: Jalankan seluruh suite**

```bash
php artisan test
```

Expected: PASS, jumlah sama seperti akhir Task 2.

- [ ] **Step 4: Jalankan gerbang cakupan migrasi**

```bash
./scripts/check-migration-coverage.sh
```

Expected: lolos. Pekerjaan ini nol migrasi, jadi gerbangnya harus diam.

- [ ] **Step 5: Format**

```bash
vendor/bin/pint app tests
```

Expected: `PASS` atau daftar berkas yang dirapikan. Kalau ada yang berubah, ikut di-commit pada Step 6.

- [ ] **Step 6: Commit**

```bash
git add resources/views/layouts/app.blade.php
git commit -m "refactor(sidebar): buang CSS label section yang sudah mati"
```

- [ ] **Step 7: Periksa manual di peramban**

Jalankan aplikasi, masuk sebagai `admin`, lalu periksa:

1. Sidebar tertutup semua → 8 baris, tidak ada scrollbar.
2. Klik `Master Data` → terbuka 7 anak, `Produksi` ikut tertutup sendiri.
3. Buka `Daftar NG Total` lewat menu → grup `Quality` terbuka, hanya `Daftar NG Total` yang menyala.
4. Muat ulang halaman → grup tetap terbuka di posisi yang sama.
5. Perkecil jendela di bawah 768px → sidebar jadi overlay, accordion tetap jalan.

Masuk lagi sebagai `staff_maintenance` dan pastikan hanya 3 baris yang tampak: `Input Data`, `MTC Mesin`, `MTC Dies`.

---

## Catatan untuk pelaksana

- **Jangan** menyentuh `routes/web.php`. Kalau sebuah menu terasa butuh route baru, itu tanda ada salah baca — hentikan dan tanya.
- **Jangan** mengubah daftar `roles` mana pun. Salinlah persis dari markup lama.
- **Jangan** menambah `Schema::hasColumn` atau guard DB — pekerjaan ini tidak menyentuh database sama sekali.
- Kalau test "tepat satu aktif" gagal dengan hitungan **0**, pola `match` item itu salah ketik. Kalau gagal dengan **2**, ada wildcard yang terlalu longgar — biasanya pola induk menelan sub-halaman `.daftar`.
