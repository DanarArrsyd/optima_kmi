<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Tandai kapan SPH QC terakhir diupgrade (mirip kolom produksi)
        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->timestamp('sph_qc_last_upgraded_at')
                  ->nullable()
                  ->after('sph_standar_qc')
                  ->comment('NULL = belum pernah diupgrade; diisi otomatis saat admin terapkan rekomendasi QC');
        });

        // 2. Tambah kolom type ke log agar bisa membedakan log produksi vs QC
        Schema::table('master_part_proses_sph_log', function (Blueprint $table) {
            $table->enum('type', ['produksi', 'qc'])
                  ->default('produksi')
                  ->after('id_part_proses');
        });
    }

    public function down(): void
    {
        Schema::table('master_part_proses_sph_log', function (Blueprint $table) {
            $table->dropColumn('type');
        });

        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->dropColumn('sph_qc_last_upgraded_at');
        });
    }
};
