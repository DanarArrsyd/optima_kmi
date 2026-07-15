<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mesin_preventive_log', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_mesin')->constrained('master_mesin');
            $table->date('tanggal_preventive');
            $table->foreignId('id_karyawan')->constrained('master_karyawan');
            $table->integer('stroke_at_preventive');
            $table->text('catatan')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->softDeletes();
            $table->timestamps();
            $table->index('id_mesin');
            $table->index('tanggal_preventive');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mesin_preventive_log');
    }
};
