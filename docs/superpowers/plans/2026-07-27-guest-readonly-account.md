# Akun Guest Read-Only — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Menambah role `guest` di OPTIMA — akun shared untuk pihak eksternal yang bisa melihat VCB, analitik maintenance, dan daftar LHP, tapi tidak bisa mengubah data, mengunduh export, atau membuka master data.

**Architecture:** Default-deny dua lapis. Lapis pertama: route GET yang boleh guest dipindah ke grup `role:...,guest` sendiri di `routes/web.php`, sehingga route baru yang dibuat kemudian otomatis tertutup. Lapis kedua: middleware web global `DenyGuestWrite` menolak semua request non-GET dari user ber-role `guest`, dengan pengecualian tunggal route `logout`.

**Tech Stack:** Laravel 13, PHP 8.5, MySQL (prod) / SQLite (test), Blade + Bootstrap 5.3, PHPUnit via `php artisan test`.

**Spec:** `docs/superpowers/specs/2026-07-27-guest-readonly-account-design.md`

## Global Constraints

- Role string persis `guest` (lowercase, tanpa prefix).
- Guest **tidak boleh**: semua route `master.*`, semua create/store/edit/update/destroy, semua import & import-template, semua export (`input.produksi.export`, `input.qc.export`, `input.dandori.export`, `vcb.dandori.export`, `master.part.export`, `maintenance.breakdown.export`, `maintenance.breakdown-dies.export`), `maintenance.breakdown.index`, `maintenance.breakdown-dies.index`, `maintenance.control-stroke-dies.preventive.checksheet`, `maintenance.control-stroke-mesin.preventive.create`, `maintenance.control-stroke-dies.preventive.create`, `chatbot.*`, `admin.anomali`.
- Guest **boleh** hanya route GET yang disebut eksplisit di Task 3.
- Jangan pakai direktif Blade `@guest` bawaan Laravel untuk pengecekan role ini — artinya berbeda (belum login). Pakai `auth()->user()->isGuest()`.
- Migration harus punya cabang MySQL (raw `ALTER`) dan non-MySQL (`->change()`), ikut pola `database/migrations/2026_07_15_000002_add_staff_maintenance_to_users_role_enum.php`.
- Tidak ada seeder dan tidak ada self-registration. Akun guest dibuat admin lewat Master > Users.
- Semua test dijalankan dengan `php artisan test`. Seluruh suite harus hijau sebelum PR.
- Branch kerja: `feat/guest-readonly` (sudah dibuat dari `main`, berisi commit spec).

---

## File Structure

**Dibuat:**
- `database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php` — perluas enum `users.role`
- `app/Http/Middleware/DenyGuestWrite.php` — jaring pengaman non-GET
- `tests/Feature/Master/GuestRoleTest.php` — role tersimpan & tervalidasi
- `tests/Feature/GuestWriteBlockTest.php` — middleware berdiri sendiri
- `tests/Feature/Layout/GuestUiTest.php` — sidebar & tombol tulis tersembunyi

**Dimodifikasi:**
- `app/Models/User.php` — helper `isGuest()`
- `app/Http/Requests/StoreMasterUserRequest.php:23` — aturan `in:`
- `resources/views/master/users/{create,edit,index}.blade.php` — opsi dropdown role
- `bootstrap/app.php` — daftarkan middleware web global
- `routes/web.php` — pecah grup route jadi default-deny
- `resources/views/layouts/app.blade.php` — sidebar + badge mode tamu
- `resources/views/input/{produksi,qc,dandori}/index.blade.php` — sembunyikan tombol tulis
- `resources/views/vcb/{produksi,quality,mesin,dandori}.blade.php` — sembunyikan tombol export
- `resources/views/maintenance/control-stroke-dies-history.blade.php` — sembunyikan link checksheet
- `tests/Feature/AuthorizationTest.php` — matriks akses guest

---

## Task 1: Role `guest` — enum DB, helper model, validasi, form user

**Files:**
- Create: `database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php`
- Create: `tests/Feature/Master/GuestRoleTest.php`
- Modify: `app/Models/User.php:48-51` (tambah method setelah `hasRole()`)
- Modify: `app/Http/Requests/StoreMasterUserRequest.php:23`
- Modify: `resources/views/master/users/create.blade.php:41-47`
- Modify: `resources/views/master/users/edit.blade.php:42-47`
- Modify: `resources/views/master/users/index.blade.php:91-96`

**Interfaces:**
- Consumes: `User::hasRole()` yang sudah ada, `UserFactory` (default `role` = `staff_produksi`).
- Produces: `User::isGuest(): bool` — dipakai Task 2 (middleware) dan Task 4 (Blade). Role string `'guest'` valid di kolom `users.role` — dipakai semua task berikutnya.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Master/GuestRoleTest.php`:

```php
<?php

namespace Tests\Feature\Master;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Role 'guest' harus valid di kolom users.role, tervalidasi di form request,
 * dan terdeteksi lewat helper model.
 */
class GuestRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_model_flags_guest_role(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);
        $admin = User::factory()->create(['role' => 'admin']);

        $this->assertTrue($guest->isGuest());
        $this->assertFalse($admin->isGuest());
    }

    public function test_admin_can_create_guest_user(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('master.users.store'), [
            'name' => 'Tamu Eksternal',
            'email' => 'tamu@example.com',
            'password' => 'rahasia-tamu-123',
            'role' => 'guest',
        ])->assertRedirect(route('master.users.index'));

        $this->assertDatabaseHas('users', [
            'email' => 'tamu@example.com',
            'role' => 'guest',
        ]);
    }

    public function test_unknown_role_is_rejected(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post(route('master.users.store'), [
            'name' => 'Peran Ngawur',
            'email' => 'ngawur@example.com',
            'password' => 'rahasia-tamu-123',
            'role' => 'superuser',
        ])->assertSessionHasErrors('role');
    }

    public function test_role_dropdown_offers_guest_option(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('master.users.create'))
            ->assertOk()
            ->assertSee('value="guest"', false);
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
php artisan test tests/Feature/Master/GuestRoleTest.php
```

Expected: FAIL — `test_model_flags_guest_role` error `Call to undefined method App\Models\User::isGuest()`, `test_admin_can_create_guest_user` gagal karena validasi `role` menolak `guest`.

- [ ] **Step 3: Buat migration enum**

Buat `database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tambah role 'guest' (akun read-only untuk pihak eksternal) ke enum users.role.
 * MySQL pakai raw ALTER; SQLite (test) pakai ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','staff_produksi','staff_qc','manager','staff_maintenance','guest') NOT NULL DEFAULT 'staff_produksi'");

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'staff_produksi', 'staff_qc', 'manager', 'staff_maintenance', 'guest'])
                ->default('staff_produksi')->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','staff_produksi','staff_qc','manager','staff_maintenance') NOT NULL DEFAULT 'staff_produksi'");

            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'staff_produksi', 'staff_qc', 'manager', 'staff_maintenance'])
                ->default('staff_produksi')->change();
        });
    }
};
```

- [ ] **Step 4: Tambah helper di model**

Di `app/Models/User.php`, tepat setelah method `hasRole()` (baris 48-51):

```php
    /**
     * Akun tamu read-only. Dipakai DenyGuestWrite dan Blade untuk menyembunyikan aksi tulis.
     * Jangan pakai direktif @guest bawaan Laravel — itu artinya "belum login".
     */
    public function isGuest(): bool
    {
        return $this->role === 'guest';
    }
```

- [ ] **Step 5: Longgarkan validasi role**

Di `app/Http/Requests/StoreMasterUserRequest.php:23`, ganti baris:

```php
            'role'        => 'required|in:admin,staff_produksi,staff_qc,manager,staff_maintenance',
```

jadi:

```php
            'role'        => 'required|in:admin,staff_produksi,staff_qc,manager,staff_maintenance,guest',
```

- [ ] **Step 6: Tambah opsi di 3 dropdown role**

Di `resources/views/master/users/create.blade.php`, setelah baris opsi `manager`:

```blade
                    <option value="guest"             {{ old('role') === 'guest'             ? 'selected' : '' }}>Guest (Read-Only)</option>
```

Di `resources/views/master/users/edit.blade.php`, setelah baris opsi `manager`:

```blade
                    <option value="guest"             {{ old('role', $user->role) === 'guest'             ? 'selected' : '' }}>Guest (Read-Only)</option>
```

Di `resources/views/master/users/index.blade.php`, setelah baris opsi `manager`:

```blade
                        <option value="guest"             {{ old('role') === 'guest'             ? 'selected' : '' }}>Guest (Read-Only)</option>
```

- [ ] **Step 7: Jalankan test, pastikan LULUS**

```bash
php artisan test tests/Feature/Master/GuestRoleTest.php
```

Expected: PASS, 4 test.

- [ ] **Step 8: Commit**

```bash
git add database/migrations/2026_07_27_000001_add_guest_to_users_role_enum.php app/Models/User.php app/Http/Requests/StoreMasterUserRequest.php resources/views/master/users tests/Feature/Master/GuestRoleTest.php
git commit -m "feat(auth): role guest — enum users.role, helper isGuest, opsi form user"
```

---

## Task 2: Middleware `DenyGuestWrite`

**Files:**
- Create: `app/Http/Middleware/DenyGuestWrite.php`
- Create: `tests/Feature/GuestWriteBlockTest.php`
- Modify: `bootstrap/app.php:13-17`

**Interfaces:**
- Consumes: `User::isGuest()` dari Task 1.
- Produces: middleware web global yang menolak request non-safe dari guest. Task 3 mengandalkan ini sebagai lapis kedua, jadi grup route di Task 3 tidak perlu mengulang proteksi verb.

Test di task ini sengaja memakai route yang **tidak** punya middleware `role` (`password.update` dan `/notifications/read-all`), supaya 403 yang muncul membuktikan middleware ini bekerja sendiri — bukan efek samping `CheckRole`.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/GuestWriteBlockTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lapis kedua proteksi guest: middleware DenyGuestWrite.
 * Route yang diuji sengaja TIDAK punya middleware role, jadi 403 di sini
 * membuktikan middleware bekerja berdiri sendiri.
 */
class GuestWriteBlockTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_post_to_route_without_role_middleware(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post('/notifications/read-all')->assertForbidden();
    }

    public function test_guest_cannot_change_own_password(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->put(route('password.update'), [
            'current_password' => 'password',
            'password' => 'password-baru-123',
            'password_confirmation' => 'password-baru-123',
        ])->assertForbidden();
    }

    public function test_guest_can_still_logout(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('logout'))->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_non_guest_roles_are_untouched(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->post('/notifications/read-all')->assertOk();
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
php artisan test tests/Feature/GuestWriteBlockTest.php
```

Expected: FAIL — `test_guest_cannot_post_to_route_without_role_middleware` dan `test_guest_cannot_change_own_password` dapat 200/302, bukan 403. Dua test lain sudah lulus sejak awal (itu memang kontrol pembanding).

- [ ] **Step 3: Buat middleware**

Buat `app/Http/Middleware/DenyGuestWrite.php`:

