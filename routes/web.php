<?php

use App\Http\Controllers\Admin\AnomalyController;
use App\Http\Controllers\ChatbotController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Input\DandoriController;
use App\Http\Controllers\Input\ProduksiController;
use App\Http\Controllers\Input\QcController;
use App\Http\Controllers\Input\RepairController;
use App\Http\Controllers\Input\RepairProduksiController;
use App\Http\Controllers\Maintenance\AvailabilityController;
use App\Http\Controllers\Maintenance\AvailabilityDiesController;
use App\Http\Controllers\Maintenance\BreakdownDiesController;
use App\Http\Controllers\Maintenance\BreakdownMesinController;
use App\Http\Controllers\Maintenance\ControlStrokeDiesController;
use App\Http\Controllers\Maintenance\ControlStrokeMesinController;
use App\Http\Controllers\Maintenance\MtbfController;
use App\Http\Controllers\Maintenance\MtbfDiesController;
use App\Http\Controllers\Maintenance\MttrController;
use App\Http\Controllers\Maintenance\MttrDiesController;
use App\Http\Controllers\Master\JenisNgController;
use App\Http\Controllers\Master\KaryawanController;
use App\Http\Controllers\Master\LosstimeController;
use App\Http\Controllers\Master\MesinController;
use App\Http\Controllers\Master\PartController;
use App\Http\Controllers\Master\ProsesRepairController;
use App\Http\Controllers\Master\ProsesRepairProduksiController;
use App\Http\Controllers\Master\ShiftController;
use App\Http\Controllers\Master\UserController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\Sistem\BackupController;
use App\Http\Controllers\Sistem\LogAktivitasController;
use App\Http\Controllers\TwoFactorAuthController;
use App\Http\Controllers\Vcb\VcbDandoriController;
use App\Http\Controllers\Vcb\VcbMesinController;
use App\Http\Controllers\Vcb\VcbNgProsesController;
use App\Http\Controllers\Vcb\VcbNgTotalController;
use App\Http\Controllers\Vcb\VcbProduksiController;
use App\Http\Controllers\Vcb\VcbQualityController;
use Illuminate\Support\Facades\Route;

Route::get('/', [HomeController::class, 'root']);

