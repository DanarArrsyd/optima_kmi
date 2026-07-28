<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('transaksi_qc_ng', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_transaksi_qc')
                ->constrained('transaksi_qc')
                ->cascadeOnDelete();
            $table->foreignId('id_jenis_ng')
                ->constrained('master_jenis_ng')
                ->restrictOnDelete();
            $table->unsignedInteger('qty');
            $table->timestamps();

            $table->unique(['id_transaksi_qc', 'id_jenis_ng'], 'uniq_qc_ng');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('transaksi_qc_ng');
    }
};