```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lapis kedua proteksi role 'guest': tolak semua request yang mengubah state.
 *
 * Lapis pertama ada di grup route (routes/web.php) — guest hanya terdaftar di
 * grup berisi route GET. Middleware ini menangkap kasus route tulis yang keliru
 * dimasukkan ke grup guest di kemudian hari.
 */
class DenyGuestWrite
{
    /** Route yang tetap boleh dipanggil guest meski method-nya bukan GET. */
    private const ALLOWED_ROUTES = ['logout'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->isGuest() && ! $request->isMethodSafe()) {
            $routeName = $request->route()?->getName();

            if (! in_array($routeName, self::ALLOWED_ROUTES, true)) {
                abort(403, 'Akun tamu hanya bisa melihat data.');
            }
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Daftarkan sebagai middleware web global**

Di `bootstrap/app.php`, ganti blok `withMiddleware` jadi:

```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\CheckRole::class,
        ]);

        // Global (bukan cuma grup auth di routes/web.php) supaya route di
        // routes/auth.php — mis. password.update — ikut tercakup.
        $middleware->web(append: [
            \App\Http\Middleware\DenyGuestWrite::class,
        ]);
    })
```

- [ ] **Step 5: Jalankan test, pastikan LULUS**

```bash
php artisan test tests/Feature/GuestWriteBlockTest.php
```

Expected: PASS, 4 test.

- [ ] **Step 6: Pastikan tidak ada role lain yang rusak**

```bash
php artisan test
```

Expected: seluruh suite hijau (309 test + 8 test baru dari Task 1-2). Kalau ada test lain yang merah, penyebabnya middleware baru — perbaiki sebelum commit.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/DenyGuestWrite.php bootstrap/app.php tests/Feature/GuestWriteBlockTest.php
git commit -m "feat(auth): middleware DenyGuestWrite — tolak semua request non-GET dari guest"
```

---

## Task 3: Route default-deny + redirect dashboard

**Files:**
- Modify: `routes/web.php:34-42` (dashboard), `:83-96` (input produksi/dandori), `:118-124` (input qc), `:127-136` (vcb produksi/mesin/dandori), `:139-143` (vcb quality), `:146-184` (maintenance)
- Modify: `tests/Feature/AuthorizationTest.php`

**Interfaces:**
- Consumes: role `guest` dari Task 1, middleware `DenyGuestWrite` dari Task 2.
- Produces: nama route tidak berubah sama sekali (`vcb.produksi`, `input.produksi.index`, `maintenance.mttr`, dst) — hanya keanggotaan grup middleware-nya yang berubah. Task 4 memakai nama route yang sama.

- [ ] **Step 1: Tulis test yang gagal**

Tambahkan method berikut ke `tests/Feature/AuthorizationTest.php` (di dalam class, setelah `test_dashboard_redirects_by_role`):

```php
    /** Route yang boleh dibuka guest. */
    public static function guestAllowedRoutes(): array
    {
        return [
            ['vcb.produksi'], ['vcb.produksi.data'], ['vcb.produksi.operator-data'],
            ['vcb.mesin'], ['vcb.mesin.data'],
            ['vcb.dandori'], ['vcb.dandori.data'],
            ['vcb.quality'], ['vcb.quality.data'], ['vcb.quality.operator-data'],
            ['maintenance.mttr'], ['maintenance.mttr.data'],
            ['maintenance.mtbf'], ['maintenance.mtbf.data'],
            ['maintenance.availability'], ['maintenance.availability.data'],
            ['maintenance.mttr-dies'], ['maintenance.mttr-dies.data'],
            ['maintenance.mtbf-dies'], ['maintenance.mtbf-dies.data'],
            ['maintenance.availability-dies'], ['maintenance.availability-dies.data'],
            ['maintenance.control-stroke-mesin'], ['maintenance.control-stroke-mesin.data'],
            ['maintenance.control-stroke-dies'], ['maintenance.control-stroke-dies.data'],
            ['input.produksi.index'], ['input.qc.index'], ['input.dandori.index'],
        ];
    }

    /** Route yang HARUS ditolak untuk guest. */
    public static function guestBlockedRoutes(): array
    {
        return [
            ['master.karyawan.index'], ['master.mesin.index'], ['master.part.index'],
            ['master.losstime.index'], ['master.shift.index'], ['master.users.index'],
            ['master.jenis-ng.index'], ['master.users.create'],
            ['master.part.export'],
            ['input.produksi.export'], ['input.qc.export'], ['input.dandori.export'],
            ['vcb.dandori.export'],
            ['input.produksi.create'], ['input.qc.create'], ['input.dandori.create'],
            ['input.produksi.import-template'], ['input.qc.import-template'], ['input.dandori.import-template'],
            ['maintenance.breakdown.index'], ['maintenance.breakdown-dies.index'],
            ['maintenance.breakdown.export'], ['maintenance.breakdown-dies.export'],
            ['maintenance.breakdown.create'],
            ['chatbot.index'], ['admin.anomali'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestAllowedRoutes')]
    public function test_guest_can_view_allowed_routes(string $routeName): void
    {
        $this->actingAs($this->user('guest'))->get(route($routeName))->assertOk();
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('guestBlockedRoutes')]
    public function test_guest_cannot_view_blocked_routes(string $routeName): void
    {
        $this->actingAs($this->user('guest'))->get(route($routeName))->assertForbidden();
    }

    public function test_guest_cannot_open_control_stroke_write_routes(): void
    {
        $guest = $this->user('guest');

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-mesin.preventive.create', ['mesin' => 1]))
            ->assertForbidden();

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-dies.preventive.create', ['partProses' => 1]))
            ->assertForbidden();

        $this->actingAs($guest)
            ->get(route('maintenance.control-stroke-dies.preventive.checksheet', ['diesPreventiveLog' => 1]))
            ->assertForbidden();
    }

    public function test_guest_write_verbs_are_rejected_on_reachable_pages(): void
    {
        $guest = $this->user('guest');

        $this->actingAs($guest)->post(route('input.produksi.note'), [
            'id' => 1, 'note' => 'coba tulis',
        ])->assertForbidden();

        $this->actingAs($guest)->delete(route('input.produksi.destroy', ['produksi' => 1]))->assertForbidden();
    }

    public function test_dashboard_redirects_guest_to_vcb_produksi(): void
    {
        $this->actingAs($this->user('guest'))->get('/dashboard')->assertRedirect('/vcb/produksi');
    }
```

