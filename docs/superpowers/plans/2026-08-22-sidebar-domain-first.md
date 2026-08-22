# Sidebar Satu Sumbu (Domain-First) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menata ulang sidebar menurut satu sumbu — domain — sehingga tiap peran cukup membuka satu grup, dan menyediakan slot untuk Backup sekarang serta Audit Trail nanti.

**Architecture:** Perubahan terjadi hampir seluruhnya di **data**, bukan di logika. `config/sidebar.php` ditulis ulang; `SidebarMenu::forRole()`, `activeGroup()`, `isActive()`, dan `partials/sidebar.blade.php` tidak disentuh. Halaman Backup masuk sidebar dan keluar dari dropdown topbar supaya hanya ada satu pintu masuk.

**Tech Stack:** Laravel 13 · PHP 8.4 (CI/prod) / 8.5.4 (lokal) · Blade + Alpine.js 3.14.1 · PHPUnit lewat `php artisan test`

## Global Constraints

- **Spec:** `docs/superpowers/specs/2026-08-22-sidebar-domain-first-design.md`
- **Plafon TIDAK dinaikkan:** `PLAFON_BARIS = 9`, `PLAFON_TERBUKA = 16` di `tests/Unit/SidebarMenuTest.php` tetap apa adanya. Struktur baru harus muat, bukan plafonnya yang mengalah
- Plafon berlaku **per peran**, bukan cuma admin — penjaganya melooping keenam peran
- **Pola `match` disalin apa adanya.** `vcb.ng-proses` WAJIB tanpa wildcard: `vcb.ng-proses*` akan ikut menelan `vcb.ng-proses.daftar` dan menyalakan dua entri sekaligus
- **Grup tidak punya `roles` sendiri** — diturunkan dari gabungan peran anaknya. Jangan tambahkan
- **`key` grup adalah identitas accordion** (dipakai `localStorage`, `isOpen()`/`toggle()`, dan diasersikan test). Key yang bertahan (`produksi`, `quality`, `mtcmesin`, `mtcdies`, `master`) **tidak boleh diganti**; `input` hilang, `sistem` baru
- **Menu tidak boleh menunjuk halaman yang belum ada.** Audit Trail TIDAK ditambahkan di plan ini
- Nol route baru, nol URL berubah, **nol migrasi**
- Array config polos: tanpa closure, tanpa `route()`, tanpa `auth()` — `config:cache` akan gagal men-serialize closure dan `route()` belum tersedia saat config dimuat
- Test: `php artisan test`. Format: `vendor/bin/pint app tests`
- Komentar dan label: **Indonesia**

---

## File Structure

| Berkas | Tanggung jawab |
|---|---|
| `config/sidebar.php` | Seluruh struktur menu — inti perubahan ini |
| `tests/Unit/SidebarMenuTest.php` | Plafon, urutan key, penyusutan grup per peran |
| `tests/Feature/Layout/SidebarNavTest.php` | Grup aktif yang ditandai per halaman |
| `resources/views/layouts/app.blade.php` | Dropdown topbar — entri Backup dicabut |
| `tests/Feature/Sistem/BackupHalamanTest.php` | Asersi pintu masuk Backup pindah dari topbar ke sidebar |

Tidak ada berkas baru. `app/Support/SidebarMenu.php` dan `resources/views/partials/sidebar.blade.php` **tidak disentuh sama sekali** — kalau sebuah task terasa perlu mengubahnya, berhenti dan tanya.

---

### Task 1: Struktur baru di config

**Files:**
- Modify: `config/sidebar.php` (seluruh isi `return [...]`)
- Modify: `tests/Unit/SidebarMenuTest.php:48-52` dan tiga test grup `input` di `:96-117`
- Modify: `tests/Feature/Layout/SidebarNavTest.php:102-103`

