<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tambah kolom jadwal Jumat & Sabtu ke master_shift
        // (kolom jam_start / jam_finish yang existing dipakai Senin–Kamis)
        Schema::table('master_shift', function (Blueprint $table) {
            $table->time('jam_start_jumat')->nullable()->after('jam_finish');
            $table->time('jam_finish_jumat')->nullable()->after('jam_start_jumat');
            $table->time('jam_start_sabtu')->nullable()->after('jam_finish_jumat');
            $table->time('jam_finish_sabtu')->nullable()->after('jam_start_sabtu');
        });

        // Tabel istirahat per kelompok hari
        Schema::create('master_shift_istirahat', function (Blueprint $table) {
            $table->id();
            $table->foreignId('id_shift')->constrained('master_shift')->cascadeOnDelete();
            $table->enum('kelompok_hari', ['senin_kamis', 'jumat', 'sabtu']);
            $table->time('jam_start');
            $table->time('jam_finish');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('master_shift_istirahat');

        Schema::table('master_shift', function (Blueprint $table) {
            $table->dropColumn(['jam_start_jumat', 'jam_finish_jumat', 'jam_start_sabtu', 'jam_finish_sabtu']);
        });
    }
};
