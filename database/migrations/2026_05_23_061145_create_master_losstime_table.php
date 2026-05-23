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
        Schema::create('master_losstime', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->string('jenis_losstime');
            $table->integer('std_menit_default')->nullable();
            $table->integer('std_menit_progressive')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_losstime');
    }
};
