<?php

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

/**
 * Halaman "View Detail" adalah navigasi terpisah dari daftar. Tombol Detail di
 * daftar membawa query string daftar saat itu sebagai `back_query`, supaya
 * tombol Kembali mengembalikan user ke rentang tanggal / filter / halaman yang
 * sama — bukan ke bawaan bulan berjalan.
 *
 * Tujuan selalu route daftar milik modul itu sendiri; `back_query` hanya
 * menyumbang parameter query, jadi tidak bisa dipakai untuk redirect ke luar.
 */
trait UrlKembaliDaftar
{
    protected function urlKembaliDaftar(Request $request, string $routeDaftar): string
    {
        parse_str((string) $request->query('back_query', ''), $params);

        return route($routeDaftar, $params);
    }
}
