<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL-only: modify ENUM column to add 'Maintenance' option
        // SQLite doesn't support ENUM, and the column is already TEXT type which accepts all values
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE master_karyawan MODIFY departemen ENUM('Produksi', 'QC', 'Maintenance') NOT NULL");
        }
    }

    public function down(): void
    {
        // MySQL-only: revert ENUM column
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE master_karyawan MODIFY departemen ENUM('Produksi', 'QC') NOT NULL");
        }
    }
};
