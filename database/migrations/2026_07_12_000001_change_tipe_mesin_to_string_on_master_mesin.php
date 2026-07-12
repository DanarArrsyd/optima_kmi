<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * tipe_mesin dari ENUM(['PROGRESSIVE','STAMPING']) → VARCHAR agar tipe baru
 * (WELDING, FORKLIFT, HANDLIFT ELECTRIC/MANUAL, ENGINEERING, dst) bisa ditambah
 * tanpa migration lagi. Daftar tipe divalidasi via MasterMesin::TIPE_MESIN.
 *
 * MySQL pakai raw ALTER (bulletproof, tak butuh doctrine/dbal); SQLite (test)
 * pakai ->change().
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE master_mesin MODIFY tipe_mesin VARCHAR(50) NOT NULL');
            return;
        }

        Schema::table('master_mesin', function (Blueprint $table) {
            $table->string('tipe_mesin', 50)->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE master_mesin MODIFY tipe_mesin ENUM('PROGRESSIVE','STAMPING') NOT NULL");
            return;
        }

        Schema::table('master_mesin', function (Blueprint $table) {
            $table->enum('tipe_mesin', ['PROGRESSIVE', 'STAMPING'])->change();
        });
    }
};
