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
        Schema::create('transaksi_dandori', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal');
            $table->foreignId('id_shift')->constrained('master_shift');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->foreignId('id_mesin')->constrained('master_mesin');
            $table->foreignId('id_part_proses')->constrained('master_part_proses');
            $table->integer('total_aktual_menit')->default(0);
            $table->integer('standard_time_menit')->default(0);
            $table->enum('status', ['OK', 'OVER'])->default('OK');
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['tanggal', 'id_mesin']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaksi_dandori');
    }
};
