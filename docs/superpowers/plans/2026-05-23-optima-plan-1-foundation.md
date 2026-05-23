# OPTIMA Plan 1: Foundation + Master Data

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Install Laravel, create all 11 database tables, set up models, auth (Breeze + CheckRole middleware), base layout with role-aware sidebar, and full CRUD for all 6 master tables.

**Architecture:** Full Laravel MVC. Models use SoftDeletes + ActivityLog. CheckRole middleware guards route groups. Sidebar renders conditionally by role. All master CRUD uses resource controllers + FormRequest validation.

**Tech Stack:** Laravel 11, MySQL (XAMPP: root, no pw, DB: optima_db), Bootstrap 5, Laravel Breeze, spatie/laravel-activitylog 4

---

## Task 1: Install Laravel & Configure Environment

**Files:**
- Create: `/Applications/XAMPP/xamppfiles/htdocs/optima/` (full Laravel project)
- Modify: `.env`

- [ ] **Step 1: Backup docs folder**
```bash
cp -r /Applications/XAMPP/xamppfiles/htdocs/optima/docs /tmp/optima_docs_backup
```

- [ ] **Step 2: Install Laravel into the directory**
```bash
cd /Applications/XAMPP/xamppfiles/htdocs
rm -rf optima
composer create-project laravel/laravel optima
cd optima
```

- [ ] **Step 3: Restore docs and git history**
```bash
cp -r /tmp/optima_docs_backup /Applications/XAMPP/xamppfiles/htdocs/optima/docs
cd /Applications/XAMPP/xamppfiles/htdocs/optima
git init
git add docs/
git commit -m "docs: restore OPTIMA design spec after Laravel install"
```

- [ ] **Step 4: Install required packages**
```bash
composer require laravel/breeze --dev
composer require spatie/laravel-activitylog
composer require maatwebsite/excel
composer require barryvdh/laravel-dompdf
```

- [ ] **Step 5: Install Breeze (Blade stack)**
```bash
php artisan breeze:install blade
```

- [ ] **Step 6: Configure .env**

Edit `.env`:
```env
APP_NAME=OPTIMA
APP_URL=http://localhost/optima/public

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=optima_db
DB_USERNAME=root
DB_PASSWORD=
```

