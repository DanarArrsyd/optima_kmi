<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        /* Step 1: rename std_dandori_menit → std_setting_dies_menit */
        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->renameColumn('std_dandori_menit', 'std_setting_dies_menit');
        });

        /* Step 2: add std_trial_qc_menit + drop CT columns */
        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->integer('std_trial_qc_menit')->default(0)->after('sph_standar_qc');
            $table->dropColumn(['cycle_time_standar_produksi', 'cycle_time_standar_qc']);
        });
    }

    public function down(): void
    {
        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->renameColumn('std_setting_dies_menit', 'std_dandori_menit');
        });

        Schema::table('master_part_proses', function (Blueprint $table) {
            $table->dropColumn('std_trial_qc_menit');
            $table->decimal('cycle_time_standar_produksi', 8, 2)->default(0);
            $table->decimal('cycle_time_standar_qc', 8, 2)->default(0);
        });
    }
};
