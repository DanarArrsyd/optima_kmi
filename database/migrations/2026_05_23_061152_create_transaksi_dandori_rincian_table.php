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
        Schema::create('transaksi_dandori_rincian', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_transaksi_dandori')
                  ->constrained('transaksi_dandori')->cascadeOnDelete();
            $table->foreignId('id_losstime')->constrained('master_losstime');
            $table->integer('menit_loss');
            $table->integer('menit_over')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('transaksi_dandori_rincian');
    }
};
