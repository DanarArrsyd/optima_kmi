<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->boolean('is_manual_override')->default(false)->after('backlog_since');
            $table->foreignId('overridden_by')->nullable()->after('is_manual_override')->constrained('users');
            $table->timestamp('overridden_at')->nullable()->after('overridden_by');
        });
    }

    public function down(): void
    {
        Schema::table('preventive_schedule_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('overridden_by');
            $table->dropColumn(['is_manual_override', 'overridden_at']);
        });
    }
};
