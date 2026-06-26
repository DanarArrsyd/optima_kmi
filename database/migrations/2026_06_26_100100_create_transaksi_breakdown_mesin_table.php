<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_breakdown_mesin', function (Blueprint $table) {
            $table->id();
            $table->string('no_lkm')->unique();
            $table->unsignedBigInteger('id_mesin');
            $table->date('tanggal_breakdown');
            $table->time('jam_breakdown');
            $table->date('tanggal_mulai_perbaikan');
            $table->time('jam_mulai_perbaikan');
            $table->date('tanggal_selesai_perbaikan');
            $table->time('jam_selesai_perbaikan');
            $table->text('problem_mesin');
            $table->text('penyebab_breakdown');
            $table->text('tindakan_perbaikan');
            $table->string('status_breakdown'); // Ringan | Sedang | Berat
            $table->string('pic_maintenance');
            $table->decimal('repair_time', 8, 2)->default(0);    // hours
            $table->decimal('total_downtime', 8, 2)->default(0); // hours
            $table->softDeletes();
            $table->timestamps();

            $table->index('id_mesin');
            $table->index('tanggal_breakdown');
            $table->foreign('id_mesin')->references('id')->on('master_mesin');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_breakdown_mesin');
    }
};
