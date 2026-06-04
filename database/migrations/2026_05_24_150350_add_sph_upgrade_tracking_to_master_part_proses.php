<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dua perubahan:
 * 1. Tambah kolom sph_last_upgraded_at ke master_part_proses
 *    → SphRecommendationService hanya scan LHP data SETELAH tanggal ini,
 *      sehingga data lama tidak dibandingkan terhadap standar baru.
 *
 * 2. Buat tabel master_part_proses_sph_log
 *    → audit trail: sebelum, sesudah, siapa, kapan.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Tandai kapan SPH standard terakhir diupgrade
        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->timestamp('sph_last_upgraded_at')
                  ->nullable()
                  ->after('sph_standar_produksi')
                  ->comment('NULL = belum pernah diupgrade; diisi saat admin terapkan rekomendasi');
        });

        // 2. Log setiap perubahan SPH standard
        Schema::create('master_part_proses_sph_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('id_part_proses');
            $table->integer('sph_standar_lama');
            $table->integer('sph_standar_baru');
            $table->unsignedBigInteger('upgraded_by')->nullable();
            $table->string('catatan', 255)->nullable();
            $table->timestamp('upgraded_at')->useCurrent();

            $table->foreign('id_part_proses')
                  ->references('id')->on('master_part_proses')
                  ->cascadeOnDelete();
            $table->foreign('upgraded_by')
                  ->references('id')->on('users')
                  ->nullOnDelete();

            $table->index('id_part_proses');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_part_proses_sph_log');

        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->dropColumn('sph_last_upgraded_at');
        });
    }
};