Catatan untuk pelaksana: `route()` butuh parameter untuk route ber-binding — itu sebabnya kasus control-stroke dan destroy ditulis manual, bukan lewat data provider. ID `1` boleh tidak ada di DB: middleware role menolak duluan sebelum model binding jalan.

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
php artisan test tests/Feature/AuthorizationTest.php
```

Expected: FAIL — semua `test_guest_can_view_allowed_routes` dapat 403 (guest belum terdaftar di grup mana pun).

- [ ] **Step 3: Pecah grup INPUT PRODUKSI/DANDORI**

Di `routes/web.php`, ganti blok baris 83-96 jadi:

```php
    // --- INPUT PRODUKSI: daftar (read-only, guest boleh) ---
    Route::middleware('role:admin,staff_produksi,guest')->prefix('input')->name('input.')->group(function () {
        Route::get('produksi', [ProduksiController::class, 'index'])->name('produksi.index');
        Route::get('dandori',  [DandoriController::class, 'index'])->name('dandori.index');
    });

    // --- INPUT PRODUKSI: tulis & export (guest TIDAK boleh) ---
    Route::middleware('role:admin,staff_produksi')->prefix('input')->name('input.')->group(function () {
        Route::resource('produksi', ProduksiController::class)->only(['create', 'store', 'destroy']);
        Route::post('produksi/import', [ProduksiController::class, 'import'])->name('produksi.import');
        Route::get('produksi/import-template', [ProduksiController::class, 'importTemplate'])->name('produksi.import-template');
        Route::get('produksi/export', [ProduksiController::class, 'export'])->name('produksi.export');
        Route::post('produksi-note', [ProduksiController::class, 'note'])->name('produksi.note');
        Route::resource('dandori', DandoriController::class)->only(['create', 'store', 'destroy']);
        Route::post('dandori/import',          [DandoriController::class, 'import'])->name('dandori.import');
        Route::get('dandori/import-template',  [DandoriController::class, 'importTemplate'])->name('dandori.import-template');
        Route::get('dandori/export',           [DandoriController::class, 'export'])->name('dandori.export');
        Route::post('dandori-note', [DandoriController::class, 'note'])->name('dandori.note');
        // AJAX: get proses list for a part (used by form JS)
        Route::get('dandori/part-proses/{part}', [DandoriController::class, 'getPartProses'])->name('dandori.part-proses');
    });
```

- [ ] **Step 4: Pecah grup INPUT QC**

Ganti blok baris 118-124 jadi:

```php
    // --- INPUT QC: daftar (read-only, guest boleh) ---
    Route::middleware('role:admin,staff_qc,guest')->prefix('input')->name('input.')->group(function () {
        Route::get('qc', [QcController::class, 'index'])->name('qc.index');
    });

    // --- INPUT QC: tulis & export (guest TIDAK boleh) ---
    Route::middleware('role:admin,staff_qc')->prefix('input')->name('input.')->group(function () {
        Route::resource('qc', QcController::class)->only(['create', 'store', 'destroy']);
        Route::post('qc/import', [QcController::class, 'import'])->name('qc.import');
        Route::get('qc/import-template', [QcController::class, 'importTemplate'])->name('qc.import-template');
        Route::get('qc/export', [QcController::class, 'export'])->name('qc.export');
        Route::post('qc-note', [QcController::class, 'note'])->name('qc.note');
    });
```

- [ ] **Step 5: Pecah grup VCB**

Ganti blok baris 127-143 jadi:

```php
    // --- VCB PRODUKSI, MESIN, DANDORI — read-only, guest boleh ---
    Route::middleware('role:admin,staff_produksi,manager,guest')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('produksi', [VcbProduksiController::class, 'index'])->name('produksi');
        Route::get('produksi/data', [VcbProduksiController::class, 'data'])->name('produksi.data');
        Route::get('produksi/operator-data', [VcbProduksiController::class, 'operatorData'])->name('produksi.operator-data');
        Route::get('mesin', [VcbMesinController::class, 'index'])->name('mesin');
        Route::get('mesin/data', [VcbMesinController::class, 'data'])->name('mesin.data');
        Route::get('dandori', [VcbDandoriController::class, 'index'])->name('dandori');
        Route::get('dandori/data', [VcbDandoriController::class, 'data'])->name('dandori.data');
    });

    // --- VCB DANDORI EXPORT — guest TIDAK boleh ---
    Route::middleware('role:admin,staff_produksi,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('dandori/export', [VcbDandoriController::class, 'export'])->name('dandori.export');
    });

    // --- VCB QUALITY — read-only, guest boleh ---
    Route::middleware('role:admin,staff_qc,manager,guest')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('quality', [VcbQualityController::class, 'index'])->name('quality');
        Route::get('quality/data', [VcbQualityController::class, 'data'])->name('quality.data');
        Route::get('quality/operator-data', [VcbQualityController::class, 'operatorData'])->name('quality.operator-data');
    });
