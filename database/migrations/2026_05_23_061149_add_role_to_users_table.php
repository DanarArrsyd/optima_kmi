<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'staff_produksi', 'staff_qc', 'manager'])
                  ->default('staff_produksi')->after('email');
            $table->foreignId('id_karyawan')->nullable()->after('role')
                  ->constrained('master_karyawan')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['id_karyawan']);
            $table->dropColumn(['role', 'id_karyawan']);
        });
    }
};