- [ ] **Step 7: Create database**
```bash
/Applications/XAMPP/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS optima_db CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

- [ ] **Step 8: Install Spatie ActivityLog**
```bash
php artisan vendor:publish --provider="Spatie\Activitylog\ActivitylogServiceProvider" --tag="activitylog-migrations"
```

- [ ] **Step 9: Verify setup**
```bash
php artisan --version
```
Expected output contains: `Laravel Framework 11.x.x`

- [ ] **Step 10: Commit**
```bash
git add .
git commit -m "chore: install Laravel 11 with Breeze, activitylog, excel, dompdf"
```

---

## Task 2: Database Migrations

**Files:**
- Create: `database/migrations/2026_05_23_000001_create_master_shift_table.php`
- Create: `database/migrations/2026_05_23_000002_create_master_karyawan_table.php`
- Create: `database/migrations/2026_05_23_000003_create_master_mesin_table.php`
- Create: `database/migrations/2026_05_23_000004_create_master_part_table.php`
- Create: `database/migrations/2026_05_23_000005_create_master_part_proses_table.php`
- Create: `database/migrations/2026_05_23_000006_create_master_losstime_table.php`
- Create: `database/migrations/2026_05_23_000007_add_role_to_users_table.php`
- Create: `database/migrations/2026_05_23_000008_create_transaksi_produksi_table.php`
- Create: `database/migrations/2026_05_23_000009_create_transaksi_qc_table.php`
- Create: `database/migrations/2026_05_23_000010_create_transaksi_dandori_table.php`
- Create: `database/migrations/2026_05_23_000011_create_transaksi_dandori_rincian_table.php`

- [ ] **Step 1: Create master_shift migration**
```bash
php artisan make:migration create_master_shift_table
```
Edit the generated file:
```php
public function up(): void
{
    Schema::create('master_shift', function (Blueprint $table) {
        $table->id();
        $table->string('nama_shift');
        $table->time('jam_start');
        $table->time('jam_finish');
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('master_shift'); }
```

- [ ] **Step 2: Create master_karyawan migration**
```bash
php artisan make:migration create_master_karyawan_table
```
```php
public function up(): void
{
    Schema::create('master_karyawan', function (Blueprint $table) {
        $table->id();
        $table->string('nik')->unique();
        $table->string('nama_karyawan');
        $table->enum('departemen', ['Produksi', 'QC']);
        $table->softDeletes();
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('master_karyawan'); }
```

- [ ] **Step 3: Create master_mesin migration**
```bash
php artisan make:migration create_master_mesin_table
```
```php
public function up(): void
{
    Schema::create('master_mesin', function (Blueprint $table) {
        $table->id();
        $table->string('id_mesin')->unique();
        $table->string('nama_mesin');
        $table->string('line');
        $table->enum('tipe_mesin', ['PROGRESSIVE', 'STAMPING']);
        $table->integer('kapasitas_max_stroke')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('master_mesin'); }
```

- [ ] **Step 4: Create master_part migration**
```bash
php artisan make:migration create_master_part_table
```
```php
public function up(): void
{
    Schema::create('master_part', function (Blueprint $table) {
        $table->id();
        $table->string('part_no')->unique();
        $table->string('part_name');
        $table->softDeletes();
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('master_part'); }
```

- [ ] **Step 5: Create master_part_proses migration**
```bash
php artisan make:migration create_master_part_proses_table
```
```php
public function up(): void
{
    Schema::create('master_part_proses', function (Blueprint $table) {
        $table->id();
        $table->foreignId('id_part')->constrained('master_part')->cascadeOnDelete();
        $table->string('id_proses');               // "1/2", "2/2", "1/4"
        $table->string('nama_proses');             // PROGRESSIVE, BENDING, DRAW
        $table->decimal('sph_standar_produksi', 8, 2)->default(0);
        $table->decimal('cycle_time_standar_produksi', 8, 2)->default(0);
        $table->decimal('sph_standar_qc', 8, 2)->default(0);
        $table->decimal('cycle_time_standar_qc', 8, 2)->default(0);
        $table->integer('std_dandori_menit')->default(15);
        $table->softDeletes();
        $table->timestamps();
        $table->unique(['id_part', 'id_proses']);
    });
}
public function down(): void { Schema::dropIfExists('master_part_proses'); }
```

- [ ] **Step 6: Create master_losstime migration**
```bash
php artisan make:migration create_master_losstime_table
```
```php
public function up(): void
{
    Schema::create('master_losstime', function (Blueprint $table) {
        $table->id();
        $table->string('kode')->unique();
        $table->string('jenis_losstime');
        $table->integer('std_menit_default')->nullable();
        $table->integer('std_menit_progressive')->nullable();
        $table->softDeletes();
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('master_losstime'); }
```

- [ ] **Step 7: Add role + id_karyawan to users**
```bash
php artisan make:migration add_role_to_users_table
```
```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->enum('role', ['admin', 'staff_produksi', 'staff_qc', 'manager'])
              ->default('staff_produksi')->after('email');
        $table->foreignId('id_karyawan')->nullable()->after('role')
              ->constrained('master_karyawan')->nullOnDelete();
    });
}
public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropForeign(['id_karyawan']);
        $table->dropColumn(['role', 'id_karyawan']);
    });
}
```

- [ ] **Step 8: Create transaksi_produksi migration**
```bash
php artisan make:migration create_transaksi_produksi_table
```
```php
public function up(): void
{
    Schema::create('transaksi_produksi', function (Blueprint $table) {
        $table->id();
        $table->date('tanggal_produksi');
        $table->foreignId('id_shift')->constrained('master_shift');
        $table->foreignId('id_karyawan')->constrained('master_karyawan');
        $table->foreignId('id_mesin')->constrained('master_mesin');
        $table->foreignId('id_part_proses')->constrained('master_part_proses');
        $table->time('jam_start');
        $table->time('jam_finish');
        $table->integer('qty_aktual');
        $table->decimal('actual_cycle_time', 8, 2)->default(0);
        $table->foreignId('created_by')->constrained('users');
        $table->foreignId('updated_by')->nullable()->constrained('users');
        $table->softDeletes();
        $table->timestamps();
        $table->index(['tanggal_produksi', 'id_karyawan']);
        $table->index(['tanggal_produksi', 'id_mesin']);
    });
}
public function down(): void { Schema::dropIfExists('transaksi_produksi'); }
```

- [ ] **Step 9: Create transaksi_qc migration**
```bash
php artisan make:migration create_transaksi_qc_table
```
```php
public function up(): void
{
    Schema::create('transaksi_qc', function (Blueprint $table) {
        $table->id();
        $table->date('tgl_pengecekan');
        $table->date('tgl_produksi_part');
        $table->foreignId('id_shift')->constrained('master_shift');
        $table->foreignId('id_karyawan_qc')->constrained('master_karyawan');
        $table->foreignId('id_part_proses')->constrained('master_part_proses');
        $table->time('jam_start');
        $table->time('jam_finish');
        $table->integer('qty_ok');
        $table->integer('qty_ng')->default(0);
        $table->decimal('actual_cycle_time', 8, 2)->default(0);
        $table->foreignId('created_by')->constrained('users');
        $table->foreignId('updated_by')->nullable()->constrained('users');
        $table->softDeletes();
        $table->timestamps();
        $table->index(['tgl_pengecekan', 'id_karyawan_qc']);
    });
}
public function down(): void { Schema::dropIfExists('transaksi_qc'); }
```

- [ ] **Step 10: Create transaksi_dandori migration**
```bash
php artisan make:migration create_transaksi_dandori_table
```
```php
public function up(): void
{
    Schema::create('transaksi_dandori', function (Blueprint $table) {
        $table->id();
        $table->date('tanggal');
        $table->foreignId('id_shift')->constrained('master_shift');
        $table->foreignId('id_karyawan')->constrained('master_karyawan');
        $table->foreignId('id_mesin')->constrained('master_mesin');
        $table->foreignId('id_part_proses')->constrained('master_part_proses');
        $table->integer('total_aktual_menit')->default(0);
        $table->integer('standard_time_menit')->default(0);
        $table->enum('status', ['OK', 'OVER'])->default('OK');
        $table->foreignId('created_by')->constrained('users');
        $table->foreignId('updated_by')->nullable()->constrained('users');
        $table->softDeletes();
        $table->timestamps();
        $table->index(['tanggal', 'id_mesin']);
    });
}
public function down(): void { Schema::dropIfExists('transaksi_dandori'); }
```

- [ ] **Step 11: Create transaksi_dandori_rincian migration**
```bash
php artisan make:migration create_transaksi_dandori_rincian_table
```
```php
public function up(): void
{
    Schema::create('transaksi_dandori_rincian', function (Blueprint $table) {
        $table->id();
        $table->foreignId('id_transaksi_dandori')
              ->constrained('transaksi_dandori')->cascadeOnDelete();
        $table->foreignId('id_losstime')->constrained('master_losstime');
        $table->integer('menit_loss');
        $table->integer('menit_over')->default(0);
        $table->timestamps();
    });
}
public function down(): void { Schema::dropIfExists('transaksi_dandori_rincian'); }
```

- [ ] **Step 12: Run all migrations**
```bash
php artisan migrate
```
Expected: 11 new tables created + Breeze tables + activitylog table.

- [ ] **Step 13: Commit**
```bash
git add database/migrations/
git commit -m "feat: add all 11 OPTIMA migrations"
```

---

## Task 3: Eloquent Models

**Files:**
- Create: `app/Models/MasterShift.php`
- Create: `app/Models/MasterKaryawan.php`
- Create: `app/Models/MasterMesin.php`
- Create: `app/Models/MasterPart.php`
- Create: `app/Models/MasterPartProses.php`
- Create: `app/Models/MasterLosstime.php`
- Create: `app/Models/TransaksiProduksi.php`
- Create: `app/Models/TransaksiQc.php`
- Create: `app/Models/TransaksiDandori.php`
- Create: `app/Models/TransaksiDandoriRincian.php`
- Modify: `app/Models/User.php`

- [ ] **Step 1: Create MasterShift, MasterKaryawan, MasterMesin**

`app/Models/MasterShift.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterShift extends Model
{
    protected $table = 'master_shift';
    protected $fillable = ['nama_shift', 'jam_start', 'jam_finish'];

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_shift');
    }
    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_shift');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandori::class, 'id_shift');
    }
}
```

`app/Models/MasterKaryawan.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterKaryawan extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_karyawan';
    protected $fillable = ['nik', 'nama_karyawan', 'departemen'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_karyawan');
    }

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_karyawan');
    }
    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_karyawan_qc');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandori::class, 'id_karyawan');
    }
    public function user(): HasMany
    {
        return $this->hasMany(User::class, 'id_karyawan');
    }
}
```

`app/Models/MasterMesin.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterMesin extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_mesin';
    protected $fillable = ['id_mesin', 'nama_mesin', 'line', 'tipe_mesin', 'kapasitas_max_stroke'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_mesin');
    }

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_mesin');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandori::class, 'id_mesin');
    }
}
```

- [ ] **Step 2: Create MasterPart + MasterPartProses**

`app/Models/MasterPart.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterPart extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_part';
    protected $fillable = ['part_no', 'part_name'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_part');
    }

    public function proses(): HasMany
    {
        return $this->hasMany(MasterPartProses::class, 'id_part');
    }
}
```

`app/Models/MasterPartProses.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterPartProses extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_part_proses';
    protected $fillable = [
        'id_part', 'id_proses', 'nama_proses',
        'sph_standar_produksi', 'cycle_time_standar_produksi',
        'sph_standar_qc', 'cycle_time_standar_qc',
        'std_dandori_menit',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_part_proses');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MasterPart::class, 'id_part');
    }
    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_part_proses');
    }
    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_part_proses');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandori::class, 'id_part_proses');
    }
}
```

- [ ] **Step 3: Create MasterLosstime**

`app/Models/MasterLosstime.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterLosstime extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_losstime';
    protected $fillable = ['kode', 'jenis_losstime', 'std_menit_default', 'std_menit_progressive'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_losstime');
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_losstime');
    }
}
```

- [ ] **Step 4: Create TransaksiProduksi + TransaksiQc**

`app/Models/TransaksiProduksi.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class TransaksiProduksi extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'transaksi_produksi';
    protected $fillable = [
        'tanggal_produksi', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses',
        'jam_start', 'jam_finish', 'qty_aktual', 'actual_cycle_time', 'created_by', 'updated_by',
    ];
    protected $casts = ['tanggal_produksi' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('transaksi_produksi');
    }

    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawan(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan'); }
    public function mesin(): BelongsTo { return $this->belongsTo(MasterMesin::class, 'id_mesin'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
```

`app/Models/TransaksiQc.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class TransaksiQc extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'transaksi_qc';
    protected $fillable = [
        'tgl_pengecekan', 'tgl_produksi_part', 'id_shift', 'id_karyawan_qc', 'id_part_proses',
        'jam_start', 'jam_finish', 'qty_ok', 'qty_ng', 'actual_cycle_time', 'created_by', 'updated_by',
    ];
    protected $casts = ['tgl_pengecekan' => 'date', 'tgl_produksi_part' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('transaksi_qc');
    }

    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawanQc(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan_qc'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
}
```

- [ ] **Step 5: Create TransaksiDandori + TransaksiDandoriRincian**

`app/Models/TransaksiDandori.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class TransaksiDandori extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'transaksi_dandori';
    protected $fillable = [
        'tanggal', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses',
        'total_aktual_menit', 'standard_time_menit', 'status', 'created_by', 'updated_by',
    ];
    protected $casts = ['tanggal' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('transaksi_dandori');
    }

    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawan(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan'); }
    public function mesin(): BelongsTo { return $this->belongsTo(MasterMesin::class, 'id_mesin'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function rincian(): HasMany { return $this->hasMany(TransaksiDandoriRincian::class, 'id_transaksi_dandori'); }
}
```

`app/Models/TransaksiDandoriRincian.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiDandoriRincian extends Model
{
    protected $table = 'transaksi_dandori_rincian';
    protected $fillable = ['id_transaksi_dandori', 'id_losstime', 'menit_loss', 'menit_over'];

    public function dandori(): BelongsTo { return $this->belongsTo(TransaksiDandori::class, 'id_transaksi_dandori'); }
    public function losstime(): BelongsTo { return $this->belongsTo(MasterLosstime::class, 'id_losstime'); }
}
```

- [ ] **Step 6: Update User model**

Edit `app/Models/User.php` — add to the class:
```php
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Add 'role' and 'id_karyawan' to $fillable:
protected $fillable = ['name', 'email', 'password', 'role', 'id_karyawan'];

public function karyawan(): BelongsTo
{
    return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
}

public function hasRole(string|array $roles): bool
{
    return in_array($this->role, (array) $roles);
}
```

- [ ] **Step 7: Commit**
```bash
git add app/Models/
git commit -m "feat: add all Eloquent models with relationships and activitylog"
```

---

## Task 4: Auth, Middleware & Routes

**Files:**
- Create: `app/Http/Middleware/CheckRole.php`
- Modify: `bootstrap/app.php`
- Modify: `routes/web.php`

- [ ] **Step 1: Write test for CheckRole middleware**

Create `tests/Feature/Auth/CheckRoleTest.php`:
```php
<?php
namespace Tests\Feature\Auth;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CheckRoleTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_master_data(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $this->actingAs($user)->get('/master/karyawan')->assertStatus(200);
    }

    public function test_staff_produksi_cannot_access_master_data(): void
    {
        $user = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($user)->get('/master/karyawan')->assertStatus(403);
    }

    public function test_manager_cannot_access_input_produksi(): void
    {
        $user = User::factory()->create(['role' => 'manager']);
        $this->actingAs($user)->get('/input/produksi')->assertStatus(403);
    }

    public function test_unauthenticated_redirects_to_login(): void
    {
        $this->get('/master/karyawan')->assertRedirect('/login');
    }
}
```

- [ ] **Step 2: Run test — expect FAIL (routes not defined yet)**
```bash
php artisan test tests/Feature/Auth/CheckRoleTest.php
```
Expected: FAIL — routes don't exist yet.

- [ ] **Step 3: Create CheckRole middleware**

`app/Http/Middleware/CheckRole.php`:
```php
<?php
namespace App\Http\Middleware;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        if (!$request->user() || !$request->user()->hasRole($roles)) {
            abort(403, 'Akses tidak diizinkan.');
        }
        return $next($request);
    }
}
```

- [ ] **Step 4: Register middleware in bootstrap/app.php**

Edit `bootstrap/app.php` — inside `withMiddleware(function (Middleware $middleware)`:
```php
$middleware->alias([
    'role' => \App\Http\Middleware\CheckRole::class,
]);
```

- [ ] **Step 5: Define routes in routes/web.php**

Replace the content after the auth routes with:
```php
<?php
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Master\KaryawanController;
use App\Http\Controllers\Master\MesinController;
use App\Http\Controllers\Master\PartController;
use App\Http\Controllers\Master\LosstimeController;
use App\Http\Controllers\Master\ShiftController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\Input\ProduksiController;
use App\Http\Controllers\Input\QcController;
use App\Http\Controllers\Input\DandoriController;
use App\Http\Controllers\Vcb\VcbProduksiController;
use App\Http\Controllers\Vcb\VcbMesinController;
use App\Http\Controllers\Vcb\VcbQualityController;
use App\Http\Controllers\Vcb\VcbDandoriController;
use App\Http\Controllers\ExportController;

Route::get('/', fn() => redirect('/dashboard'));

Route::middleware('auth')->group(function () {

    Route::get('/dashboard', fn() => redirect('/vcb/produksi'))->name('dashboard');

    // --- MASTER DATA (admin only) ---
    Route::middleware('role:admin')->prefix('master')->name('master.')->group(function () {
        Route::resource('karyawan', KaryawanController::class);
        Route::resource('mesin', MesinController::class);
        Route::resource('part', PartController::class);
        Route::resource('losstime', LosstimeController::class);
        Route::resource('shift', ShiftController::class);
        Route::resource('users', UserController::class);
        // Sub-resource: proses per part
        Route::post('part/{part}/proses', [PartController::class, 'storeProses'])->name('part.proses.store');
        Route::delete('part/{part}/proses/{proses}', [PartController::class, 'destroyProses'])->name('part.proses.destroy');
    });

    // --- INPUT PRODUKSI (admin, staff_produksi) ---
    Route::middleware('role:admin,staff_produksi')->prefix('input')->name('input.')->group(function () {
        Route::resource('produksi', ProduksiController::class)->only(['index', 'create', 'store', 'destroy']);
        Route::resource('dandori', DandoriController::class)->only(['index', 'create', 'store', 'destroy']);
        // AJAX: get standard_time by id_part_proses
        Route::get('dandori/standard-time/{partProses}', [DandoriController::class, 'getStandardTime'])->name('dandori.standard-time');
        Route::get('dandori/part-proses/{part}', [DandoriController::class, 'getPartProses'])->name('dandori.part-proses');
    });

    // --- INPUT QC (admin, staff_qc) ---
    Route::middleware('role:admin,staff_qc')->prefix('input')->name('input.')->group(function () {
        Route::resource('qc', QcController::class)->only(['index', 'create', 'store', 'destroy']);
    });

    // --- VCB PRODUKSI & MESIN (admin, staff_produksi, manager) ---
    Route::middleware('role:admin,staff_produksi,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('produksi', [VcbProduksiController::class, 'index'])->name('produksi');
        Route::get('produksi/data', [VcbProduksiController::class, 'data'])->name('produksi.data');
        Route::get('mesin', [VcbMesinController::class, 'index'])->name('mesin');
        Route::get('mesin/data', [VcbMesinController::class, 'data'])->name('mesin.data');
        Route::get('dandori', [VcbDandoriController::class, 'index'])->name('dandori');
        Route::get('dandori/data', [VcbDandoriController::class, 'data'])->name('dandori.data');
    });

    // --- VCB QUALITY (admin, staff_qc, manager) ---
    Route::middleware('role:admin,staff_qc,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('quality', [VcbQualityController::class, 'index'])->name('quality');
        Route::get('quality/data', [VcbQualityController::class, 'data'])->name('quality.data');
    });

    // --- EXPORT (role filter inside controller) ---
    Route::prefix('export')->name('export.')->group(function () {
        Route::get('produksi', [ExportController::class, 'produksi'])->name('produksi');
        Route::get('qc', [ExportController::class, 'qc'])->name('qc');
        Route::get('dandori', [ExportController::class, 'dandori'])->name('dandori');
    });
});

require __DIR__.'/auth.php';
```

- [ ] **Step 6: Create stub controllers so routes resolve**

```bash
php artisan make:controller Master/KaryawanController --resource
php artisan make:controller Master/MesinController --resource
php artisan make:controller Master/PartController --resource
php artisan make:controller Master/LosstimeController --resource
php artisan make:controller Master/ShiftController --resource
php artisan make:controller Master/UserController --resource
php artisan make:controller Input/ProduksiController --resource
php artisan make:controller Input/QcController --resource
php artisan make:controller Input/DandoriController --resource
php artisan make:controller Vcb/VcbProduksiController
php artisan make:controller Vcb/VcbMesinController
php artisan make:controller Vcb/VcbQualityController
php artisan make:controller Vcb/VcbDandoriController
php artisan make:controller ExportController
```

Add stub `index()` returning 200 to each controller:
```php
public function index() { return response('ok', 200); }
```

For VCB controllers add both `index()` and `data()` stubs.
For DandoriController add `getStandardTime()` and `getPartProses()` stubs returning `response()->json([])`.

- [ ] **Step 7: Run tests**
```bash
php artisan test tests/Feature/Auth/CheckRoleTest.php
```
Expected: 4 PASS

- [ ] **Step 8: Commit**
```bash
git add app/Http/Middleware/ bootstrap/app.php routes/ app/Http/Controllers/
git commit -m "feat: add CheckRole middleware and all route groups"
```

---

## Task 5: Seeders & Base Layout

**Files:**
- Create: `database/seeders/MasterShiftSeeder.php`
- Create: `database/seeders/MasterLosstimeSeeder.php`
- Create: `database/seeders/AdminUserSeeder.php`
- Modify: `database/seeders/DatabaseSeeder.php`
- Create: `resources/views/layouts/app.blade.php`
- Create: `resources/views/layouts/auth.blade.php`

- [ ] **Step 1: Create MasterShiftSeeder**

`database/seeders/MasterShiftSeeder.php`:
```php
<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\MasterShift;

class MasterShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00'],
            ['nama_shift' => 'Shift 2', 'jam_start' => '14:00:00', 'jam_finish' => '22:00:00'],
            ['nama_shift' => 'Shift 3', 'jam_start' => '22:00:00', 'jam_finish' => '06:00:00'],
        ];
        foreach ($shifts as $shift) {
            MasterShift::firstOrCreate(['nama_shift' => $shift['nama_shift']], $shift);
        }
    }
}
```

- [ ] **Step 2: Create MasterLosstimeSeeder**

`database/seeders/MasterLosstimeSeeder.php`:
```php
<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\MasterLosstime;