```

- [ ] **Step 6: Pecah grup MAINTENANCE**

Ganti blok baris 146-184 jadi dua grup. Pertama yang tetap tertutup untuk guest:

```php
    // --- MAINTENANCE: breakdown, import/export, input preventive (guest TIDAK boleh) ---
    Route::middleware('role:admin,staff_maintenance,manager')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('breakdown/last-no-lkm', [BreakdownMesinController::class, 'lastNoLkm'])->name('breakdown.last-no-lkm');
        Route::get('breakdown/export', [BreakdownMesinController::class, 'export'])->name('breakdown.export');
        Route::post('breakdown/import', [BreakdownMesinController::class, 'import'])->name('breakdown.import');
        Route::get('breakdown/import-template', [BreakdownMesinController::class, 'importTemplate'])->name('breakdown.import-template');
        Route::resource('breakdown', BreakdownMesinController::class)->only(['index', 'create', 'store']);

        Route::get('breakdown-dies/last-no-lkd', [BreakdownDiesController::class, 'lastNoLkd'])->name('breakdown-dies.last-no-lkd');
        Route::get('breakdown-dies/export', [BreakdownDiesController::class, 'export'])->name('breakdown-dies.export');
        Route::post('breakdown-dies/import', [BreakdownDiesController::class, 'import'])->name('breakdown-dies.import');
        Route::get('breakdown-dies/import-template', [BreakdownDiesController::class, 'importTemplate'])->name('breakdown-dies.import-template');
        Route::resource('breakdown-dies', BreakdownDiesController::class)->only(['index', 'store'])->parameters(['breakdown-dies' => 'breakdownDy']);

        Route::get('availability/control-stroke-mesin/{mesin}/preventive', [ControlStrokeMesinController::class, 'createPreventive'])->name('control-stroke-mesin.preventive.create');
        Route::post('availability/control-stroke-mesin/{mesin}/preventive', [ControlStrokeMesinController::class, 'storePreventive'])->name('control-stroke-mesin.preventive');

        Route::get('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'createPreventive'])->name('control-stroke-dies.preventive.create');
        Route::post('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'storePreventive'])->name('control-stroke-dies.preventive');
        Route::get('availability/control-stroke-dies/preventive/{diesPreventiveLog}/checksheet', [ControlStrokeDiesController::class, 'showChecksheet'])->name('control-stroke-dies.preventive.checksheet');
    });
```

Lalu grup analitik yang boleh guest:

```php
    // --- MAINTENANCE: analitik read-only (guest boleh) ---
    Route::middleware('role:admin,staff_maintenance,manager,guest')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('mttr',          [MttrController::class, 'index'])->name('mttr');
        Route::get('mttr/data',     [MttrController::class, 'data'])->name('mttr.data');
        Route::get('mtbf',          [MtbfController::class, 'index'])->name('mtbf');
        Route::get('mtbf/data',     [MtbfController::class, 'data'])->name('mtbf.data');
        Route::get('availability',      [AvailabilityController::class, 'index'])->name('availability');
        Route::get('availability/data', [AvailabilityController::class, 'data'])->name('availability.data');

        Route::get('mttr-dies',          [MttrDiesController::class, 'index'])->name('mttr-dies');
        Route::get('mttr-dies/data',     [MttrDiesController::class, 'data'])->name('mttr-dies.data');
        Route::get('mtbf-dies',          [MtbfDiesController::class, 'index'])->name('mtbf-dies');
        Route::get('mtbf-dies/data',     [MtbfDiesController::class, 'data'])->name('mtbf-dies.data');
        Route::get('availability-dies',      [AvailabilityDiesController::class, 'index'])->name('availability-dies');
        Route::get('availability-dies/data', [AvailabilityDiesController::class, 'data'])->name('availability-dies.data');

        Route::get('availability/control-stroke-mesin',       [ControlStrokeMesinController::class, 'index'])->name('control-stroke-mesin');
        Route::get('availability/control-stroke-mesin/data',   [ControlStrokeMesinController::class, 'data'])->name('control-stroke-mesin.data');
        Route::get('availability/control-stroke-mesin/{mesin}/history', [ControlStrokeMesinController::class, 'history'])->name('control-stroke-mesin.history');

        Route::get('availability/control-stroke-dies',        [ControlStrokeDiesController::class, 'index'])->name('control-stroke-dies');
        Route::get('availability/control-stroke-dies/data',    [ControlStrokeDiesController::class, 'data'])->name('control-stroke-dies.data');
        Route::get('availability/control-stroke-dies/{partProses}/history', [ControlStrokeDiesController::class, 'history'])->name('control-stroke-dies.history');
    });
```

Perhatikan: route statis `availability/control-stroke-dies/preventive/{diesPreventiveLog}/checksheet` ada di grup pertama dan route ber-parameter `availability/control-stroke-dies/{partProses}/history` di grup kedua. Grup pertama harus tetap dideklarasikan **lebih dulu** supaya segmen statis `preventive` menang atas `{partProses}`.

- [ ] **Step 7: Tambah cabang dashboard**

Di `routes/web.php:34-42`, tambah satu cabang di `match(true)` sebelum `default`:

```php
            $role === 'guest' => '/vcb/produksi',
