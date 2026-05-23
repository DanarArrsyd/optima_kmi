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
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->index('tanggal_produksi');
        });

        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->index('tgl_pengecekan');
        });

        Schema::table('transaksi_dandori', function (Blueprint $table) {
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->dropIndex(['tanggal_produksi']);
        });

        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->dropIndex(['tgl_pengecekan']);
        });

        Schema::table('transaksi_dandori', function (Blueprint $table) {
            $table->dropIndex(['tanggal']);
        });
    }
};