Route::middleware('auth')->group(function () {

    Route::get('/dashboard', [HomeController::class, 'dashboard'])->name('dashboard');

    // --- MASTER DATA (admin only) ---
    Route::middleware('role:admin')->prefix('master')->name('master.')->group(function () {
        // Import routes must come BEFORE resource routes to avoid model-binding conflicts
        Route::post('karyawan/import', [KaryawanController::class, 'import'])->name('karyawan.import');
        Route::get('karyawan/import-template', [KaryawanController::class, 'importTemplate'])->name('karyawan.import-template');
        Route::patch('karyawan/{karyawan}/toggle-aktif', [KaryawanController::class, 'toggleAktif'])->name('karyawan.toggle-aktif');

        Route::post('mesin/import', [MesinController::class, 'import'])->name('mesin.import');
        Route::get('mesin/import-template', [MesinController::class, 'importTemplate'])->name('mesin.import-template');

        Route::post('part/import', [PartController::class, 'import'])->name('part.import');
        Route::get('part/import-template', [PartController::class, 'importTemplate'])->name('part.import-template');
        Route::get('part/export', [PartController::class, 'export'])->name('part.export');
        Route::get('part/sph-recommendations', [PartController::class, 'sphRecommendations'])->name('part.sph-recommendations');
        Route::post('part/sph-dismiss', [PartController::class, 'dismissSphRecommendation'])->name('part.sph-dismiss');
        Route::post('part/sph-dismiss-reset', [PartController::class, 'resetSphDismissed'])->name('part.sph-dismiss-reset');

        Route::post('losstime/import', [LosstimeController::class, 'import'])->name('losstime.import');
        Route::get('losstime/import-template', [LosstimeController::class, 'importTemplate'])->name('losstime.import-template');

        Route::post('shift/import', [ShiftController::class, 'import'])->name('shift.import');
        Route::get('shift/import-template', [ShiftController::class, 'importTemplate'])->name('shift.import-template');

        Route::resource('karyawan', KaryawanController::class);
        Route::resource('mesin', MesinController::class);
        Route::resource('part', PartController::class)->except(['show']);
        Route::resource('losstime', LosstimeController::class);
        Route::get('proses-repair', [ProsesRepairController::class, 'index'])->name('proses-repair.index');
        Route::get('proses-repair/create', [ProsesRepairController::class, 'create'])->name('proses-repair.create');
        Route::post('proses-repair', [ProsesRepairController::class, 'store'])->name('proses-repair.store');
        Route::get('proses-repair/{prosesRepair}/edit', [ProsesRepairController::class, 'edit'])->name('proses-repair.edit');
        Route::put('proses-repair/{prosesRepair}', [ProsesRepairController::class, 'update'])->name('proses-repair.update');
        Route::delete('proses-repair/{prosesRepair}', [ProsesRepairController::class, 'destroy'])->name('proses-repair.destroy');
        Route::get('proses-repair-produksi', [ProsesRepairProduksiController::class, 'index'])->name('proses-repair-produksi.index');
        Route::get('proses-repair-produksi/create', [ProsesRepairProduksiController::class, 'create'])->name('proses-repair-produksi.create');
        Route::post('proses-repair-produksi', [ProsesRepairProduksiController::class, 'store'])->name('proses-repair-produksi.store');
        Route::get('proses-repair-produksi/{prosesRepairProduksi}/edit', [ProsesRepairProduksiController::class, 'edit'])->name('proses-repair-produksi.edit');
        Route::put('proses-repair-produksi/{prosesRepairProduksi}', [ProsesRepairProduksiController::class, 'update'])->name('proses-repair-produksi.update');
        Route::delete('proses-repair-produksi/{prosesRepairProduksi}', [ProsesRepairProduksiController::class, 'destroy'])->name('proses-repair-produksi.destroy');
        Route::resource('shift', ShiftController::class);
        Route::patch('users/{user}/toggle-aktif', [UserController::class, 'toggleAktif'])->name('users.toggle-aktif');
        Route::resource('users', UserController::class);
        Route::post('jenis-ng/import', [JenisNgController::class, 'import'])->name('jenis-ng.import');
        Route::get('jenis-ng/import-template', [JenisNgController::class, 'importTemplate'])->name('jenis-ng.import-template');
        Route::resource('jenis-ng', JenisNgController::class)->only(['index', 'create', 'store', 'edit', 'update', 'destroy']);
        // Sub-resource: proses per part
        Route::get('part/{part}/proses/create', [PartController::class, 'createProses'])->name('part.proses.create');
        Route::post('part/{part}/proses', [PartController::class, 'storeProses'])->name('part.proses.store');
        Route::delete('part/{part}/proses/{proses}', [PartController::class, 'destroyProses'])->name('part.proses.destroy');
        Route::patch('part/{part}/proses/{proses}/upgrade-sph', [PartController::class, 'upgradeSph'])->name('part.proses.upgrade-sph');
        Route::patch('part/{part}/proses/{proses}/upgrade-qc-sph', [PartController::class, 'upgradeQcSph'])->name('part.proses.upgrade-qc-sph');
    });

    // --- SISTEM (admin only) — satu arsip memuat seluruh DB termasuk tabel users ---
    Route::middleware('role:admin')->prefix('sistem')->name('sistem.')->group(function () {
        Route::get('backup', [BackupController::class, 'index'])->name('backup.index');
        Route::post('backup/jalankan', [BackupController::class, 'jalankan'])->name('backup.jalankan');
        Route::get('backup/{berkas}/unduh', [BackupController::class, 'unduh'])->name('backup.unduh');
        Route::delete('backup/{berkas}', [BackupController::class, 'hapus'])->name('backup.hapus');
        Route::get('log-aktivitas', [LogAktivitasController::class, 'index'])->name('log-aktivitas.index');
    });

    // --- 2FA (admin only) — setup/nonaktifkan TOTP. Nol route 'profile.*' lain
    // terpasang di app ini (ProfileController Breeze dead scaffolding). ---
    Route::middleware('role:admin')->prefix('profile')->name('profile.')->group(function () {
        Route::get('two-factor', [TwoFactorAuthController::class, 'show'])->name('two-factor');
        Route::post('two-factor', [TwoFactorAuthController::class, 'confirm'])->name('two-factor.confirm');
        Route::delete('two-factor', [TwoFactorAuthController::class, 'destroy'])->name('two-factor.destroy');
    });

    // --- INPUT PRODUKSI: daftar (read-only, guest boleh) ---
    Route::middleware('role:admin,staff_produksi,guest')->prefix('input')->name('input.')->group(function () {
        Route::get('produksi', [ProduksiController::class, 'index'])->name('produksi.index');
        Route::get('dandori', [DandoriController::class, 'index'])->name('dandori.index');
    });

    // --- INPUT PRODUKSI: tulis & export (guest TIDAK boleh) ---
    Route::middleware('role:admin,staff_produksi')->prefix('input')->name('input.')->group(function () {
        Route::post('produksi/batch', [ProduksiController::class, 'batch'])->name('produksi.batch');
        Route::resource('produksi', ProduksiController::class)->only(['create', 'destroy']);
        Route::post('produksi/import', [ProduksiController::class, 'import'])->name('produksi.import');
        Route::get('produksi/import-template', [ProduksiController::class, 'importTemplate'])->name('produksi.import-template');
        Route::get('produksi/export', [ProduksiController::class, 'export'])->name('produksi.export');
        Route::post('produksi-note', [ProduksiController::class, 'note'])->name('produksi.note');
        Route::post('dandori/batch', [DandoriController::class, 'batch'])->name('dandori.batch');
        Route::resource('dandori', DandoriController::class)->only(['create', 'destroy']);
        Route::get('dandori/import-template', [DandoriController::class, 'importTemplate'])->name('dandori.import-template');
        Route::get('dandori/export', [DandoriController::class, 'export'])->name('dandori.export');
        Route::post('dandori-note', [DandoriController::class, 'note'])->name('dandori.note');
        // AJAX: get proses list for a part (used by form JS)
        Route::get('dandori/part-proses/{part}', [DandoriController::class, 'getPartProses'])->name('dandori.part-proses');
    });

    // --- ADMIN TOOLS (admin only) ---
    Route::middleware('role:admin')->name('admin.')->group(function () {
        Route::get('admin/anomali', [AnomalyController::class, 'index'])->name('anomali');
        Route::post('admin/anomali/{transaksi}/approve', [AnomalyController::class, 'approve'])->name('anomali.approve');
    });

    // --- INPUT & DAFTAR LHR (staff_repair + admin) ---
    Route::middleware('role:admin,staff_repair')->prefix('input')->name('input.')->group(function () {
        Route::get('repair', [RepairController::class, 'index'])->name('repair.index');
        Route::get('repair/create', [RepairController::class, 'create'])->name('repair.create');
        Route::post('repair/batch', [RepairController::class, 'batch'])->name('repair.batch');
        Route::get('repair/export', [RepairController::class, 'export'])->name('repair.export');
    });

    // --- INPUT & DAFTAR LHR PRODUKSI (staff_produksi + staff_repair + admin) ---
    Route::middleware('role:admin,staff_repair,staff_produksi')->prefix('input')->name('input.')->group(function () {
        Route::get('repair-produksi', [RepairProduksiController::class, 'index'])->name('repair-produksi.index');
        Route::get('repair-produksi/create', [RepairProduksiController::class, 'create'])->name('repair-produksi.create');
        Route::post('repair-produksi/batch', [RepairProduksiController::class, 'batch'])->name('repair-produksi.batch');
        Route::get('repair-produksi/export', [RepairProduksiController::class, 'export'])->name('repair-produksi.export');
    });

    // --- EDIT/UPDATE LHP — admin only ---
    Route::middleware('role:admin')->prefix('input')->name('input.')->group(function () {
        Route::get('produksi/{produksi}/edit', [ProduksiController::class, 'edit'])->name('produksi.edit');
        Route::put('produksi/{produksi}', [ProduksiController::class, 'update'])->name('produksi.update');
        Route::post('produksi-note-resolve', [ProduksiController::class, 'resolveNote'])->name('produksi.note-resolve');
        Route::get('qc/{qc}/edit', [QcController::class, 'edit'])->name('qc.edit');
        Route::put('qc/{qc}', [QcController::class, 'update'])->name('qc.update');
        Route::post('qc-note-resolve', [QcController::class, 'resolveNote'])->name('qc.note-resolve');
        Route::get('dandori/{dandori}/edit', [DandoriController::class, 'edit'])->name('dandori.edit');
        Route::put('dandori/{dandori}', [DandoriController::class, 'update'])->name('dandori.update');
        Route::post('dandori-note-resolve', [DandoriController::class, 'resolveNote'])->name('dandori.note-resolve');
        // Import Excel dandori UPSERT (timpa baris existing lewat kunci natural, termasuk
        // hidupin baris yang di-soft-delete admin) — beda dari batch() yang insert-only.
        // Admin-only, samain sama boundary dandori.update di atas (audit keamanan 2026-09-03).
        Route::post('dandori/import', [DandoriController::class, 'import'])->name('dandori.import');
        Route::get('repair/{repair}/edit', [RepairController::class, 'edit'])->name('repair.edit');
        Route::put('repair/{repair}', [RepairController::class, 'update'])->name('repair.update');
        Route::delete('repair/{repair}', [RepairController::class, 'destroy'])->name('repair.destroy');
        Route::get('repair-produksi/{repairProduksi}/edit', [RepairProduksiController::class, 'edit'])->name('repair-produksi.edit');
        Route::put('repair-produksi/{repairProduksi}', [RepairProduksiController::class, 'update'])->name('repair-produksi.update');
        Route::delete('repair-produksi/{repairProduksi}', [RepairProduksiController::class, 'destroy'])->name('repair-produksi.destroy');
    });

    // --- INPUT QC: daftar (read-only, guest boleh) ---
    Route::middleware('role:admin,staff_qc,guest')->prefix('input')->name('input.')->group(function () {
        Route::get('qc', [QcController::class, 'index'])->name('qc.index');
    });

    // --- INPUT QC: tulis & export (guest TIDAK boleh) ---
    Route::middleware('role:admin,staff_qc')->prefix('input')->name('input.')->group(function () {
        Route::post('qc/batch', [QcController::class, 'batch'])->name('qc.batch');
        Route::resource('qc', QcController::class)->only(['create', 'destroy']);
        Route::post('qc/import', [QcController::class, 'import'])->name('qc.import');
        Route::get('qc/import-template', [QcController::class, 'importTemplate'])->name('qc.import-template');
        Route::get('qc/export', [QcController::class, 'export'])->name('qc.export');
        Route::post('qc-note', [QcController::class, 'note'])->name('qc.note');
    });

    // --- VCB PRODUKSI, MESIN, DANDORI — read-only, guest boleh ---
    Route::middleware('role:admin,staff_produksi,manager,guest')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('produksi', [VcbProduksiController::class, 'index'])->name('produksi');
        Route::get('produksi/data', [VcbProduksiController::class, 'data'])->name('produksi.data');
        Route::get('produksi/operator-data', [VcbProduksiController::class, 'operatorData'])->name('produksi.operator-data');
        Route::get('mesin', [VcbMesinController::class, 'index'])->name('mesin');
        Route::get('mesin/data', [VcbMesinController::class, 'data'])->name('mesin.data');
        Route::get('dandori', [VcbDandoriController::class, 'index'])->name('dandori');
        Route::get('dandori/data', [VcbDandoriController::class, 'data'])->name('dandori.data');
        Route::get('ng-proses', [VcbNgProsesController::class, 'index'])->name('ng-proses');
        Route::get('ng-proses/data', [VcbNgProsesController::class, 'data'])->name('ng-proses.data');
        Route::get('ng-proses/detail', [VcbNgProsesController::class, 'detail'])->name('ng-proses.detail');
        Route::get('ng-proses/daftar', [VcbNgProsesController::class, 'daftar'])->name('ng-proses.daftar');
    });

    // --- VCB DANDORI & DAFTAR NG PROSES EXPORT — guest TIDAK boleh ---
    Route::middleware('role:admin,staff_produksi,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('dandori/export', [VcbDandoriController::class, 'export'])->name('dandori.export');
        Route::get('ng-proses/daftar/export', [VcbNgProsesController::class, 'export'])->name('ng-proses.daftar.export');
    });

    // --- VCB QUALITY — read-only, guest boleh ---
    Route::middleware('role:admin,staff_qc,manager,guest')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('quality', [VcbQualityController::class, 'index'])->name('quality');
        Route::get('quality/data', [VcbQualityController::class, 'data'])->name('quality.data');
        Route::get('quality/operator-data', [VcbQualityController::class, 'operatorData'])->name('quality.operator-data');
        Route::get('ng-total', [VcbNgTotalController::class, 'index'])->name('ng-total');
        Route::get('ng-total/data', [VcbNgTotalController::class, 'data'])->name('ng-total.data');
        Route::get('ng-total/detail', [VcbNgTotalController::class, 'detail'])->name('ng-total.detail');
        Route::get('ng-total/daftar', [VcbNgTotalController::class, 'daftar'])->name('ng-total.daftar');
    });

    // --- DAFTAR NG TOTAL EXPORT — guest TIDAK boleh ---
    // Grup baru: sisi Produksi sudah punya padanannya (lihat grup dandori/export di
    // atas), sisi QC belum. Isinya GET semua, jadi DenyGuestWrite tidak tersentuh.
    Route::middleware('role:admin,staff_qc,manager')->prefix('vcb')->name('vcb.')->group(function () {
        Route::get('ng-total/daftar/export', [VcbNgTotalController::class, 'export'])->name('ng-total.daftar.export');
    });

    // --- MAINTENANCE: breakdown, import/export, input preventive (guest TIDAK boleh) ---
    Route::middleware('role:admin,staff_maintenance,manager')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('breakdown/last-no-lkm', [BreakdownMesinController::class, 'lastNoLkm'])->name('breakdown.last-no-lkm');
        Route::get('breakdown/export', [BreakdownMesinController::class, 'export'])->name('breakdown.export');
        Route::post('breakdown/import', [BreakdownMesinController::class, 'import'])->name('breakdown.import');
        Route::get('breakdown/import-template', [BreakdownMesinController::class, 'importTemplate'])->name('breakdown.import-template');
        Route::post('breakdown/batch', [BreakdownMesinController::class, 'batch'])->name('breakdown.batch');
        Route::resource('breakdown', BreakdownMesinController::class)->only(['index', 'create']);

        Route::get('breakdown-dies/last-no-lkd', [BreakdownDiesController::class, 'lastNoLkd'])->name('breakdown-dies.last-no-lkd');
        Route::get('breakdown-dies/export', [BreakdownDiesController::class, 'export'])->name('breakdown-dies.export');
        Route::post('breakdown-dies/import', [BreakdownDiesController::class, 'import'])->name('breakdown-dies.import');
        Route::get('breakdown-dies/import-template', [BreakdownDiesController::class, 'importTemplate'])->name('breakdown-dies.import-template');
        Route::post('breakdown-dies/batch', [BreakdownDiesController::class, 'batch'])->name('breakdown-dies.batch');
        Route::resource('breakdown-dies', BreakdownDiesController::class)->only(['index', 'create']);

        Route::get('availability/control-stroke-mesin/{mesin}/preventive', [ControlStrokeMesinController::class, 'createPreventive'])->name('control-stroke-mesin.preventive.create');
        Route::post('availability/control-stroke-mesin/{mesin}/preventive', [ControlStrokeMesinController::class, 'storePreventive'])->name('control-stroke-mesin.preventive');

        Route::get('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'createPreventive'])->name('control-stroke-dies.preventive.create');
        Route::post('availability/control-stroke-dies/{partProses}/preventive', [ControlStrokeDiesController::class, 'storePreventive'])->name('control-stroke-dies.preventive');
        Route::get('availability/control-stroke-dies/preventive/{diesPreventiveLog}/checksheet', [ControlStrokeDiesController::class, 'showChecksheet'])->name('control-stroke-dies.preventive.checksheet');

        Route::post('availability/control-stroke-mesin/schedule-generate', [ControlStrokeMesinController::class, 'scheduleGenerate'])->name('control-stroke-mesin.schedule-generate');
        Route::patch('availability/control-stroke-mesin/schedule-item/{item}', [ControlStrokeMesinController::class, 'updateScheduleItem'])->name('control-stroke-mesin.schedule-item.update');
        Route::post('availability/control-stroke-mesin/schedule-item/{item}/reset', [ControlStrokeMesinController::class, 'resetScheduleItem'])->name('control-stroke-mesin.schedule-item.reset');

        Route::post('availability/control-stroke-dies/schedule-generate', [ControlStrokeDiesController::class, 'scheduleGenerate'])->name('control-stroke-dies.schedule-generate');
        Route::patch('availability/control-stroke-dies/schedule-item/{item}', [ControlStrokeDiesController::class, 'updateScheduleItem'])->name('control-stroke-dies.schedule-item.update');
        Route::post('availability/control-stroke-dies/schedule-item/{item}/reset', [ControlStrokeDiesController::class, 'resetScheduleItem'])->name('control-stroke-dies.schedule-item.reset');
    });

    // --- MAINTENANCE: analitik read-only (guest boleh) ---
    Route::middleware('role:admin,staff_maintenance,manager,guest')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('mttr', [MttrController::class, 'index'])->name('mttr');
        Route::get('mttr/data', [MttrController::class, 'data'])->name('mttr.data');
        Route::get('mtbf', [MtbfController::class, 'index'])->name('mtbf');
        Route::get('mtbf/data', [MtbfController::class, 'data'])->name('mtbf.data');
        Route::get('availability', [AvailabilityController::class, 'index'])->name('availability');
        Route::get('availability/data', [AvailabilityController::class, 'data'])->name('availability.data');

        Route::get('mttr-dies', [MttrDiesController::class, 'index'])->name('mttr-dies');
        Route::get('mttr-dies/data', [MttrDiesController::class, 'data'])->name('mttr-dies.data');
        Route::get('mtbf-dies', [MtbfDiesController::class, 'index'])->name('mtbf-dies');
        Route::get('mtbf-dies/data', [MtbfDiesController::class, 'data'])->name('mtbf-dies.data');
        Route::get('availability-dies', [AvailabilityDiesController::class, 'index'])->name('availability-dies');
        Route::get('availability-dies/data', [AvailabilityDiesController::class, 'data'])->name('availability-dies.data');

        Route::get('availability/control-stroke-mesin', [ControlStrokeMesinController::class, 'index'])->name('control-stroke-mesin');
        Route::get('availability/control-stroke-mesin/data', [ControlStrokeMesinController::class, 'data'])->name('control-stroke-mesin.data');
        Route::get('availability/control-stroke-mesin/{mesin}/history', [ControlStrokeMesinController::class, 'history'])->name('control-stroke-mesin.history');

        Route::get('availability/control-stroke-dies', [ControlStrokeDiesController::class, 'index'])->name('control-stroke-dies');
        Route::get('availability/control-stroke-dies/data', [ControlStrokeDiesController::class, 'data'])->name('control-stroke-dies.data');
        Route::get('availability/control-stroke-dies/{partProses}/history', [ControlStrokeDiesController::class, 'history'])->name('control-stroke-dies.history');
    });

    // --- SCHEDULE PREVENTIVE: PDF & edit manual — admin only (dicabut dari staff_maintenance/
    // manager/guest 2026-09-03, sebelumnya ikut grup baca Control Stroke di atas) ---
    Route::middleware('role:admin')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('availability/control-stroke-mesin/schedule-pdf', [ControlStrokeMesinController::class, 'schedulePdf'])->name('control-stroke-mesin.schedule-pdf');
        Route::get('availability/control-stroke-mesin/schedule', [ControlStrokeMesinController::class, 'scheduleEdit'])->name('control-stroke-mesin.schedule-edit');
        Route::get('availability/control-stroke-dies/schedule-pdf', [ControlStrokeDiesController::class, 'schedulePdf'])->name('control-stroke-dies.schedule-pdf');
        Route::get('availability/control-stroke-dies/schedule', [ControlStrokeDiesController::class, 'scheduleEdit'])->name('control-stroke-dies.schedule-edit');
    });

    // --- NOTIFICATIONS (all authenticated users) ---
    Route::get('/notifications/poll', [NotificationController::class, 'poll']);
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead']);
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead']);

    // --- MAINTENANCE edit/hapus (admin only) ---
    Route::middleware('role:admin')->prefix('maintenance')->name('maintenance.')->group(function () {
        Route::get('breakdown/{breakdown}/edit', [BreakdownMesinController::class, 'edit'])->name('breakdown.edit');
        Route::put('breakdown/{breakdown}', [BreakdownMesinController::class, 'update'])->name('breakdown.update');
        Route::delete('breakdown/{breakdown}', [BreakdownMesinController::class, 'destroy'])->name('breakdown.destroy');

        Route::get('breakdown-dies/{breakdownDy}/edit', [BreakdownDiesController::class, 'edit'])->name('breakdown-dies.edit');
        Route::put('breakdown-dies/{breakdownDy}', [BreakdownDiesController::class, 'update'])->name('breakdown-dies.update');
        Route::delete('breakdown-dies/{breakdownDy}', [BreakdownDiesController::class, 'destroy'])->name('breakdown-dies.destroy');
    });

    // --- OVERVIEW (grafik global pabrik, semua role) ---
    // Sengaja DI LUAR prefix 'chatbot': halaman ini bukan fitur AI, dan
    // menunya berdiri sendiri di sidebar. URL '/chatbot/overview' akan
    // menyesatkan. Konsekuensinya middleware role: harus ditulis eksplisit
    // di sini — dia tidak lagi mewarisi dari grup chatbot.
    //
    // 'guest' ADA di daftar ini, dan itu disengaja: halaman read-only tanpa
    // export. Route-nya WAJIB terdaftar di AuthorizationTest::guestAllowedRoutes()
    // — assertion default-deny di sana membandingkan himpunan route yang
    // benar-benar bisa dicapai guest dengan whitelist itu, jadi menambah guest
    // di sini tanpa mendaftarkannya bikin test merah (bukan bolong senyap).
    Route::middleware('role:admin,manager,staff_produksi,staff_qc,staff_maintenance,staff_repair,guest')
        ->get('overview', [OverviewController::class, 'index'])
        ->name('overview');

    // --- AI CHATBOT (admin & manager only) ---
    Route::middleware('role:admin,manager')->prefix('chatbot')->name('chatbot.')->group(function () {
        Route::get('/', [ChatbotController::class, 'index'])->name('index');
        Route::post('message', [ChatbotController::class, 'sendMessage'])
            ->middleware('throttle:20,1')->name('send-message');
        Route::post('stream', [ChatbotController::class, 'stream'])
            ->middleware('throttle:20,1')->name('stream');
    });
});

require __DIR__.'/auth.php';
