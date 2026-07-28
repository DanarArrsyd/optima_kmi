<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_jenis_ng', function (Blueprint $table) {
            $table->string('kategori', 50)->nullable()->after('nama_ng');
        });
    }

    public function down(): void
    {
        Schema::table('master_jenis_ng', function (Blueprint $table) {
            $table->dropColumn('kategori');
        });
    }
};
