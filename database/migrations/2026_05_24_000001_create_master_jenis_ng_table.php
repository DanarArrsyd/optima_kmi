<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('master_jenis_ng', function (Blueprint $table) {
            $table->id();
            $table->string('kode')->unique();
            $table->string('nama_ng');
            $table->text('deskripsi')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('master_jenis_ng'); }
};
