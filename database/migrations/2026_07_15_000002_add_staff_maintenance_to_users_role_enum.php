<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * users.role dibuat ENUM(['admin','staff_produksi','staff_qc','manager']) di migration awal,
 * padahal app code (StoreMasterUserRequest, StoreBreakdownMesinRequest, StoreBreakdownDiesRequest)
 * sudah lama mengasumsikan role 'staff_maintenance' valid. ENUM belum pernah diperlebar —
 * gap pre-existing, ditemukan saat kerjakan dies-preventive-maintenance plan Task 3.
 *
 * MySQL pakai raw ALTER; SQLite (test) pakai ->change() (butuh doctrine/dbal, sudah terpasang).
 */
return new class extends Migration
{
    public function up(): void
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

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE users MODIFY role ENUM('admin','staff_produksi','staff_qc','manager') NOT NULL DEFAULT 'staff_produksi'");
            return;
        }

        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'staff_produksi', 'staff_qc', 'manager'])
                  ->default('staff_produksi')->change();
        });
    }
};