**Interfaces:**
- Consumes: —
- Produces: key tingkat atas `overview, chatbot, produksi, quality, mtcmesin, mtcdies, master, sistem`; grup `sistem` memuat route `admin.anomali`, `master.users.index`, `sistem.backup.index`

- [ ] **Step 1: Write the failing tests**

Di `tests/Unit/SidebarMenuTest.php`, ganti `test_admin_melihat_sembilan_baris_tingkat_atas` (namanya ikut berubah — jumlahnya bukan sembilan lagi):

```php
    public function test_urutan_dan_kunci_tingkat_atas_admin(): void
    {
        // Urutan disengaja: pekerjaan harian di atas, administrasi di bawah.
        $this->assertSame(
            ['overview', 'chatbot', 'produksi', 'quality', 'mtcmesin', 'mtcdies', 'master', 'sistem'],
            $this->keys('admin')
        );
    }
```

Ganti ketiga test grup `input` (`test_grup_input_menyusut_sesuai_role`, `test_manager_melihat_breakdown_tapi_bukan_lhp`, `test_guest_melihat_lhp_tapi_bukan_breakdown`) dengan versi yang membuktikan hal yang sama — **satu grup hanya merender anak yang boleh dilihat perannya** — tetapi pada grup yang baru:

```php
    public function test_grup_quality_menyusut_sesuai_role(): void
    {
        $grup = collect(SidebarMenu::forRole('staff_qc'))->firstWhere('key', 'quality');

        $this->assertSame(
            ['LHP Quality', 'VCB Quality', 'VCB NG Total', 'Daftar NG Total'],
            array_column($grup['items'], 'label')
        );
    }

    public function test_manager_melihat_breakdown_tapi_bukan_lhp(): void
    {
        // manager tidak menginput LHP, tapi memantau breakdown. Sesudah penataan
        // ulang keduanya ada di grup domain yang sama, jadi penyaringan per-anak
        // inilah yang menjaga manager tidak kebagian halaman input.
        $grup = collect(SidebarMenu::forRole('manager'))->firstWhere('key', 'mtcmesin');

        $this->assertSame(
            ['Breakdown Mesin', 'MTTR', 'MTBF', 'Availability', 'Control Stroke'],
            array_column($grup['items'], 'label')
        );

        $produksi = collect(SidebarMenu::forRole('manager'))->firstWhere('key', 'produksi');

        $this->assertNotContains('LHP Produksi', array_column($produksi['items'], 'label'));
    }

    public function test_guest_melihat_lhp_tapi_bukan_breakdown(): void
    {
        $produksi = collect(SidebarMenu::forRole('guest'))->firstWhere('key', 'produksi');
        $mtc = collect(SidebarMenu::forRole('guest'))->firstWhere('key', 'mtcmesin');

        $this->assertContains('LHP Produksi', array_column($produksi['items'], 'label'));
        $this->assertNotContains('Breakdown Mesin', array_column($mtc['items'], 'label'));
    }

    public function test_grup_sistem_hanya_untuk_admin(): void
    {
        // Satu arsip backup memuat seluruh database; grup ini tidak boleh bocor
        // ke peran lain lewat menu, sekalipun route-nya sudah dijaga role:admin.
        $this->assertContains('sistem', $this->keys('admin'));

        foreach (['manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'] as $role) {
            $this->assertNotContains('sistem', $this->keys($role), "Grup sistem bocor ke {$role}.");
        }
    }
```

Di `tests/Feature/Layout/SidebarNavTest.php`, halaman Users kini berada di grup `sistem`:

```php
        $this->actingAs($admin)->get(route('master.users.index'))
            ->assertOk()
            ->assertSee("navGroups('sistem')", false);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test tests/Unit/SidebarMenuTest.php tests/Feature/Layout/SidebarNavTest.php`
Expected: FAIL — key admin masih memuat `anomali`/`input`, grup `sistem` belum ada

- [ ] **Step 3: Tulis ulang config**