```

- [ ] **Step 8: Jalankan test, pastikan LULUS**

```bash
php artisan test tests/Feature/AuthorizationTest.php
```

Expected: PASS. Semua route di `guestAllowedRoutes` → 200, semua di `guestBlockedRoutes` → 403.

Kalau ada endpoint `*.data` yang balas 500 di DB kosong (bukan 403), itu bug lama yang baru kelihatan — endpoint itu memang belum pernah diuji tanpa data. Perbaiki penyebabnya di controller terkait dan sebutkan di badan PR; jangan hapus route itu dari data provider.

- [ ] **Step 9: Pastikan role lama tidak berubah aksesnya**

```bash
php artisan test
```

Expected: seluruh suite hijau. Pemecahan grup route tidak boleh mengubah akses admin/staff/manager — kalau ada test lama merah, berarti ada route yang jatuh ke grup yang salah.

- [ ] **Step 10: Commit**

```bash
git add routes/web.php tests/Feature/AuthorizationTest.php
git commit -m "feat(auth): route default-deny untuk guest + redirect dashboard"
```

---

## Task 4: UI — sidebar, badge mode tamu, sembunyikan tombol tulis

**Files:**
- Create: `tests/Feature/Layout/GuestUiTest.php`
- Modify: `resources/views/layouts/app.blade.php:896-902` (badge), `:933`, `:936`, `:950`, `:984`, `:1005`, `:1025`, `:1039`, `:1062` (sidebar)
- Modify: `resources/views/input/produksi/index.blade.php:262-282`, `:295`, `:286-292`, `:788-797`
- Modify: `resources/views/input/qc/index.blade.php:257-277`, panel import & export
- Modify: `resources/views/input/dandori/index.blade.php` (blok `action-btns` header, panel import & export, tombol note non-admin)
- Modify: `resources/views/vcb/produksi.blade.php:157`, `resources/views/vcb/quality.blade.php:155`, `resources/views/vcb/mesin.blade.php:36`, `resources/views/vcb/dandori.blade.php:176`
- Modify: `resources/views/maintenance/control-stroke-dies-history.blade.php`

**Interfaces:**
- Consumes: `User::isGuest()` (Task 1), route yang boleh guest (Task 3).
- Produces: tidak ada API baru. Ini murni lapis tampilan — proteksi sebenarnya tetap Task 2 + Task 3.

- [ ] **Step 1: Tulis test yang gagal**

Buat `tests/Feature/Layout/GuestUiTest.php`:

```php
<?php

namespace Tests\Feature\Layout;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Guest tidak boleh melihat kontrol yang tidak bisa dia pakai:
 * link master data, link breakdown, tombol input/import/export.
 */
class GuestUiTest extends TestCase
{
    use RefreshDatabase;

    private function guest(): User
    {
        return User::factory()->create(['role' => 'guest']);
    }

    public function test_sidebar_hides_master_and_breakdown_links(): void
    {
        $response = $this->actingAs($this->guest())->get(route('vcb.produksi'))->assertOk();

        $response->assertDontSee(route('master.karyawan.index'));
        $response->assertDontSee(route('master.users.index'));
        $response->assertDontSee(route('maintenance.breakdown.index'));
        $response->assertDontSee(route('maintenance.breakdown-dies.index'));
        $response->assertDontSee(route('chatbot.index'));
    }

    public function test_sidebar_keeps_allowed_links(): void
    {
        $response = $this->actingAs($this->guest())->get(route('vcb.produksi'))->assertOk();

        $response->assertSee(route('vcb.quality'));
        $response->assertSee(route('maintenance.mttr'));
        $response->assertSee(route('input.produksi.index'));
    }

    public function test_topbar_shows_read_only_badge(): void
    {
        $this->actingAs($this->guest())->get(route('vcb.produksi'))
            ->assertOk()
            ->assertSee('MODE TAMU');
    }

    public function test_admin_does_not_see_read_only_badge(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.produksi'))
            ->assertOk()
            ->assertDontSee('MODE TAMU');
    }

    public function test_lhp_pages_hide_write_buttons_for_guest(): void
    {
        $guest = $this->guest();

        foreach (['input.produksi.index', 'input.qc.index', 'input.dandori.index'] as $routeName) {
            $response = $this->actingAs($guest)->get(route($routeName))->assertOk();

            $response->assertDontSee('Input Baru');
            $response->assertDontSee('Import Excel');
            $response->assertDontSee('Export Excel');
        }
    }

    public function test_vcb_pages_hide_export_for_guest(): void
    {
        $guest = $this->guest();

        $this->actingAs($guest)->get(route('vcb.produksi'))->assertOk()->assertDontSee('Export');
        $this->actingAs($guest)->get(route('vcb.mesin'))->assertOk()->assertDontSee('Export');
        $this->actingAs($guest)->get(route('vcb.quality'))->assertOk()->assertDontSee('Export');
        $this->actingAs($guest)->get(route('vcb.dandori'))->assertOk()->assertDontSee('Export');
    }

    public function test_staff_still_sees_write_buttons(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($staff)->get(route('input.produksi.index'))
            ->assertOk()
            ->assertSee('Input Baru');
    }
}
```

- [ ] **Step 2: Jalankan test, pastikan GAGAL**

```bash
php artisan test tests/Feature/Layout/GuestUiTest.php
```

Expected: FAIL — badge belum ada, tombol tulis masih terlihat, link breakdown masih muncul di sidebar.

- [ ] **Step 3: Tambah badge mode tamu di topbar**

Di `resources/views/layouts/app.blade.php`, tepat setelah blok `user-block` (baris 896-902), sebelum form logout:

```blade
        @if($u->isGuest())
        <div class="topbar-item" style="border:1px solid #4b5563;padding:2px 8px;font-size:0.72rem;letter-spacing:0.04em;color:#e5e7eb">
            MODE TAMU — READ ONLY
        </div>
        @endif
```

- [ ] **Step 4: Buka menu sidebar untuk guest**

Di `resources/views/layouts/app.blade.php`, tambah `'guest'` ke lima whitelist berikut — **jangan** sentuh blok breakdown (baris 967), blok anomali (958), blok chatbot (921), dan blok Master Data (1086):

Baris 933 (pembungkus section Input Data):
```blade
    @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'manager', 'guest']))
