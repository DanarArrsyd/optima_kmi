<?php

namespace App\Support;

/**
 * Penyaringan menu sidebar per role dan deteksi halaman aktif.
 *
 * Deteksi aktif memakai NAMA ROUTE, bukan prefiks URI. Pola prefiks lama
 * (`str_starts_with($uri, 'vcb/ng-proses')`) juga cocok dengan
 * `vcb/ng-proses/daftar` dan sudah tiga kali menyalakan dua entri
 * sekaligus. Nama route unik, jadi kelas bug itu hilang.
 */
class SidebarMenu
{
    /**
     * Entri sidebar yang boleh dilihat sebuah user — user bisa punya
     * BANYAK role, jadi cocok kalau SALAH SATU role-nya ada di daftar
     * 'roles' entri (array_intersect), bukan menuntut satu kecocokan
     * tunggal seperti dulu.
     *
     * Grup yang tidak menyisakan satu anak pun untuk role-role tersebut
     * dibuang seluruhnya — bukan dirender kosong.
     *
     * @param  array<int, string>  $roles
     * @return array<int, array<string, mixed>>
     */
    public static function forRoles(array $roles): array
    {
        $hasil = [];

        foreach (config('sidebar', []) as $entri) {
            if (($entri['type'] ?? 'link') === 'link') {
                if (array_intersect($roles, $entri['roles']) !== []) {
                    $hasil[] = $entri;
                }

                continue;
            }

            $items = array_values(array_filter(
                $entri['items'],
                fn (array $item) => array_intersect($roles, $item['roles']) !== []
            ));

            if ($items !== []) {
                $entri['items'] = $items;
                $hasil[] = $entri;
            }
        }

        return $hasil;
    }

    /** Apakah entri ini menunjuk halaman yang sedang dibuka. */
    public static function isActive(array $item): bool
    {
        return request()->routeIs(...$item['match']);
    }

    /**
     * Key grup yang memuat halaman sekarang.
     *
     * Diturunkan dari anaknya, bukan dari peta terpisah — peta terpisah
     * bisa melenceng dari daftar item tanpa ketahuan.
     */
    /** @param  array<int, string>  $roles */
    public static function activeGroup(array $roles): ?string
    {
        foreach (self::forRoles($roles) as $entri) {
            if (($entri['type'] ?? 'link') !== 'group') {
                continue;
            }

            foreach ($entri['items'] as $item) {
                if (self::isActive($item)) {
                    return $entri['key'];
                }
            }
        }

        return null;
    }
}
