<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventive_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('bulan');
            $table->string('jenis', 10); // 'mesin' | 'dies'
            $table->string('nomor_dokumen');
            $table->dateTime('generated_at')->nullable();
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['bulan', 'jenis']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_schedules');
    }
};
