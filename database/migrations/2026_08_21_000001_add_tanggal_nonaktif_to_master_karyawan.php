<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->date('tanggal_nonaktif')->nullable()->after('is_aktif')
                ->comment('Tanggal karyawan dinonaktifkan; NULL = masih aktif atau tidak diketahui');
        });
    }

    public function down(): void
    {
        Schema::table('master_karyawan', function (Blueprint $table) {
            $table->dropColumn('tanggal_nonaktif');
        });
    }
};
