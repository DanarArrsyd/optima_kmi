<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Penanda shift mana yang dihitung sebagai JAM TERJADWAL untuk Availability.
 *
 * Availability berpindah dari basis jam kalender 24/7 ke jam shift terjadwal.
 * Fakta "pabrik hanya menjalankan Shift 1 & 2" tidak ada di DB sebelum ini —
 * master_shift menyimpan tiga shift dan tidak ada cara membedakan yang benar
 * dijalankan dari yang cuma terdaftar. Tanpa kolom ini penyebutnya salah 8 jam
 * per hari.
 *
 * Alternatif yang DITOLAK: soft-delete Shift 3. LHP historis menunjuk shift itu
 * lewat foreign key; menghapusnya membuat laporan lama kehilangan nama shiftnya.
 *
 * Default TRUE, bukan false: kolom baru tidak boleh mengubah arti data yang
 * sudah ada tanpa ada yang memutuskan. Setelah migrasi, admin mematikan Shift 3
 * lewat Master > Shift.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_shift', function (Blueprint $table) {
            $table->boolean('dihitung_availability')
                ->default(true)
                ->after('jam_finish_sabtu');
        });
    }

    public function down(): void
    {
        Schema::table('master_shift', function (Blueprint $table) {
            $table->dropColumn('dihitung_availability');
        });
    }
};
