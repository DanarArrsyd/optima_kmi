<?php

namespace App\Contracts;

/**
 * Titik palsu untuk test: mysqldump tidak akan pernah jalan di CI, jadi seluruh
 * test memasang implementasi tiruan lewat antarmuka ini.
 */
interface PembuatDump
{
    /**
     * Menulis dump SQL penuh ke $tujuan. Melempar RuntimeException kalau gagal —
     * berkas separuh jadi tidak boleh dibiarkan.
     */
    public function keBerkas(string $tujuan): void;
}
