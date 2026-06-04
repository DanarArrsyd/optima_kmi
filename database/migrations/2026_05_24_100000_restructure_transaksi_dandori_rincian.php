<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Drop old tables (both empty — confirmed) */
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('transaksi_dandori_rincian');
        Schema::dropIfExists('transaksi_dandori');
        Schema::enableForeignKeyConstraints();

        /* New flat table: one row = one loss-time entry per dandori session */
        Schema::create('transaksi_dandori_rincian', function (Blueprint $table) {
            $table->id();

            // Session fields (repeated per row within same session)
            $table->date('tanggal');
            $table->foreignId('id_shift')->constrained('master_shift');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->foreignId('id_mesin')->constrained('master_mesin');
            $table->foreignId('id_part_proses')->constrained('master_part_proses');

            // Loss-time detail
            $table->foreignId('id_losstime')->constrained('master_losstime');
            $table->decimal('actual_menit', 8, 2)->comment('Input dari operator');
            $table->decimal('std_menit',    8, 2)->comment('Snapshot std dari master saat input');
            $table->decimal('over_menit',   8, 2)->default(0)->comment('max(0, actual - std)');

            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();

            // Indexes for VCB queries
            $table->index(['tanggal', 'id_mesin']);
            $table->index(['tanggal', 'id_part_proses']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_dandori_rincian');
    }
};
