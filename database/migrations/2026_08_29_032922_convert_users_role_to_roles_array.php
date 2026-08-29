<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * User dulu punya SATU role (enum). Sekarang bisa punya banyak — akun admin
 * yang juga staff QC tidak perlu dua akun terpisah.
 *
 * `roles` JSON, backfill dari `role` lama, lalu `role` DIHAPUS — bukan
 * dipertahankan berdampingan. Dua kolom yang mengklaim jadi sumber
 * kebenaran yang sama sudah berkali-kali menyimpang di repo ini (lihat
 * std_menit dandori, PR terpisah) tanpa ketahuan sampai datanya beda.
 * UserFactory yang menerjemahkan `role` lama jadi `roles` supaya ratusan
 * test yang sudah ada tidak perlu disentuh satu per satu.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('roles')->nullable()->after('role');
        });

        DB::table('users')->select('id', 'role')->orderBy('id')->chunkById(200, function ($baris) {
            foreach ($baris as $b) {
                DB::table('users')->where('id', $b->id)->update([
                    'roles' => json_encode([$b->role]),
                ]);
            }
        });

        /* roles dibiarkan nullable di skema — "minimal satu role" dijaga
         * validasi (StoreMasterUserRequest), bukan constraint DB. JSON
         * NOT NULL dengan default yang portabel lintas MySQL/SQLite
         * menambah risiko tanpa manfaat sepadan di sini. */
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('role', ['admin', 'staff_produksi', 'staff_qc', 'manager', 'staff_maintenance', 'guest'])
                ->default('staff_produksi')->after('email');
        });

        /* Role pertama di array yang menang kalau harus mundur ke satu
         * role — mundurnya rencana ini SENGAJA lossy, sama seperti setiap
         * rollback yang menyempitkan kardinalitas data. */
        DB::table('users')->select('id', 'roles')->orderBy('id')->chunkById(200, function ($baris) {
            foreach ($baris as $b) {
                $roles = json_decode($b->roles, true) ?: ['staff_produksi'];
                DB::table('users')->where('id', $b->id)->update(['role' => $roles[0]]);
            }
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('roles');
        });
    }
};