Ganti seluruh `return [...]` di `config/sidebar.php` dengan berikut. Komentar kepala berkas di atasnya dipertahankan, **kecuali** baris yang menjelaskan `accent` — kuncinya dibuang di langkah ini, dan komentar yang menjelaskan konfigurasi yang sudah tidak ada akan menyesatkan pembaca berikutnya.

```php
return [
    [
        'type' => 'link',
        'key' => 'overview',
        'label' => 'Overview',
        'icon' => 'bi-graph-up-arrow',
        'route' => 'overview',
        'roles' => ['admin', 'manager', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'guest'],
        'match' => ['overview'],
    ],

    [
        'type' => 'link',
        'key' => 'chatbot',
        'label' => 'AI Assistant',
        'icon' => 'bi-robot',
        'route' => 'chatbot.index',
        'roles' => ['admin', 'manager'],
        'match' => ['chatbot.*'],
    ],

    // Satu sumbu: domain. Halaman input tinggal serumah dengan dashboard yang
    // membacanya — yang menginput LHP Produksi adalah yang membaca VCB Produksi.
    [
        'type' => 'group',
        'key' => 'produksi',
        'label' => 'Produksi',
        'icon' => 'bi-box-seam',
        'items' => [
            ['label' => 'LHP Produksi', 'icon' => 'bi-clipboard-data', 'route' => 'input.produksi.index', 'roles' => ['admin', 'staff_produksi', 'guest'], 'match' => ['input.produksi.*']],
            ['label' => 'Dandori', 'icon' => 'bi-wrench-adjustable', 'route' => 'input.dandori.index', 'roles' => ['admin', 'staff_produksi', 'guest'], 'match' => ['input.dandori.*']],
            ['label' => 'VCB Produksi', 'icon' => 'bi-bar-chart', 'route' => 'vcb.produksi', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.produksi']],
            ['label' => 'VCB Mesin', 'icon' => 'bi-gear', 'route' => 'vcb.mesin', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.mesin']],
            ['label' => 'VCB Dandori', 'icon' => 'bi-clock-history', 'route' => 'vcb.dandori', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.dandori']],
            // TANPA wildcard: 'vcb.ng-proses*' akan ikut menelan
            // 'vcb.ng-proses.daftar' dan menyalakan dua entri sekaligus.
            ['label' => 'VCB NG Proses', 'icon' => 'bi-exclamation-triangle', 'route' => 'vcb.ng-proses', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.ng-proses']],
            ['label' => 'Daftar NG Proses', 'icon' => 'bi-list-ul', 'route' => 'vcb.ng-proses.daftar', 'roles' => ['admin', 'staff_produksi', 'manager', 'guest'], 'match' => ['vcb.ng-proses.daftar']],
        ],
    ],

    [
        'type' => 'group',
        'key' => 'quality',
        'label' => 'Quality',
        'icon' => 'bi-shield-check',
        'items' => [
            ['label' => 'LHP Quality', 'icon' => 'bi-clipboard-check', 'route' => 'input.qc.index', 'roles' => ['admin', 'staff_qc', 'guest'], 'match' => ['input.qc.*']],
            ['label' => 'VCB Quality', 'icon' => 'bi-shield-check', 'route' => 'vcb.quality', 'roles' => ['admin', 'staff_qc', 'manager', 'guest'], 'match' => ['vcb.quality']],
            ['label' => 'VCB NG Total', 'icon' => 'bi-exclamation-triangle', 'route' => 'vcb.ng-total', 'roles' => ['admin', 'staff_qc', 'manager', 'guest'], 'match' => ['vcb.ng-total']],
            ['label' => 'Daftar NG Total', 'icon' => 'bi-list-ul', 'route' => 'vcb.ng-total.daftar', 'roles' => ['admin', 'staff_qc', 'manager', 'guest'], 'match' => ['vcb.ng-total.daftar']],
        ],
    ],

    [
        'type' => 'group',
        'key' => 'mtcmesin',
        'label' => 'MTC Mesin',
        'icon' => 'bi-tools',
        'items' => [
            ['label' => 'Breakdown Mesin', 'icon' => 'bi-tools', 'route' => 'maintenance.breakdown.index', 'roles' => ['admin', 'staff_maintenance', 'manager'], 'match' => ['maintenance.breakdown.*']],
            ['label' => 'MTTR', 'icon' => 'bi-clock-history', 'route' => 'maintenance.mttr', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mttr']],
            ['label' => 'MTBF', 'icon' => 'bi-arrow-repeat', 'route' => 'maintenance.mtbf', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mtbf']],
            ['label' => 'Availability', 'icon' => 'bi-graph-up', 'route' => 'maintenance.availability', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.availability']],
            ['label' => 'Control Stroke', 'icon' => 'bi-speedometer2', 'route' => 'maintenance.control-stroke-mesin', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.control-stroke-mesin*']],
        ],
    ],

    [
        'type' => 'group',
        'key' => 'mtcdies',
        'label' => 'MTC Dies',
        'icon' => 'bi-hammer',
        'items' => [
            ['label' => 'Breakdown Dies', 'icon' => 'bi-hammer', 'route' => 'maintenance.breakdown-dies.index', 'roles' => ['admin', 'staff_maintenance', 'manager'], 'match' => ['maintenance.breakdown-dies.*']],
            ['label' => 'MTTR', 'icon' => 'bi-clock-history', 'route' => 'maintenance.mttr-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mttr-dies']],
            ['label' => 'MTBF', 'icon' => 'bi-arrow-repeat', 'route' => 'maintenance.mtbf-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.mtbf-dies']],
            ['label' => 'Availability', 'icon' => 'bi-graph-up', 'route' => 'maintenance.availability-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.availability-dies']],
            ['label' => 'Control Stroke', 'icon' => 'bi-speedometer2', 'route' => 'maintenance.control-stroke-dies', 'roles' => ['admin', 'staff_maintenance', 'manager', 'guest'], 'match' => ['maintenance.control-stroke-dies*']],
        ],
    ],

    // Master data pabrik. Users PINDAH ke grup Sistem: akun dan peran itu
    // administrasi sistem, bukan data pabrik.
    [
        'type' => 'group',
        'key' => 'master',
        'label' => 'Master Data',
        'icon' => 'bi-database',
        'items' => [
            ['label' => 'Karyawan', 'icon' => 'bi-people', 'route' => 'master.karyawan.index', 'roles' => ['admin'], 'match' => ['master.karyawan.*']],
            ['label' => 'Mesin', 'icon' => 'bi-cpu', 'route' => 'master.mesin.index', 'roles' => ['admin'], 'match' => ['master.mesin.*']],
            ['label' => 'Part / Dies', 'icon' => 'bi-box-seam', 'route' => 'master.part.index', 'roles' => ['admin'], 'match' => ['master.part.*']],
            ['label' => 'Loss Time', 'icon' => 'bi-stopwatch', 'route' => 'master.losstime.index', 'roles' => ['admin'], 'match' => ['master.losstime.*']],
            ['label' => 'Shift', 'icon' => 'bi-calendar-week', 'route' => 'master.shift.index', 'roles' => ['admin'], 'match' => ['master.shift.*']],
            ['label' => 'Jenis NG', 'icon' => 'bi-exclamation-octagon', 'route' => 'master.jenis-ng.index', 'roles' => ['admin'], 'match' => ['master.jenis-ng.*']],
        ],
    ],

    // Administrasi sistem. Audit Trail masuk ke sini saat halamannya ada —
    // plafonnya sudah disiapkan, dan menu yang menunjuk halaman yang belum
    // ada lebih buruk daripada menu yang belum ada.
    [
        'type' => 'group',
        'key' => 'sistem',
        'label' => 'Sistem',
        'icon' => 'bi-sliders',
        'items' => [
            ['label' => 'Anomali LHP', 'icon' => 'bi-exclamation-triangle-fill', 'route' => 'admin.anomali', 'roles' => ['admin'], 'match' => ['admin.anomali', 'admin.anomali.approve']],
            ['label' => 'Users', 'icon' => 'bi-person-gear', 'route' => 'master.users.index', 'roles' => ['admin'], 'match' => ['master.users.*']],
            ['label' => 'Backup', 'icon' => 'bi-download', 'route' => 'sistem.backup.index', 'roles' => ['admin'], 'match' => ['sistem.backup.*']],
        ],
    ],
];
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Unit/SidebarMenuTest.php tests/Feature/Layout/SidebarNavTest.php`
Expected: PASS

