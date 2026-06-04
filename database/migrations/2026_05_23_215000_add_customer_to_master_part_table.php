<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_part', function (Blueprint $table) {
            $table->string('customer', 100)->nullable()->after('part_no');
        });
    }

    public function down(): void
    {
        Schema::table('master_part', function (Blueprint $table) {
            $table->dropColumn('customer');
        });
    }
};
