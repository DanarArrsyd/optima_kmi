<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->date('tanggal_rencana')->nullable()->change();
            $table->unsignedTinyInteger('minggu_ke')->nullable()->change();
            $table->date('backlog_since')->nullable()->after('minggu_ke');
        });
    }

    public function down(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->dropColumn('backlog_since');
            $table->date('tanggal_rencana')->nullable(false)->change();
            $table->unsignedTinyInteger('minggu_ke')->nullable(false)->change();
        });
    }
};
