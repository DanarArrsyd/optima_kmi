<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('master_part_proses_repair_produksi', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_part')->constrained('master_part')->cascadeOnDelete();
            $table->string('id_proses');
            $table->string('nama_proses');
            $table->decimal('sph_standar_repair_produksi', 8, 2)->default(0);
            $table->softDeletes();
            $table->timestamps();
            $table->unique(['id_part', 'id_proses']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_part_proses_repair_produksi');
    }
};
