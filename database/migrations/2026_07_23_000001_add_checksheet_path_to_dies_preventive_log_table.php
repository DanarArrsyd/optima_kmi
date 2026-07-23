<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('dies_preventive_log', 'checksheet_path')) {
            Schema::table('dies_preventive_log', function (Blueprint $table) {
                $table->string('checksheet_path')->nullable()->after('catatan');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('dies_preventive_log', 'checksheet_path')) {
            Schema::table('dies_preventive_log', function (Blueprint $table) {
                $table->dropColumn('checksheet_path');
            });
        }
    }
};
