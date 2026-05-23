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
        Schema::create('transaksi_qc', function (Blueprint $table) {
            $table->id();
            $table->date('tgl_pengecekan');
            $table->date('tgl_produksi_part');
            $table->foreignId('id_shift')->constrained('master_shift');
            $table->foreignId('id_karyawan_qc')->constrained('master_karyawan');
            $table->foreignId('id_part_proses')->constrained('master_part_proses');
            $table->time('jam_start');
            $table->time('jam_finish');
            $table->integer('qty_ok');
            $table->integer('qty_ng')->default(0);
            $table->decimal('actual_cycle_time', 8, 2)->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['tgl_pengecekan', 'id_karyawan_qc']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaksi_qc');
    }
};
