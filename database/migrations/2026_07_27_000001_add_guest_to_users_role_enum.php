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
