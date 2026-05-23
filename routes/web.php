<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
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

    Route::get('/dashboard', function () {
        $role = Auth::user()->role;
        return redirect(match(true) {
            in_array($role, ['admin', 'staff_produksi', 'manager']) => '/vcb/produksi',
            $role === 'staff_qc' => '/vcb/quality',
            default => '/vcb/produksi',
        });
    })->name('dashboard');

    // --- MASTER DATA (admin only) ---
    Route::middleware('role:admin')->prefix('master')->name('master.')->group(function () {
        Route::resource('karyawan', KaryawanController::class);
        Route::resource('mesin', MesinController::class);
        Route::resource('part', PartController::class)->except(['show']);
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

    // --- EXPORT (admin, manager only) ---
    Route::middleware('role:admin,manager')->prefix('export')->name('export.')->group(function () {
        Route::get('produksi', [ExportController::class, 'produksi'])->name('produksi');
        Route::get('qc', [ExportController::class, 'qc'])->name('qc');
        Route::get('dandori', [ExportController::class, 'dandori'])->name('dandori');
    });
});

require __DIR__.'/auth.php';
