<?php
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Master\KaryawanController;
use App\Http\Controllers\Master\MesinController;
use App\Http\Controllers\Master\PartController;
use App\Http\Controllers\Master\LosstimeController;
use App\Http\Controllers\Master\ShiftController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\Master\JenisNgController;
use App\Http\Controllers\Input\ProduksiController;
use App\Http\Controllers\Input\QcController;
use App\Http\Controllers\Input\DandoriController;
use App\Http\Controllers\Vcb\VcbProduksiController;
use App\Http\Controllers\Vcb\VcbMesinController;
use App\Http\Controllers\Vcb\VcbQualityController;
use App\Http\Controllers\Vcb\VcbDandoriController;

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
        // Import routes must come BEFORE resource routes to avoid model-binding conflicts
        Route::post('karyawan/import',           [KaryawanController::class, 'import'])->name('karyawan.import');
        Route::get('karyawan/import-template',   [KaryawanController::class, 'importTemplate'])->name('karyawan.import-template');

        Route::post('mesin/import',              [MesinController::class, 'import'])->name('mesin.import');
        Route::get('mesin/import-template',      [MesinController::class, 'importTemplate'])->name('mesin.import-template');

        Route::post('part/import',               [PartController::class, 'import'])->name('part.import');
        Route::get('part/import-template',       [PartController::class, 'importTemplate'])->name('part.import-template');

        Route::post('losstime/import',           [LosstimeController::class, 'import'])->name('losstime.import');
        Route::get('losstime/import-template',   [LosstimeController::class, 'importTemplate'])->name('losstime.import-template');

        Route::post('shift/import',              [ShiftController::class, 'import'])->name('shift.import');
        Route::get('shift/import-template',      [ShiftController::class, 'importTemplate'])->name('shift.import-template');

        Route::resource('karyawan', KaryawanController::class);
        Route::resource('mesin', MesinController::class);
        Route::resource('part', PartController::class)->except(['show']);
        Route::resource('losstime', LosstimeController::class);
        Route::resource('shift', ShiftController::class);
        Route::resource('users', UserController::class);
        Route::post('jenis-ng/import',         [JenisNgController::class, 'import'])->name('jenis-ng.import');
        Route::get('jenis-ng/import-template', [JenisNgController::class, 'importTemplate'])->name('jenis-ng.import-template');
        Route::resource('jenis-ng', JenisNgController::class)->only(['index', 'store', 'update', 'destroy']);
        // Sub-resource: proses per part
        Route::post('part/{part}/proses',                     [PartController::class, 'storeProses'])->name('part.proses.store');
        Route::delete('part/{part}/proses/{proses}',          [PartController::class, 'destroyProses'])->name('part.proses.destroy');
        Route::patch('part/{part}/proses/{proses}/upgrade-sph',    [PartController::class, 'upgradeSph'])->name('part.proses.upgrade-sph');
        Route::patch('part/{part}/proses/{proses}/upgrade-qc-sph', [PartController::class, 'upgradeQcSph'])->name('part.proses.upgrade-qc-sph');
    });

    // --- INPUT PRODUKSI (admin, staff_produksi) ---
    Route::middleware('role:admin,staff_produksi')->prefix('input')->name('input.')->group(function () {
        Route::resource('produksi', ProduksiController::class)->only(['index', 'create', 'store', 'destroy']);
        Route::post('produksi/import', [ProduksiController::class, 'import'])->name('produksi.import');
        Route::get('produksi/import-template', [ProduksiController::class, 'importTemplate'])->name('produksi.import-template');
        Route::get('produksi/export', [ProduksiController::class, 'export'])->name('produksi.export');
        Route::resource('dandori', DandoriController::class)->only(['index', 'create', 'store', 'destroy']);
        Route::post('dandori/import',          [DandoriController::class, 'import'])->name('dandori.import');
        Route::get('dandori/import-template',  [DandoriController::class, 'importTemplate'])->name('dandori.import-template');
        Route::get('dandori/export',           [DandoriController::class, 'export'])->name('dandori.export');
        // AJAX: get proses list for a part (used by form JS)
        Route::get('dandori/part-proses/{part}', [DandoriController::class, 'getPartProses'])->name('dandori.part-proses');
    });

    // --- EDIT/UPDATE LHP — admin only ---
    Route::middleware('role:admin')->prefix('input')->name('input.')->group(function () {
        Route::get('produksi/{produksi}/edit', [ProduksiController::class, 'edit'])->name('produksi.edit');
        Route::put('produksi/{produksi}', [ProduksiController::class, 'update'])->name('produksi.update');
        Route::get('qc/{qc}/edit', [QcController::class, 'edit'])->name('qc.edit');
        Route::put('qc/{qc}', [QcController::class, 'update'])->name('qc.update');
    });

    // --- INPUT QC (admin, staff_qc) ---
    Route::middleware('role:admin,staff_qc')->prefix('input')->name('input.')->group(function () {
        Route::resource('qc', QcController::class)->only(['index', 'create', 'store', 'destroy']);
        Route::post('qc/import', [QcController::class, 'import'])->name('qc.import');
        Route::get('qc/import-template', [QcController::class, 'importTemplate'])->name('qc.import-template');
        Route::get('qc/export', [QcController::class, 'export'])->name('qc.export');
    });

    // --- VCB PRODUKSI & MESIN (admin, staff_produksi, manager) ---
    Route::middleware('role:admin,staff_produksi,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('produksi', [VcbProduksiController::class, 'index'])->name('produksi');
        Route::get('produksi/data', [VcbProduksiController::class, 'data'])->name('produksi.data');
        Route::get('produksi/operator-data', [VcbProduksiController::class, 'operatorData'])->name('produksi.operator-data');
        Route::get('mesin', [VcbMesinController::class, 'index'])->name('mesin');
        Route::get('mesin/data', [VcbMesinController::class, 'data'])->name('mesin.data');
        Route::get('dandori', [VcbDandoriController::class, 'index'])->name('dandori');
        Route::get('dandori/data', [VcbDandoriController::class, 'data'])->name('dandori.data');
    });

    // --- VCB QUALITY (admin, staff_qc, manager) ---
    Route::middleware('role:admin,staff_qc,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('quality', [VcbQualityController::class, 'index'])->name('quality');
        Route::get('quality/data', [VcbQualityController::class, 'data'])->name('quality.data');
        Route::get('quality/operator-data', [VcbQualityController::class, 'operatorData'])->name('quality.operator-data');
    });
});

require __DIR__.'/auth.php';