```

Baris 936 (LHP Produksi + Dandori):
```blade
        @if(in_array($role, ['admin', 'staff_produksi', 'guest']))
```

Baris 950 (LHP Quality):
```blade
        @if(in_array($role, ['admin', 'staff_qc', 'guest']))
```

Baris 984 (pembungkus section Dashboard):
```blade
    @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc', 'staff_maintenance', 'manager', 'guest']))
```

Baris 1005 (grup Produksi):
```blade
        @if(in_array($role, ['admin', 'staff_produksi', 'manager', 'guest']))
```

Baris 1025 (grup Quality):
```blade
        @if(in_array($role, ['admin', 'staff_qc', 'manager', 'guest']))
```

Baris 1039 (grup MTC Mesin):
```blade
        @if(in_array($role, ['admin', 'staff_maintenance', 'manager', 'guest']))
```

Baris 1062 (grup MTC Dies):
```blade
        @if(in_array($role, ['admin', 'staff_maintenance', 'manager', 'guest']))
```

Blok breakdown (baris 967-980) tetap `['admin', 'staff_maintenance', 'manager']` — guest tidak boleh melihat daftar breakdown.

- [ ] **Step 5: Sembunyikan tombol tulis di 3 halaman LHP**

Pola yang sama untuk `input/produksi/index.blade.php`, `input/qc/index.blade.php`, dan `input/dandori/index.blade.php`:

1. Bungkus tombol "Input Baru", "Import Excel", dan "Export Excel" di dalam `<div class="action-btns">` dengan `@if(! auth()->user()->isGuest())` ... `@endif`. **Tombol "Filter" tetap di luar** — guest boleh memfilter.
2. Bungkus komponen `<x-export-modal ... />` dengan penjaga yang sama.
3. Bungkus seluruh panel import (`<div id="import-produksi-panel" ...>` / `import-qc-panel` / `import-dandori-panel`) dengan penjaga yang sama.
4. Bungkus drawer form input dengan penjaga yang sama. Drawer terdiri dari dua elemen berurutan — backdrop `<div x-show="drawerOpen" ...>` dan panel `<div x-show="drawerOpen" ...>` yang memuat seluruh form — dan berakhir tepat sebelum komentar `{{-- ══ KONTEN HALAMAN ... --}}`. Batasnya: `produksi/index.blade.php` baris 26 sampai 256, `qc/index.blade.php` baris 21 sampai 251, `dandori/index.blade.php` baris 21 sampai 322. Verifikasi batas bawahnya dengan mencari komentar `KONTEN HALAMAN` di tiap file sebelum menyisipkan `@endif` — nomor baris bergeser setelah edit sebelumnya di langkah ini.
5. Di kolom aksi tiap baris, cabang `@else` (non-admin) merender tombol Note yang memanggil route POST `*-note`. Bungkus cabang itu dengan `@if(! auth()->user()->isGuest())` juga — untuk `produksi/index.blade.php` blok itu ada di baris 792-797 (`<button type="button" ... openNoteDrawer ...>` yang diakhiri `@endif`).

Contoh konkret untuk `input/produksi/index.blade.php` baris 262-282:

```blade
    <div class="action-btns">
        @if(! auth()->user()->isGuest())
        <button class="btn btn-primary btn-sm" @click="drawerOpen = true">
            <i class="bi bi-plus-lg me-1"></i>Input Baru
        </button>
        @endif
        <button type="button" @click="filterOpen = !filterOpen"
                :class="filterOpen ? 'btn-secondary' : 'btn-outline-secondary'"
                class="btn btn-sm" style="position:relative">
            <i class="bi bi-funnel me-1"></i>Filter
            @if($filterActive)
            <span style="position:absolute;top:-5px;right:-5px;width:8px;height:8px;background:#ef4444;border-radius:50%;border:1.5px solid #fff"></span>
            @endif
        </button>
        @if(! auth()->user()->isGuest())
        <button class="btn btn-outline-success btn-sm"
                onclick="document.getElementById('import-produksi-panel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-arrow-up me-1"></i>Import Excel
        </button>
        <button class="btn btn-outline-secondary btn-sm"
                onclick="document.getElementById('exportProduksiPanel').classList.toggle('d-none')">
            <i class="bi bi-file-earmark-excel me-1"></i>Export Excel
        </button>
        @endif
    </div>
```

- [ ] **Step 6: Sembunyikan tombol export di 4 halaman VCB**

Bungkus tiap tombol export dengan penjaga yang sama:

`resources/views/vcb/produksi.blade.php:157` dan `resources/views/vcb/quality.blade.php:155` — bungkus elemen `<a id="oneExportBtn" ...>` beserta pembungkus kolomnya.

`resources/views/vcb/mesin.blade.php:36` — bungkus `<a id="exportExcel" ...>`.

`resources/views/vcb/dandori.blade.php:176` — bungkus `<a :href="exportUrl" ...>`.

Pola:

```blade
@if(! auth()->user()->isGuest())
<a id="oneExportBtn" href="#" class="btn btn-outline-secondary btn-sm">
    <i class="bi bi-file-earmark-excel me-1"></i>Export
</a>
@endif
```

Baris JavaScript yang menyetel `href` tombol (mis. `document.getElementById('oneExportBtn').href = ...` di `vcb/produksi.blade.php:649`, `vcb/quality.blade.php:610`, `vcb/mesin.blade.php:75`) akan error `Cannot set properties of null` kalau elemennya hilang. Ubah jadi null-safe:

```javascript
    const exportBtn = document.getElementById('oneExportBtn');
    if (exportBtn) exportBtn.href = `/export/produksi?${params}&format=excel`;
