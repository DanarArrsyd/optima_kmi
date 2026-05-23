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
        Schema::create('master_mesin', function (Blueprint $table) {
            $table->id();
            $table->string('id_mesin')->unique();
            $table->string('nama_mesin');
            $table->string('line');
            $table->enum('tipe_mesin', ['PROGRESSIVE', 'STAMPING']);
            $table->integer('kapasitas_max_stroke')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('master_mesin');
    }
};