Run: `php artisan test tests/Feature/Layout/NgListSidebarTest.php`
Expected: PASS — satu sub-halaman NG tetap tidak menyalakan dua entri

- [ ] **Step 5: Buktikan plafonnya benar-benar dihitung ulang**

Plafon adalah alasan utama perubahan ini, jadi angkanya diperiksa langsung, bukan diasumsikan dari test hijau:

```bash
php artisan tinker --execute="
foreach (['admin','manager','staff_produksi','staff_qc','staff_maintenance','guest'] as \$r) {
  \$e = App\Support\SidebarMenu::forRole(\$r);
  \$g = array_map(fn(\$x) => (\$x['type'] ?? 'link') === 'group' ? count(\$x['items']) : 0, \$e);
  printf('%-18s atas=%d anak=%d terbuka=%d'.PHP_EOL, \$r, count(\$e), \$g ? max(\$g) : 0, count(\$e) + (\$g ? max(\$g) : 0));
}"
```

Expected: `admin atas=8 anak=7 terbuka=15`, dan tidak ada peran yang `atas > 9` atau `terbuka > 16`.

- [ ] **Step 6: Commit**

```bash
git add config/sidebar.php tests/Unit/SidebarMenuTest.php tests/Feature/Layout/SidebarNavTest.php
git commit -m "feat(sidebar): tata ulang menu menurut domain, tambah grup Sistem"
```

