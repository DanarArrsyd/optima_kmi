<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('transaksi_breakdown_dies', function (Blueprint $table) {
            $table->unsignedBigInteger('id_part_proses')->nullable()->after('id_part');
            $table->index('id_part_proses');
        });

        // Backfill: pakai proses pertama milik masing-masing part (data lama tidak tahu proses mana yang breakdown).
        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE transaksi_breakdown_dies AS t
                JOIN (
                    SELECT id_part, MIN(id) AS id_part_proses
                    FROM master_part_proses
                    GROUP BY id_part
                ) AS mp ON mp.id_part = t.id_part
                SET t.id_part_proses = mp.id_part_proses
                WHERE t.id_part_proses IS NULL
            ');
        } else {
            // SQLite fallback
            DB::statement('
                UPDATE transaksi_breakdown_dies
                SET id_part_proses = (
                    SELECT MIN(id)
                    FROM master_part_proses
                    WHERE id_part = transaksi_breakdown_dies.id_part
                )
                WHERE id_part_proses IS NULL
            ');
        }

        Schema::table('transaksi_breakdown_dies', function (Blueprint $table) {
            $table->unsignedBigInteger('id_part_proses')->nullable(false)->change();
            $table->foreign('id_part_proses')->references('id')->on('master_part_proses');
            $table->dropForeign(['id_part']);
        });

        // Drop index before dropping column (important for SQLite compatibility)
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS transaksi_breakdown_dies_id_part_index');
        }

        Schema::table('transaksi_breakdown_dies', function (Blueprint $table) {
            $table->dropColumn('id_part');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_breakdown_dies', function (Blueprint $table) {
            $table->unsignedBigInteger('id_part')->nullable()->after('no_lkd');
        });

        if (DB::getDriverName() === 'mysql') {
            DB::statement('
                UPDATE transaksi_breakdown_dies AS t
                JOIN master_part_proses AS mp ON mp.id = t.id_part_proses
                SET t.id_part = mp.id_part
            ');
        } else {
            // SQLite fallback
            DB::statement('
                UPDATE transaksi_breakdown_dies
                SET id_part = (
                    SELECT id_part
                    FROM master_part_proses
                    WHERE id = transaksi_breakdown_dies.id_part_proses
                )
            ');
        }

        Schema::table('transaksi_breakdown_dies', function (Blueprint $table) {
            $table->unsignedBigInteger('id_part')->nullable(false)->change();
            $table->foreign('id_part')->references('id')->on('master_part');
            $table->dropForeign(['id_part_proses']);
        });

        // Drop index before dropping column (important for SQLite compatibility)
        if (DB::getDriverName() === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS transaksi_breakdown_dies_id_part_proses_index');
        }

        Schema::table('transaksi_breakdown_dies', function (Blueprint $table) {
            $table->dropColumn('id_part_proses');
        });
    }
};
