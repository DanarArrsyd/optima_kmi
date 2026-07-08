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
        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->unsignedBigInteger('id_shift')->nullable()->change();
            $table->unsignedBigInteger('id_karyawan_qc')->nullable()->change();
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });

        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->unsignedBigInteger('id_shift')->nullable()->change();
            $table->unsignedBigInteger('id_karyawan')->nullable()->change();
            $table->unsignedBigInteger('id_mesin')->nullable()->change();
            $table->unsignedBigInteger('created_by')->nullable()->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('transaksi_qc', function (Blueprint $table) {
            $table->unsignedBigInteger('id_shift')->nullable(false)->change();
            $table->unsignedBigInteger('id_karyawan_qc')->nullable(false)->change();
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });

        Schema::table('transaksi_produksi', function (Blueprint $table) {
            $table->unsignedBigInteger('id_shift')->nullable(false)->change();
            $table->unsignedBigInteger('id_karyawan')->nullable(false)->change();
            $table->unsignedBigInteger('id_mesin')->nullable(false)->change();
            $table->unsignedBigInteger('created_by')->nullable(false)->change();
        });
    }
};
