<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->boolean('is_aktif')->default(true)->after('departemen')
                  ->comment('true = masih aktif bekerja; false = sudah tidak aktif');
        });
    }

    public function down(): void
    {
        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->dropColumn('is_aktif');
        });
    }
};
