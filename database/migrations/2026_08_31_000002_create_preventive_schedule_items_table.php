<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('preventive_schedule_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('preventive_schedules')->cascadeOnDelete();
            $table->foreignId('id_mesin')->nullable()->constrained('master_mesin');
            $table->foreignId('id_part_proses')->nullable()->constrained('master_part_proses');
            $table->integer('stroke_snapshot');
            $table->decimal('pct_snapshot', 9, 1);
            $table->integer('threshold_snapshot');
            $table->date('tanggal_rencana');
            $table->unsignedTinyInteger('minggu_ke');
            $table->timestamps();
            $table->index('schedule_id');
            $table->index('tanggal_rencana');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('preventive_schedule_items');
    }
};
