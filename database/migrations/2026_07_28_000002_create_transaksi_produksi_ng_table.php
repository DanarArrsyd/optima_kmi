<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_produksi_ng', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_transaksi_produksi')
                ->constrained('transaksi_produksi')
                ->cascadeOnDelete();
            $table->foreignId('id_jenis_ng')
                ->constrained('master_jenis_ng')
                ->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            // Satu jenis NG tidak boleh dobel dalam satu sesi.
            $table->unique(['id_transaksi_produksi', 'id_jenis_ng'], 'uniq_prod_ng');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_produksi_ng');
    }
};