class MasterLosstimeSeeder extends Seeder
{
    public function run(): void
    {
        $losstimes = [
            ['kode' => '1', 'jenis_losstime' => 'Mesin_Rusak',      'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '2', 'jenis_losstime' => 'Tunggu_Crane',     'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '3', 'jenis_losstime' => 'Tunggu_Material',  'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '4', 'jenis_losstime' => 'Tunggu_Dies',      'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '5', 'jenis_losstime' => 'MP_Tidak_Ada',     'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '7', 'jenis_losstime' => 'Trial_QC',         'std_menit_default' => 5,    'std_menit_progressive' => 5],
            ['kode' => '9', 'jenis_losstime' => 'Setting_Dies',     'std_menit_default' => 10,   'std_menit_progressive' => 15],
        ];
        foreach ($losstimes as $lt) {
            MasterLosstime::firstOrCreate(['kode' => $lt['kode']], $lt);
        }
    }
}
```

- [ ] **Step 3: Create AdminUserSeeder**

`database/seeders/AdminUserSeeder.php`:
```php
<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::firstOrCreate(
            ['email' => 'admin@optima.local'],
            [
                'name'     => 'Administrator',
                'password' => Hash::make('optima@2026'),
                'role'     => 'admin',
            ]
        );
    }
}
```

- [ ] **Step 4: Update DatabaseSeeder**

`database/seeders/DatabaseSeeder.php`:
```php
<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            MasterShiftSeeder::class,
            MasterLosstimeSeeder::class,
            AdminUserSeeder::class,
        ]);
    }
}
```

- [ ] **Step 5: Run seeders**
```bash
php artisan db:seed
```
Expected: 3 shifts, 7 losstimes, 1 admin user created.

- [ ] **Step 6: Create base layout app.blade.php**

`resources/views/layouts/app.blade.php`:
```html
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>OPTIMA - @yield('title', 'Dashboard')</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; }
        .sidebar { width: 240px; min-height: 100vh; background: #1a1a2e; position: fixed; top: 0; left: 0; z-index: 100; padding-top: 60px; }
        .sidebar .nav-link { color: #ccc; padding: 8px 20px; font-size: 0.875rem; }
        .sidebar .nav-link:hover, .sidebar .nav-link.active { color: #fff; background: rgba(255,255,255,0.1); border-radius: 4px; }
        .sidebar .nav-header { color: #888; font-size: 0.7rem; text-transform: uppercase; padding: 12px 20px 4px; letter-spacing: 1px; }
        .main-content { margin-left: 240px; padding-top: 60px; }
        .topbar { position: fixed; top: 0; left: 0; right: 0; height: 60px; background: #fff; border-bottom: 1px solid #dee2e6; z-index: 200; display: flex; align-items: center; padding: 0 20px; gap: 12px; }
        .topbar .logo { font-weight: 800; font-size: 1.1rem; color: #1a1a2e; margin-left: 240px; }
    </style>
    @stack('styles')
</head>
<body>
    {{-- TOPBAR --}}
    <div class="topbar">
        <span class="logo">OPTIMA</span>
        <div class="ms-auto d-flex align-items-center gap-3">
            <span class="badge bg-secondary">{{ strtoupper(str_replace('_', ' ', auth()->user()->role)) }}</span>
            <span class="text-muted small">{{ auth()->user()->name }}</span>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="btn btn-sm btn-outline-secondary">Logout</button>
            </form>
        </div>
    </div>

    {{-- SIDEBAR --}}
    <div class="sidebar">
        @php $role = auth()->user()->role; @endphp

        @if(in_array($role, ['admin', 'staff_produksi', 'staff_qc']))
        <div class="nav-header">Input Data</div>
        @if(in_array($role, ['admin', 'staff_produksi']))
        <a href="{{ route('input.produksi.index') }}" class="nav-link {{ request()->is('input/produksi*') ? 'active' : '' }}">
            <i class="bi bi-clipboard-plus me-2"></i>LHP Produksi
        </a>
        <a href="{{ route('input.dandori.index') }}" class="nav-link {{ request()->is('input/dandori*') ? 'active' : '' }}">
            <i class="bi bi-tools me-2"></i>Dandori
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_qc']))
        <a href="{{ route('input.qc.index') }}" class="nav-link {{ request()->is('input/qc*') ? 'active' : '' }}">
            <i class="bi bi-check2-square me-2"></i>LHP Quality
        </a>
        @endif
        @endif

        <div class="nav-header">VCB Dashboard</div>
        @if(in_array($role, ['admin', 'staff_produksi', 'manager']))
        <a href="{{ route('vcb.produksi') }}" class="nav-link {{ request()->is('vcb/produksi*') ? 'active' : '' }}">
            <i class="bi bi-bar-chart me-2"></i>VCB Produksi
        </a>
        <a href="{{ route('vcb.mesin') }}" class="nav-link {{ request()->is('vcb/mesin*') ? 'active' : '' }}">
            <i class="bi bi-gear me-2"></i>VCB Mesin
        </a>
        <a href="{{ route('vcb.dandori') }}" class="nav-link {{ request()->is('vcb/dandori*') ? 'active' : '' }}">
            <i class="bi bi-clock-history me-2"></i>VCB Dandori
        </a>
        @endif
        @if(in_array($role, ['admin', 'staff_qc', 'manager']))
        <a href="{{ route('vcb.quality') }}" class="nav-link {{ request()->is('vcb/quality*') ? 'active' : '' }}">
            <i class="bi bi-shield-check me-2"></i>VCB Quality
        </a>
        @endif

        @if($role === 'admin')
        <div class="nav-header">Master Data</div>
        <a href="{{ route('master.karyawan.index') }}" class="nav-link {{ request()->is('master/karyawan*') ? 'active' : '' }}">
            <i class="bi bi-people me-2"></i>Karyawan
        </a>
        <a href="{{ route('master.mesin.index') }}" class="nav-link {{ request()->is('master/mesin*') ? 'active' : '' }}">
            <i class="bi bi-cpu me-2"></i>Mesin
        </a>
        <a href="{{ route('master.part.index') }}" class="nav-link {{ request()->is('master/part*') ? 'active' : '' }}">
            <i class="bi bi-box-seam me-2"></i>Part / Dies
        </a>
        <a href="{{ route('master.losstime.index') }}" class="nav-link {{ request()->is('master/losstime*') ? 'active' : '' }}">
            <i class="bi bi-exclamation-triangle me-2"></i>Loss Time
        </a>
        <a href="{{ route('master.shift.index') }}" class="nav-link {{ request()->is('master/shift*') ? 'active' : '' }}">
            <i class="bi bi-calendar-week me-2"></i>Shift
        </a>
        <a href="{{ route('master.users.index') }}" class="nav-link {{ request()->is('master/users*') ? 'active' : '' }}">
            <i class="bi bi-person-gear me-2"></i>Users
        </a>
        @endif
    </div>

    {{-- MAIN CONTENT --}}
    <div class="main-content p-4">
        @if(session('success'))
            <div class="alert alert-success alert-dismissible fade show">
                {{ session('success') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @if(session('error'))
            <div class="alert alert-danger alert-dismissible fade show">
                {{ session('error') }} <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        @endif
        @yield('content')
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/alpinejs@3.14.1/dist/cdn.min.js" defer></script>
    @stack('scripts')
</body>
</html>
```

- [ ] **Step 7: Create auth layout**

`resources/views/layouts/auth.blade.php`:
```html
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>OPTIMA - Login</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: #1a1a2e; min-height: 100vh; display: flex; align-items: center; justify-content: center; }
        .login-card { width: 100%; max-width: 400px; }
        .brand { color: #fff; font-size: 2rem; font-weight: 800; text-align: center; margin-bottom: 8px; }
        .brand-sub { color: #888; font-size: 0.75rem; text-align: center; margin-bottom: 24px; }
    </style>
</head>
<body>
    <div class="login-card">
        <div class="brand">OPTIMA</div>
        <div class="brand-sub">OPERATOR & PRODUCTION TRACKING INFORMATION SYSTEM</div>
        <div class="card shadow">
            <div class="card-body p-4">
                @yield('content')
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
```

- [ ] **Step 8: Update Breeze login view to use auth layout**

Edit `resources/views/auth/login.blade.php` — replace `<x-guest-layout>` wrapper with:
```html
@extends('layouts.auth')
@section('content')
<h5 class="mb-4">Login</h5>
<form method="POST" action="{{ route('login') }}">
    @csrf
    <div class="mb-3">
        <label class="form-label">Email</label>
        <input type="email" name="email" class="form-control @error('email') is-invalid @enderror"
               value="{{ old('email') }}" required autofocus>
        @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3">
        <label class="form-label">Password</label>
        <input type="password" name="password" class="form-control @error('password') is-invalid @enderror" required>
        @error('password')<div class="invalid-feedback">{{ $message }}</div>@enderror
    </div>
    <div class="mb-3 form-check">
        <input type="checkbox" name="remember" class="form-check-input" id="remember">
        <label class="form-check-label" for="remember">Ingat saya</label>
    </div>
    <button type="submit" class="btn btn-primary w-100">Login</button>
</form>
@endsection
```

- [ ] **Step 9: Verify login works in browser**

Open: `http://localhost/optima/public/login`
Login with: `admin@optima.local` / `optima@2026`
Expected: Redirect to `/vcb/produksi`, sidebar shows all menus for admin.

- [ ] **Step 10: Commit**
```bash
git add database/seeders/ resources/views/layouts/
git commit -m "feat: add seeders, base layout with role-aware sidebar"
```

---

## Task 6: Master Karyawan CRUD

**Files:**
- Modify: `app/Http/Controllers/Master/KaryawanController.php`
- Create: `app/Http/Requests/StoreMasterKaryawanRequest.php`
- Create: `resources/views/master/karyawan/index.blade.php`
- Create: `resources/views/master/karyawan/create.blade.php`
- Create: `resources/views/master/karyawan/edit.blade.php`
- Test: `tests/Feature/Master/KaryawanTest.php`

- [ ] **Step 1: Write failing tests**

`tests/Feature/Master/KaryawanTest.php`:
```php
<?php
namespace Tests\Feature\Master;
use Tests\TestCase;
use App\Models\User;
use App\Models\MasterKaryawan;
use Illuminate\Foundation\Testing\RefreshDatabase;

class KaryawanTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => 'admin']);
    }

    public function test_admin_can_view_karyawan_list(): void
    {
        MasterKaryawan::create(['nik' => 'K001', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi']);
        $this->actingAs($this->admin)
             ->get('/master/karyawan')
             ->assertStatus(200)
             ->assertSee('Budi');
    }

    public function test_admin_can_create_karyawan(): void
    {
        $this->actingAs($this->admin)
             ->post('/master/karyawan', [
                 'nik'           => 'K002',
                 'nama_karyawan' => 'Siti',
                 'departemen'    => 'QC',
             ])
             ->assertRedirect('/master/karyawan');
        $this->assertDatabaseHas('master_karyawan', ['nik' => 'K002']);
    }

    public function test_admin_can_soft_delete_karyawan(): void
    {
        $k = MasterKaryawan::create(['nik' => 'K003', 'nama_karyawan' => 'Andi', 'departemen' => 'Produksi']);
        $this->actingAs($this->admin)
             ->delete("/master/karyawan/{$k->id}")
             ->assertRedirect('/master/karyawan');
        $this->assertSoftDeleted('master_karyawan', ['id' => $k->id]);
    }

    public function test_staff_produksi_cannot_access_karyawan(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $this->actingAs($staff)->get('/master/karyawan')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run — expect FAIL**
```bash
php artisan test tests/Feature/Master/KaryawanTest.php
```
Expected: FAIL — controller returns stub 200 but assertions fail.

- [ ] **Step 3: Create FormRequest**

`app/Http/Requests/StoreMasterKaryawanRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterKaryawanRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }

    public function rules(): array
    {
        $id = $this->route('karyawan');
        return [
            'nik'           => 'required|string|max:50|unique:master_karyawan,nik,' . ($id?->id ?? 'NULL'),
            'nama_karyawan' => 'required|string|max:100',
            'departemen'    => 'required|in:Produksi,QC',
        ];
    }
}
```

- [ ] **Step 4: Implement KaryawanController**

`app/Http/Controllers/Master/KaryawanController.php`:
```php
<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterKaryawanRequest;
use App\Models\MasterKaryawan;

class KaryawanController extends Controller
{
    public function index()
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->paginate(20);
        return view('master.karyawan.index', compact('karyawan'));
    }

    public function create()
    {
        return view('master.karyawan.create');
    }

    public function store(StoreMasterKaryawanRequest $request)
    {
        MasterKaryawan::create($request->validated());
        return redirect()->route('master.karyawan.index')->with('success', 'Karyawan berhasil ditambahkan.');
    }

    public function edit(MasterKaryawan $karyawan)
    {
        return view('master.karyawan.edit', compact('karyawan'));
    }

    public function update(StoreMasterKaryawanRequest $request, MasterKaryawan $karyawan)
    {
        $karyawan->update($request->validated());
        return redirect()->route('master.karyawan.index')->with('success', 'Karyawan berhasil diupdate.');
    }

    public function destroy(MasterKaryawan $karyawan)
    {
        $karyawan->delete();
        return redirect()->route('master.karyawan.index')->with('success', 'Karyawan berhasil dihapus.');
    }
}
```

- [ ] **Step 5: Create index view**

`resources/views/master/karyawan/index.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Master Karyawan')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-people me-2"></i>Master Karyawan</h4>
    <a href="{{ route('master.karyawan.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Karyawan
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr><th>NIK</th><th>Nama</th><th>Departemen</th><th width="120">Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($karyawan as $k)
                <tr>
                    <td>{{ $k->nik }}</td>
                    <td>{{ $k->nama_karyawan }}</td>
                    <td><span class="badge {{ $k->departemen === 'QC' ? 'bg-info' : 'bg-primary' }}">{{ $k->departemen }}</span></td>
                    <td>
                        <a href="{{ route('master.karyawan.edit', $k) }}" class="btn btn-sm btn-outline-warning">Edit</a>
                        <form method="POST" action="{{ route('master.karyawan.destroy', $k) }}" class="d-inline"
                              onsubmit="return confirm('Hapus karyawan ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $karyawan->links() }}</div>
@endsection
```

- [ ] **Step 6: Create create view**

`resources/views/master/karyawan/create.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Tambah Karyawan')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.karyawan.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah Karyawan</h4>
</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.karyawan.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">NIK <span class="text-danger">*</span></label>
                <input type="text" name="nik" class="form-control @error('nik') is-invalid @enderror"
                       value="{{ old('nik') }}">
                @error('nik')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Karyawan <span class="text-danger">*</span></label>
                <input type="text" name="nama_karyawan" class="form-control @error('nama_karyawan') is-invalid @enderror"
                       value="{{ old('nama_karyawan') }}">
                @error('nama_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Departemen <span class="text-danger">*</span></label>
                <select name="departemen" class="form-select @error('departemen') is-invalid @enderror">
                    <option value="">-- Pilih --</option>
                    <option value="Produksi" {{ old('departemen') === 'Produksi' ? 'selected' : '' }}>Produksi</option>
                    <option value="QC" {{ old('departemen') === 'QC' ? 'selected' : '' }}>QC</option>
                </select>
                @error('departemen')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan</button>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 7: Create edit view**

`resources/views/master/karyawan/edit.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Edit Karyawan')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.karyawan.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Edit Karyawan</h4>
</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.karyawan.update', $karyawan) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">NIK <span class="text-danger">*</span></label>
                <input type="text" name="nik" class="form-control @error('nik') is-invalid @enderror"
                       value="{{ old('nik', $karyawan->nik) }}">
                @error('nik')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Karyawan <span class="text-danger">*</span></label>
                <input type="text" name="nama_karyawan" class="form-control @error('nama_karyawan') is-invalid @enderror"
                       value="{{ old('nama_karyawan', $karyawan->nama_karyawan) }}">
                @error('nama_karyawan')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Departemen <span class="text-danger">*</span></label>
                <select name="departemen" class="form-select @error('departemen') is-invalid @enderror">
                    <option value="Produksi" {{ old('departemen', $karyawan->departemen) === 'Produksi' ? 'selected' : '' }}>Produksi</option>
                    <option value="QC" {{ old('departemen', $karyawan->departemen) === 'QC' ? 'selected' : '' }}>QC</option>
                </select>
                @error('departemen')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Update</button>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 8: Run tests**
```bash
php artisan test tests/Feature/Master/KaryawanTest.php
```
Expected: 4 PASS

- [ ] **Step 9: Commit**
```bash
git add app/Http/Controllers/Master/KaryawanController.php \
        app/Http/Requests/StoreMasterKaryawanRequest.php \
        resources/views/master/karyawan/ \
        tests/Feature/Master/KaryawanTest.php
git commit -m "feat: master karyawan CRUD with soft delete and activitylog"
```

---

## Task 7: Master Mesin, Losstime & Shift CRUD

**Files:**
- Modify: `app/Http/Controllers/Master/MesinController.php`
- Modify: `app/Http/Controllers/Master/LosstimeController.php`
- Modify: `app/Http/Controllers/Master/ShiftController.php`
- Create: corresponding Request, views for each

These follow the exact same pattern as Task 6. Implement each with:

- [ ] **Step 1: Implement MesinController**

`app/Http/Requests/StoreMasterMesinRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterMesinRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('mesin');
        return [
            'id_mesin'            => 'required|string|max:20|unique:master_mesin,id_mesin,' . ($id?->id ?? 'NULL'),
            'nama_mesin'          => 'required|string|max:100',
            'line'                => 'required|string|max:10',
            'tipe_mesin'          => 'required|in:PROGRESSIVE,STAMPING',
            'kapasitas_max_stroke' => 'nullable|integer|min:0',
        ];
    }
}
```

`app/Http/Controllers/Master/MesinController.php` — same pattern as KaryawanController:
```php
<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterMesinRequest;
use App\Models\MasterMesin;

class MesinController extends Controller
{
    public function index()
    {
        $mesin = MasterMesin::orderBy('line')->orderBy('id_mesin')->paginate(20);
        return view('master.mesin.index', compact('mesin'));
    }
    public function create() { return view('master.mesin.create'); }
    public function store(StoreMasterMesinRequest $request)
    {
        MasterMesin::create($request->validated());
        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil ditambahkan.');
    }
    public function edit(MasterMesin $mesin) { return view('master.mesin.edit', compact('mesin')); }
    public function update(StoreMasterMesinRequest $request, MasterMesin $mesin)
    {
        $mesin->update($request->validated());
        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil diupdate.');
    }
    public function destroy(MasterMesin $mesin)
    {
        $mesin->delete();
        return redirect()->route('master.mesin.index')->with('success', 'Mesin berhasil dihapus.');
    }
}
```

Create `resources/views/master/mesin/index.blade.php` — same structure as karyawan/index but with columns: ID Mesin | Nama Mesin | Line | Tipe.
Create `resources/views/master/mesin/create.blade.php` and `edit.blade.php` — same form structure with fields: id_mesin, nama_mesin, line, tipe_mesin (select: PROGRESSIVE/STAMPING), kapasitas_max_stroke (optional).

- [ ] **Step 2: Implement LosstimeController**

`app/Http/Requests/StoreMasterLosstimeRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterLosstimeRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('losstime');
        return [
            'kode'                  => 'required|string|max:10|unique:master_losstime,kode,' . ($id?->id ?? 'NULL'),
            'jenis_losstime'        => 'required|string|max:100',
            'std_menit_default'     => 'nullable|integer|min:0',
            'std_menit_progressive' => 'nullable|integer|min:0',
        ];
    }
}
```

`app/Http/Controllers/Master/LosstimeController.php`:
```php
<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterLosstimeRequest;
use App\Models\MasterLosstime;

class LosstimeController extends Controller
{
    public function index()
    {
        $losstimes = MasterLosstime::orderBy('kode')->paginate(20);
        return view('master.losstime.index', compact('losstimes'));
    }
    public function create() { return view('master.losstime.create'); }
    public function store(StoreMasterLosstimeRequest $request)
    {
        MasterLosstime::create($request->validated());
        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil ditambahkan.');
    }
    public function edit(MasterLosstime $losstime) { return view('master.losstime.edit', compact('losstime')); }
    public function update(StoreMasterLosstimeRequest $request, MasterLosstime $losstime)
    {
        $losstime->update($request->validated());
        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil diupdate.');
    }
    public function destroy(MasterLosstime $losstime)
    {
        $losstime->delete();
        return redirect()->route('master.losstime.index')->with('success', 'Loss time berhasil dihapus.');
    }
}
```

Views: `resources/views/master/losstime/{index,create,edit}.blade.php` — same pattern. Index columns: Kode | Jenis Loss | Std Menit Default | Std Menit Progressive. Form fields: kode, jenis_losstime, std_menit_default (nullable number), std_menit_progressive (nullable number). Add note in form: "Kosongkan jika loss ini dihitung penuh (full loss)."

- [ ] **Step 3: Implement ShiftController**

`app/Http/Requests/StoreMasterShiftRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterShiftRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        return [
            'nama_shift' => 'required|string|max:50',
            'jam_start'  => 'required|date_format:H:i',
            'jam_finish' => 'required|date_format:H:i',
        ];
    }
}
```

`app/Http/Controllers/Master/ShiftController.php` — same pattern as KaryawanController. `index()` returns all shifts ordered by `jam_start`. Views show: Nama Shift | Jam Start | Jam Finish.

- [ ] **Step 4: Run quick smoke test in browser**

Navigate to `http://localhost/optima/public/master/mesin` as admin.
Expected: Table page loads, "Tambah Mesin" button visible.

- [ ] **Step 5: Commit**
```bash
git add app/Http/Controllers/Master/MesinController.php \
        app/Http/Controllers/Master/LosstimeController.php \
        app/Http/Controllers/Master/ShiftController.php \
        app/Http/Requests/StoreMasterMesinRequest.php \
        app/Http/Requests/StoreMasterLosstimeRequest.php \
        app/Http/Requests/StoreMasterShiftRequest.php \
        resources/views/master/mesin/ \
        resources/views/master/losstime/ \
        resources/views/master/shift/
git commit -m "feat: master CRUD for mesin, losstime, shift"
```

---

## Task 8: Master Part & Proses CRUD

**Files:**
- Modify: `app/Http/Controllers/Master/PartController.php`
- Create: `app/Http/Requests/StoreMasterPartRequest.php`
- Create: `app/Http/Requests/StoreMasterPartProsesRequest.php`
- Create: `resources/views/master/part/index.blade.php`
- Create: `resources/views/master/part/create.blade.php`
- Create: `resources/views/master/part/edit.blade.php`

This is the most complex master CRUD: Part has a sub-table of Proses that are managed inline on the edit page.

- [ ] **Step 1: Create FormRequests**

`app/Http/Requests/StoreMasterPartRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterPartRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('part');
        return [
            'part_no'   => 'required|string|max:50|unique:master_part,part_no,' . ($id?->id ?? 'NULL'),
            'part_name' => 'required|string|max:200',
        ];
    }
}
```

`app/Http/Requests/StoreMasterPartProsesRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;

class StoreMasterPartProsesRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        return [
            'id_proses'                      => 'required|string|max:10',
            'nama_proses'                    => 'required|string|max:100',
            'sph_standar_produksi'           => 'required|numeric|min:0',
            'cycle_time_standar_produksi'    => 'required|numeric|min:0',
            'sph_standar_qc'                 => 'required|numeric|min:0',
            'cycle_time_standar_qc'          => 'required|numeric|min:0',
            'std_dandori_menit'              => 'required|integer|min:1',
        ];
    }
}
```

- [ ] **Step 2: Implement PartController**

`app/Http/Controllers/Master/PartController.php`:
```php
<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterPartRequest;
use App\Http\Requests\StoreMasterPartProsesRequest;
use App\Models\MasterPart;
use App\Models\MasterPartProses;

class PartController extends Controller
{
    public function index()
    {
        $parts = MasterPart::withCount('proses')->orderBy('part_name')->paginate(20);
        return view('master.part.index', compact('parts'));
    }

    public function create() { return view('master.part.create'); }

    public function store(StoreMasterPartRequest $request)
    {
        MasterPart::create($request->validated());
        return redirect()->route('master.part.index')->with('success', 'Part berhasil ditambahkan. Tambahkan proses di halaman edit.');
    }

    public function edit(MasterPart $part)
    {
        $part->load('proses');
        return view('master.part.edit', compact('part'));
    }

    public function update(StoreMasterPartRequest $request, MasterPart $part)
    {
        $part->update($request->validated());
        return redirect()->route('master.part.edit', $part)->with('success', 'Part berhasil diupdate.');
    }

    public function destroy(MasterPart $part)
    {
        $part->delete();
        return redirect()->route('master.part.index')->with('success', 'Part berhasil dihapus.');
    }

    // --- Sub-resource: Proses ---
    public function storeProses(StoreMasterPartProsesRequest $request, MasterPart $part)
    {
        $part->proses()->create($request->validated());
        return redirect()->route('master.part.edit', $part)->with('success', 'Proses berhasil ditambahkan.');
    }

    public function destroyProses(MasterPart $part, MasterPartProses $proses)
    {
        $proses->delete();
        return redirect()->route('master.part.edit', $part)->with('success', 'Proses berhasil dihapus.');
    }
}
```

- [ ] **Step 3: Create index view**

`resources/views/master/part/index.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Master Part / Dies')
@section('content')
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><i class="bi bi-box-seam me-2"></i>Master Part / Dies</h4>
    <a href="{{ route('master.part.create') }}" class="btn btn-primary btn-sm">
        <i class="bi bi-plus-lg me-1"></i>Tambah Part
    </a>
</div>
<div class="card shadow-sm">
    <div class="card-body p-0">
        <table class="table table-hover mb-0">
            <thead class="table-dark">
                <tr><th>Part No</th><th>Part Name</th><th>Jml Proses</th><th width="120">Aksi</th></tr>
            </thead>
            <tbody>
            @forelse($parts as $part)
                <tr>
                    <td><code>{{ $part->part_no }}</code></td>
                    <td>{{ $part->part_name }}</td>
                    <td><span class="badge bg-secondary">{{ $part->proses_count }} proses</span></td>
                    <td>
                        <a href="{{ route('master.part.edit', $part) }}" class="btn btn-sm btn-outline-warning">Edit & Proses</a>
                        <form method="POST" action="{{ route('master.part.destroy', $part) }}" class="d-inline"
                              onsubmit="return confirm('Hapus part ini beserta semua proses?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="4" class="text-center text-muted py-3">Belum ada data.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
<div class="mt-3">{{ $parts->links() }}</div>
@endsection
```

- [ ] **Step 4: Create create view**

`resources/views/master/part/create.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Tambah Part')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Tambah Part</h4>
</div>
<div class="card shadow-sm" style="max-width:500px">
    <div class="card-body">
        <form method="POST" action="{{ route('master.part.store') }}">
            @csrf
            <div class="mb-3">
                <label class="form-label">Part No <span class="text-danger">*</span></label>
                <input type="text" name="part_no" class="form-control @error('part_no') is-invalid @enderror"
                       value="{{ old('part_no') }}" placeholder="BT-091A-3">
                @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-4">
                <label class="form-label">Part Name <span class="text-danger">*</span></label>
                <input type="text" name="part_name" class="form-control @error('part_name') is-invalid @enderror"
                       value="{{ old('part_name') }}">
                @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-primary">Simpan & Lanjut ke Proses</button>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 5: Create edit view (Part info + Proses sub-table)**

`resources/views/master/part/edit.blade.php`:
```html
@extends('layouts.app')
@section('title', 'Edit Part')
@section('content')
<div class="d-flex align-items-center gap-3 mb-4">
    <a href="{{ route('master.part.index') }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-arrow-left"></i></a>
    <h4 class="mb-0">Edit Part — <span class="text-muted">{{ $part->part_no }}</span></h4>
</div>

{{-- Part Header --}}
<div class="card shadow-sm mb-4" style="max-width:500px">
    <div class="card-header fw-semibold">Info Part</div>
    <div class="card-body">
        <form method="POST" action="{{ route('master.part.update', $part) }}">
            @csrf @method('PUT')
            <div class="mb-3">
                <label class="form-label">Part No</label>
                <input type="text" name="part_no" class="form-control @error('part_no') is-invalid @enderror"
                       value="{{ old('part_no', $part->part_no) }}">
                @error('part_no')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <div class="mb-3">
                <label class="form-label">Part Name</label>
                <input type="text" name="part_name" class="form-control @error('part_name') is-invalid @enderror"
                       value="{{ old('part_name', $part->part_name) }}">
                @error('part_name')<div class="invalid-feedback">{{ $message }}</div>@enderror
            </div>
            <button type="submit" class="btn btn-sm btn-primary">Update Part</button>
        </form>
    </div>
</div>

{{-- Proses Sub-table --}}
<div class="card shadow-sm mb-4">
    <div class="card-header fw-semibold d-flex justify-content-between">
        <span>Daftar Proses</span>
        <span class="badge bg-secondary">{{ $part->proses->count() }} proses</span>
    </div>
    <div class="card-body p-0">
        <table class="table table-sm mb-0">
            <thead class="table-light">
                <tr>
                    <th>ID Proses</th><th>Nama Proses</th>
                    <th>SPH Prod</th><th>CT Prod</th>
                    <th>SPH QC</th><th>CT QC</th>
                    <th>Std Dandori</th><th width="80">Aksi</th>
                </tr>
            </thead>
            <tbody>
            @forelse($part->proses as $p)
                <tr>
                    <td>{{ $p->id_proses }}</td>
                    <td>{{ $p->nama_proses }}</td>
                    <td>{{ $p->sph_standar_produksi }}</td>
                    <td>{{ $p->cycle_time_standar_produksi }}s</td>
                    <td>{{ $p->sph_standar_qc }}</td>
                    <td>{{ $p->cycle_time_standar_qc }}s</td>
                    <td>{{ $p->std_dandori_menit }} mnt</td>
                    <td>
                        <form method="POST" action="{{ route('master.part.proses.destroy', [$part, $p]) }}"
                              onsubmit="return confirm('Hapus proses ini?')">
                            @csrf @method('DELETE')
                            <button class="btn btn-sm btn-outline-danger">Hapus</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-2">Belum ada proses.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

{{-- Add Proses Form --}}
<div class="card shadow-sm" style="max-width:700px">
    <div class="card-header fw-semibold">Tambah Proses</div>
    <div class="card-body">
        <form method="POST" action="{{ route('master.part.proses.store', $part) }}">
            @csrf
            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">ID Proses <span class="text-danger">*</span></label>
                    <input type="text" name="id_proses" class="form-control @error('id_proses') is-invalid @enderror"
                           placeholder="1/3" value="{{ old('id_proses') }}">
                    @error('id_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-4">
                    <label class="form-label">Nama Proses <span class="text-danger">*</span></label>
                    <input type="text" name="nama_proses" class="form-control @error('nama_proses') is-invalid @enderror"
                           placeholder="PROGRESSIVE" value="{{ old('nama_proses') }}">
                    @error('nama_proses')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-2">
                    <label class="form-label">Std Dandori (mnt)</label>
                    <input type="number" name="std_dandori_menit" class="form-control" value="{{ old('std_dandori_menit', 15) }}" min="1">
                </div>
            </div>
            <div class="row g-3 mt-1">
                <div class="col-md-3">
                    <label class="form-label">SPH Standar Produksi</label>
                    <input type="number" step="0.01" name="sph_standar_produksi" class="form-control" value="{{ old('sph_standar_produksi', 0) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cycle Time Prod (dtk)</label>
                    <input type="number" step="0.01" name="cycle_time_standar_produksi" class="form-control" value="{{ old('cycle_time_standar_produksi', 0) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">SPH Standar QC</label>
                    <input type="number" step="0.01" name="sph_standar_qc" class="form-control" value="{{ old('sph_standar_qc', 0) }}">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Cycle Time QC (dtk)</label>
                    <input type="number" step="0.01" name="cycle_time_standar_qc" class="form-control" value="{{ old('cycle_time_standar_qc', 0) }}">
                </div>
            </div>
            <button type="submit" class="btn btn-success mt-3">Tambah Proses</button>
        </form>
    </div>
</div>
@endsection
```

- [ ] **Step 6: Commit**
```bash
git add app/Http/Controllers/Master/PartController.php \
        app/Http/Requests/StoreMasterPartRequest.php \
        app/Http/Requests/StoreMasterPartProsesRequest.php \
        resources/views/master/part/
git commit -m "feat: master part + proses CRUD with inline sub-table"
```

---

## Task 9: Users Management CRUD

**Files:**
- Modify: `app/Http/Controllers/Master/UserController.php`
- Create: `app/Http/Requests/StoreMasterUserRequest.php`
- Create: `resources/views/master/users/{index,create,edit}.blade.php`

- [ ] **Step 1: Create FormRequest**

`app/Http/Requests/StoreMasterUserRequest.php`:
```php
<?php
namespace App\Http\Requests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class StoreMasterUserRequest extends FormRequest
{
    public function authorize(): bool { return $this->user()->hasRole('admin'); }
    public function rules(): array
    {
        $id = $this->route('user');
        return [
            'name'        => 'required|string|max:100',
            'email'       => 'required|email|unique:users,email,' . ($id?->id ?? 'NULL'),
            'password'    => $id ? ['nullable', Password::min(8)] : ['required', Password::min(8)],
            'role'        => 'required|in:admin,staff_produksi,staff_qc,manager',
            'id_karyawan' => 'nullable|exists:master_karyawan,id',
        ];
    }
}
```

- [ ] **Step 2: Implement UserController**

`app/Http/Controllers/Master/UserController.php`:
```php
<?php
namespace App\Http\Controllers\Master;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreMasterUserRequest;
use App\Models\User;
use App\Models\MasterKaryawan;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index()
    {
        $users = User::with('karyawan')->orderBy('name')->paginate(20);
        return view('master.users.index', compact('users'));
    }
    public function create()
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();
        return view('master.users.create', compact('karyawan'));
    }
    public function store(StoreMasterUserRequest $request)
    {
        User::create([
            'name'        => $request->name,
            'email'       => $request->email,
            'password'    => Hash::make($request->password),
            'role'        => $request->role,
            'id_karyawan' => $request->id_karyawan,
        ]);
        return redirect()->route('master.users.index')->with('success', 'User berhasil dibuat.');
    }
    public function edit(User $user)
    {
        $karyawan = MasterKaryawan::orderBy('nama_karyawan')->get();
        return view('master.users.edit', compact('user', 'karyawan'));
    }
    public function update(StoreMasterUserRequest $request, User $user)
    {
        $data = $request->only(['name', 'email', 'role', 'id_karyawan']);
        if ($request->filled('password')) {
            $data['password'] = Hash::make($request->password);
        }
        $user->update($data);
        return redirect()->route('master.users.index')->with('success', 'User berhasil diupdate.');
    }
    public function destroy(User $user)
    {
        if ($user->id === auth()->id()) {
            return redirect()->route('master.users.index')->with('error', 'Tidak bisa menghapus akun sendiri.');
        }
        $user->delete();
        return redirect()->route('master.users.index')->with('success', 'User berhasil dihapus.');
    }
}
```

- [ ] **Step 3: Create views**

`resources/views/master/users/index.blade.php` — table with columns: Nama | Email | Role (badge) | Karyawan | Aksi.

`resources/views/master/users/create.blade.php` — form: name, email, password, role (select), id_karyawan (select, nullable, from $karyawan collection).

`resources/views/master/users/edit.blade.php` — same as create but password field shows "Kosongkan jika tidak ingin mengubah password."

All three views follow the same layout pattern as master/karyawan views. Role badges: admin=danger, staff_produksi=primary, staff_qc=info, manager=success.

- [ ] **Step 4: Run all master tests**
```bash
php artisan test tests/Feature/Master/
```
Expected: All tests PASS.

- [ ] **Step 5: Commit**
```bash
git add app/Http/Controllers/Master/UserController.php \
        app/Http/Requests/StoreMasterUserRequest.php \
        resources/views/master/users/
git commit -m "feat: users management CRUD (admin only)"
```

---

## Task 10: Final Plan 1 Verification

- [ ] **Step 1: Run full test suite**
```bash
php artisan test
```
Expected: All tests PASS, no failures.

- [ ] **Step 2: Run migrations fresh to verify schema integrity**
```bash
php artisan migrate:fresh --seed
```
Expected: All 11 tables created, 3 shifts + 7 losstimes + 1 admin user seeded.

- [ ] **Step 3: Manual smoke test**
Login as admin → verify all sidebar menus visible → visit each master CRUD page → create one record in each master table → verify activity log is recorded.

- [ ] **Step 4: Final commit**
```bash
git add .
git commit -m "feat: Plan 1 complete — foundation, auth, all master CRUD"
```

---

**Plan 1 done.** Proceed to Plan 2 (`2026-05-23-optima-plan-2-input-services.md`) for Input Forms + Service Layer.
