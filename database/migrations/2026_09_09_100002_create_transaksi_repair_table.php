<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_repair', function (Blueprint $table) {
            $table->id();
            $table->date('tanggal_repair');
            $table->foreignId('id_shift')->constrained('master_shift');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->foreignId('id_part_proses_repair')->constrained('master_part_proses_repair');
            $table->time('jam_start');
            $table->time('jam_finish');
            $table->integer('durasi_menit')->default(0);
            $table->integer('qty_aktual');
            $table->decimal('actual_cycle_time', 8, 2)->default(0);
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index(['tanggal_repair', 'id_karyawan']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_repair');
    }
};