---

### Task 2: Penjaga satu route satu entri

**Files:**
- Modify: `tests/Unit/SidebarMenuTest.php` (tambah satu test)

**Interfaces:**
- Consumes: struktur dari Task 1
- Produces: —

Backup sempat punya dua pintu masuk (dropdown topbar dan rencana sidebar). Duplikat menu tidak pernah dilaporkan siapa pun — ia hanya membuat orang ragu mana yang benar — jadi ia butuh penjaga, bukan kehati-hatian.

- [ ] **Step 1: Write the failing test**

Tambahkan ke `tests/Unit/SidebarMenuTest.php`:

```php
    public function test_tidak_ada_route_yang_muncul_dua_kali(): void
    {
        // Dibaca dari config MENTAH, bukan forRole('admin'), supaya entri yang
        // hanya terlihat peran lain ikut tercakup.
        $routes = [];

        foreach (config('sidebar') as $entri) {
            if (($entri['type'] ?? 'link') === 'link') {
                $routes[] = $entri['route'];

                continue;
            }
            foreach ($entri['items'] as $anak) {
                $routes[] = $anak['route'];
            }
        }

        $ganda = array_keys(array_filter(array_count_values($routes), fn ($n) => $n > 1));

        $this->assertSame([], $ganda, 'Route muncul lebih dari sekali di sidebar: '.implode(', ', $ganda));
    }
```

- [ ] **Step 2: Buktikan penjaganya bisa jatuh**

Sisipkan sementara satu entri kembar di grup `sistem` pada `config/sidebar.php`:

```php
            ['label' => 'Backup (kembar)', 'icon' => 'bi-download', 'route' => 'sistem.backup.index', 'roles' => ['admin'], 'match' => ['sistem.backup.*']],
```

