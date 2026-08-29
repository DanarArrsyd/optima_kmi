<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * LogAktivitasController SELALU filter rentang tanggal (whereBetween created_at)
 * tiap buka halaman — nol index di sini berarti full table scan, makin lambat
 * makin gede tabelnya. Tabel ini sekarang nyatet SEMUA transaksi (bukan cuma
 * Master Data), jadi tumbuhnya cepat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['created_at']);
        });
    }
};
