<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * tipe_mesin dari ENUM(['PROGRESSIVE','STAMPING']) → VARCHAR agar tipe baru
 * (WELDING, FORKLIFT, HANDLIFT ELECTRIC/MANUAL, ENGINEERING, dst) bisa ditambah
 * tanpa migration lagi. Daftar tipe divalidasi via MasterMesin::TIPE_MESIN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_mesin', function (Blueprint $table) {
            $table->string('tipe_mesin', 50)->change();
        });
    }

    public function down(): void
    {
        Schema::table('master_mesin', function (Blueprint $table) {
            $table->enum('tipe_mesin', ['PROGRESSIVE', 'STAMPING'])->change();
        });
    }
};