Run: `php artisan test tests/Unit/SidebarMenuTest.php --filter tidak_ada_route_yang_muncul_dua_kali`
Expected: FAIL — `Route muncul lebih dari sekali di sidebar: sistem.backup.index`

Hapus lagi entri kembarnya, jalankan ulang, dan pastikan hijau. Laporkan kedua keluarannya.

- [ ] **Step 3: Commit**

```bash
git add tests/Unit/SidebarMenuTest.php
git commit -m "test(sidebar): penjaga satu route tidak boleh punya dua entri menu"
```

---

### Task 3: Backup keluar dari dropdown topbar

**Files:**
- Modify: `resources/views/layouts/app.blade.php:1319-1323`
- Modify: `tests/Feature/Sistem/BackupHalamanTest.php` (dua test topbar)

**Interfaces:**
- Consumes: entri `sistem.backup.index` di sidebar dari Task 1
- Produces: —

Sesudah Task 1, Backup punya dua pintu masuk. Sidebar yang tinggal; dropdown topbar menyisakan Profil (masih dijaga `Route::has('profile.edit')` karena route itu memang belum pernah ada) dan Logout yang tetap POST ber-CSRF.

- [ ] **Step 1: Ubah dua test topbar jadi test sidebar**

Di `tests/Feature/Sistem/BackupHalamanTest.php`, ganti `test_admin_melihat_tautan_backup_di_topbar` dan `test_bukan_admin_tidak_melihat_tautan_backup`:

```php
    public function test_admin_melihat_tautan_backup_di_sidebar(): void
    {
        // Dijangkarkan ke <nav id="sidebar"> — route Backup juga muncul di
        // tempat lain pada halaman, jadi asersi ke seluruh HTML tidak pernah
        // bisa gagal.
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent();
        $awal = strpos($html, '<nav id="sidebar"');
        $this->assertNotFalse($awal, 'Sidebar tidak ditemukan.');
        $sidebar = substr($html, $awal, strpos($html, '</nav>', $awal) - $awal);

        $this->assertStringContainsString(route('sistem.backup.index'), $sidebar);
    }

    public function test_backup_tidak_lagi_ada_di_dropdown_topbar(): void
    {
        // Dua pintu masuk untuk satu halaman membuat orang ragu mana yang benar.
        $admin = User::factory()->create(['role' => 'admin']);

        $html = $this->actingAs($admin)->get(route('vcb.produksi'))->assertOk()->getContent();
        $awal = strpos($html, 'class="topbar-item user-block"');
        $this->assertNotFalse($awal, 'Blok user topbar tidak ditemukan.');
        $dropdown = substr($html, $awal, 1600);

        $this->assertStringNotContainsString(route('sistem.backup.index'), $dropdown);
        // Logout tetap di tempatnya, tetap POST ber-CSRF.
        $this->assertStringContainsString(route('logout'), $dropdown);
    }

    public function test_bukan_admin_tidak_melihat_tautan_backup(): void
    {
        // Kenyamanan, bukan pengaman — pengamannya role:admin di route.
        $manager = User::factory()->create(['role' => 'manager']);

        $this->actingAs($manager)
            ->get(route('vcb.produksi'))
            ->assertDontSee(route('sistem.backup.index'), false);
    }
```

- [ ] **Step 2: Run tests to verify the topbar one fails**

Run: `php artisan test tests/Feature/Sistem/BackupHalamanTest.php`
Expected: FAIL pada `test_backup_tidak_lagi_ada_di_dropdown_topbar` — entrinya masih ada di dropdown

- [ ] **Step 3: Cabut entrinya dari dropdown**

Di `resources/views/layouts/app.blade.php`, hapus blok ini seutuhnya:

```blade
                @if($u->role === 'admin')
                <a href="{{ route('sistem.backup.index') }}" class="dropdown-item">
                    <i class="bi bi-download me-2"></i>Backup Sistem
                </a>
                @endif
```

