<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * departemen dari ENUM(['Produksi','QC']) → VARCHAR. Migration lama
 * (2026_07_02_110000_add_maintenance_to_master_karyawan_departemen_enum)
 * cuma nambah 'Maintenance' di MySQL — sengaja no-op di SQLite dengan
 * asumsi kolomnya sudah TEXT "yang menerima semua nilai". Asumsi itu
 * salah: `enum()` Laravel di SQLite jadi CHECK constraint sungguhan, jadi
 * departemen='Maintenance' selalu gagal INSERT di SQLite (test suite &
 * dev lokal) walau app-level validation (StoreMasterKaryawanRequest)
 * sudah lama mengizinkannya — ketahuan pas verifikasi browser form
 * Tambah Karyawan yang baru dipindah dari drawer ke halaman penuh.
 *
 * Daftar departemen sudah divalidasi di StoreMasterKaryawanRequest — CHECK
 * constraint DB jadi berlebihan sekaligus rawan menyimpang dari situ tiap
 * ada departemen baru (sama seperti tipe_mesin, lihat migration
 * 2026_07_12_000001_change_tipe_mesin_to_string_on_master_mesin).
 *
 * MySQL pakai raw ALTER (bulletproof, tak butuh doctrine/dbal); SQLite
 * (test) pakai ->change() — sudah native di driver SQLite Laravel, nol
 * butuh doctrine/dbal.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE master_karyawan MODIFY departemen VARCHAR(50) NOT NULL');

            return;
        }

        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->string('departemen', 50)->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE master_karyawan MODIFY departemen ENUM('Produksi','QC','Maintenance') NOT NULL");

            return;
        }

        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->enum('departemen', ['Produksi', 'QC', 'Maintenance'])->change();
        });
    }
};
