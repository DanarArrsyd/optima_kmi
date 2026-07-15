<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dies_preventive_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_part_proses')->constrained('master_part_proses');
            $table->date('tanggal_preventive');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->integer('stroke_at_preventive');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index('id_part_proses');
            $table->index('tanggal_preventive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dies_preventive_log');
    }
};