Jangan sentuh blok Profil di atasnya maupun form Logout di bawahnya.

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test tests/Feature/Sistem/BackupHalamanTest.php`
Expected: PASS

Run: `php artisan test tests/Feature/Layout/`
Expected: PASS — beberapa test di sana membaca layout yang sama

Run: `grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/`
Expected: tidak ada keluaran

- [ ] **Step 5: Commit**

```bash
git add resources/views/layouts/app.blade.php tests/Feature/Sistem/BackupHalamanTest.php
git commit -m "fix(backup): satu pintu masuk — Backup di sidebar, keluar dari topbar"
```

---

### Task 4: Gerbang akhir & PR

**Files:** seluruh suite

- [ ] **Step 1: Jalankan seluruh gerbang**

```bash
php artisan test
```
Expected: PASS — 936 test lama disesuaikan, nol gagal

```bash
vendor/bin/pint app tests
```
Expected: `PASS`

```bash
grep -rnE '\w@(if|endif|else|elseif|foreach|endforeach|selected)\b' resources/views/
```
Expected: tidak ada keluaran

```bash
./scripts/check-migration-coverage.sh
```
Expected: `✓ Cakupan migrasi lengkap` — **jumlahnya tidak berubah**, plan ini tidak menambah migrasi

- [ ] **Step 2: Periksa mata, bukan cuma test**

Sidebar rusaknya cuma terlihat mata — test hijau tidak membuktikan kedelapan barisnya muat.
Cara yang sudah terbukti di repo ini (dipakai saat memeriksa halaman Backup): tulis satu test
sementara yang login sebagai `admin`, meng-GET `route('sistem.backup.index')`, lalu
`file_put_contents()` isi responsnya ke sebuah berkas `.html` **di dalam folder proyek**
(berkas di luar folder proyek hanya dirender sebagai cuplikan statis). Buka berkas itu di
peramban pada 1366×768, lalu **hapus lagi berkas dan test sementaranya**.

Yang diperiksa: kedelapan baris muat tanpa sidebar menggulung · grup Sistem tampil terbuka
dengan Backup ditandai aktif · tidak ada baris terpotong di bawah.

Laporkan apa yang terlihat, bukan cuma bahwa langkahnya dijalankan. Pemeriksaan seperti ini
menemukan bug nyata terakhir kali, bukan sekadar menilai tampilan.

- [ ] **Step 3: Push dan buka PR**

```bash
git push -u origin feat/sidebar-domain-first
```

Badan PR wajib memuat: tautan ke spec, tabel struktur lama vs baru, angka plafon **per peran** sebelum dan sesudah, keempat konsekuensi yang disengaja (Backup keluar dari topbar · aksen Anomali LHP hilang · key `input` yatim di localStorage · muscle memory berubah), dan pernyataan bahwa Audit Trail sengaja belum ditambahkan.

- [ ] **Step 4: Tunggu CI hijau sebelum merge**

```bash
gh pr checks <nomor>
```
Expected: `test pass`

---

## Verifikasi Manual Setelah Deploy

1. Masuk sebagai `admin` — sidebar delapan baris, tidak menggulung, tidak ada baris terpotong di layar 1366×768.
2. Buka `/sistem/backup` — grup **Sistem** tampil terbuka dan entri Backup ditandai aktif.
3. Buka `/master/users` — grup **Sistem** yang menyala, bukan Master Data.
4. Buka `/vcb/ng-proses` lalu `/vcb/ng-proses/daftar` — **hanya satu** entri menyala di masing-masing halaman.
5. Buka dropdown topbar — tidak ada lagi entri Backup Sistem; Logout tetap berfungsi.
6. Masuk sebagai `staff_produksi` — semua halaman hariannya (LHP Produksi, Dandori, VCB) ada dalam **satu** grup Produksi.
7. Masuk sebagai `guest` — grup Sistem tidak muncul sama sekali.
