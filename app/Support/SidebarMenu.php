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
     * Entri sidebar yang boleh dilihat sebuah role.
     *
     * Grup yang tidak menyisakan satu anak pun untuk role tersebut
     * dibuang seluruhnya — bukan dirender kosong.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function forRole(string $role): array
    {
        $hasil = [];

        foreach (config('sidebar', []) as $entri) {
            if (($entri['type'] ?? 'link') === 'link') {
                if (in_array($role, $entri['roles'], true)) {
                    $hasil[] = $entri;
                }

                continue;
            }

            $items = array_values(array_filter(
                $entri['items'],
                fn (array $item) => in_array($role, $item['roles'], true)
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
    public static function activeGroup(string $role): ?string
    {
        foreach (self::forRole($role) as $entri) {
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
