<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_mesin', function (Blueprint $table) {
            $table->date('tanggal_instalasi')->nullable()->after('kapasitas_max_stroke');
        });
    }

    public function down(): void
    {
        Schema::table('master_mesin', function (Blueprint $table) {
            $table->dropColumn('tanggal_instalasi');
        });
    }
};