```

- [ ] **Step 7: Sembunyikan link checksheet PDF di history dies**

Di `resources/views/maintenance/control-stroke-dies-history.blade.php` baris 90-98, ganti isi sel:

```blade
                        <td style="white-space:nowrap">
                            @if($log->checksheet_path && ! auth()->user()->isGuest())
                                <a href="{{ route('maintenance.control-stroke-dies.preventive.checksheet', $log) }}" target="_blank" rel="noopener">
                                    <i class="bi bi-file-earmark-pdf"></i> Lihat PDF
                                </a>
                            @else
                                —
                            @endif
                        </td>
```

- [ ] **Step 8: Jalankan test, pastikan LULUS**

```bash
php artisan test tests/Feature/Layout/GuestUiTest.php
```

Expected: PASS, 7 test.

Kalau `test_vcb_pages_hide_export_for_guest` masih merah karena kata "Export" muncul di tempat lain (mis. judul panel atau teks JS), persempit assertion ke penanda unik elemen tombolnya — contoh `assertDontSee('id="oneExportBtn"', false)` — bukan melonggarkan proteksinya.

- [ ] **Step 9: Jalankan seluruh suite**

```bash
php artisan test
```

Expected: seluruh suite hijau.

- [ ] **Step 10: Verifikasi manual di browser**

Buat akun guest lewat Master > Users, login sebagai guest, lalu cek:
1. Sidebar hanya menampilkan Input Data (3 daftar LHP), Dashboard (VCB + MTC), tanpa Master Data / Breakdown / AI Assistant.
2. Badge "MODE TAMU — READ ONLY" muncul di topbar.
3. Halaman VCB Produksi, Quality, Mesin, Dandori terbuka dan grafiknya jalan (cek console browser bersih dari error null).
4. Buka `/master/users` langsung lewat URL → 403.
5. Logout jalan.

- [ ] **Step 11: Commit**

```bash
git add resources/views tests/Feature/Layout/GuestUiTest.php
git commit -m "feat(ui): sembunyikan aksi tulis & menu terlarang untuk role guest"
```

---

## Task 5: Dokumentasi & PR

**Files:**
- Modify: `CLAUDE.md` (tabel Roles + Active Work)

**Interfaces:**
- Consumes: seluruh hasil Task 1-4.
- Produces: PR siap review.

- [ ] **Step 1: Update tabel Roles di CLAUDE.md**

Tambah baris di tabel Roles:

```markdown
| `guest` | Read-only: VCB, analitik maintenance, daftar LHP. Tanpa export, master data, breakdown, chatbot |
```

- [ ] **Step 2: Update Active Work di CLAUDE.md**

Tambah baris di tabel Active Work:

```markdown
| **Akun Guest Read-Only** | ✅ DONE — role `guest`, route default-deny + middleware `DenyGuestWrite`. Akun dibuat admin di Master > Users. |
```

- [ ] **Step 3: Jalankan seluruh suite terakhir kali**

```bash
php artisan test
```

Expected: hijau semua. Catat jumlah test yang lulus untuk badan PR.

- [ ] **Step 4: Commit & push**

```bash
git add CLAUDE.md
git commit -m "docs: catat role guest read-only di CLAUDE.md"
git push -u origin feat/guest-readonly
```

- [ ] **Step 5: Buat PR**

```bash
gh pr create --base main --title "feat(auth): akun guest read-only untuk pihak eksternal" --body "$(cat <<'EOF'
## Ringkasan

Role baru `guest` — akun shared yang bisa diberikan ke pihak eksternal untuk melihat alur OPTIMA tanpa bisa mengubah data.

Guest boleh: VCB (Produksi/Mesin/Dandori/Quality), analitik maintenance (MTTR, MTBF, Availability, Control Stroke — mesin & dies), dan daftar LHP Produksi/QC/Dandori.

Guest tidak boleh: semua master data, semua aksi tulis, semua export Excel, daftar breakdown, checksheet PDF preventive, dan chatbot AI.

## Arsitektur

Default-deny dua lapis:
1. Route GET yang diizinkan disebut eksplisit di grup `role:...,guest`. Route baru otomatis tertutup untuk guest.
2. Middleware web global `DenyGuestWrite` menolak semua request non-GET dari guest, kecuali `logout`.

Efek yang disengaja: guest tidak bisa mengganti password akun shared — hanya admin, lewat Master > Users.

## Cara pakai

Admin bikin user baru di Master > Users dengan role "Guest (Read-Only)".

## Test

Menambah `GuestRoleTest`, `GuestWriteBlockTest`, `GuestUiTest`, dan matriks akses guest di `AuthorizationTest`.

Spec: `docs/superpowers/specs/2026-07-27-guest-readonly-account-design.md`

🤖 Generated with [Claude Code](https://claude.com/claude-code)
EOF
)"
```

---

## Catatan Pelaksanaan

**Migrasi produksi.** Deploy ke hosting berjalan otomatis lewat GitHub Actions saat merge ke `main`, termasuk `php artisan migrate`. Tidak ada langkah manual.

**Di luar scope, jangan diperbaiki di sini.** Tombol export di `vcb/produksi.blade.php`, `vcb/quality.blade.php`, dan `vcb/mesin.blade.php` menunjuk ke `/export/produksi` dan `/export/qc` — route itu tidak ada di `routes/web.php`, jadi tombolnya 404 untuk semua role. Task 4 hanya menyembunyikannya dari guest; perbaikan route-nya urusan PR lain.
