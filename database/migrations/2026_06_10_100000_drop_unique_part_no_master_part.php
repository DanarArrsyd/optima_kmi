<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Hapus unique index single-column pada master_part.part_no.
     *
     * Satu part_no bisa memiliki beberapa varian part_name
     * (mis. 55112-17000 "BEAM INNER LH" dan "BEAM INNER RH").
     * Unique pada part_no saja menyebabkan varian kedua tertolak/ketimpa.
     * Identitas part kini = kombinasi (part_no, part_name).
     */
    public function up(): void
    {
        Schema::table('master_part', function (Blueprint $table) {
            $table->dropUnique('master_part_part_no_unique');
        });
    }

    public function down(): void
    {
        Schema::table('master_part', function (Blueprint $table) {
            $table->unique('part_no');
        });
    }
};
