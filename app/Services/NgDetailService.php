<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Satu-satunya penulis baris detail NG dan kolom turunan `qty_ng`.
 *
 * Sengaja service eksplisit, bukan Eloquent observer: observer tidak jalan di
 * operasi bulk dan membuat efek samping tak terlihat dari controller. `qty_ng`
 * dibaca 16 file, jadi kapan nilainya berubah harus mudah dilacak.
 *
 * Invarian yang dijaga: qty_ng = SUM(detail.qty) untuk setiap baris yang pernah
 * melewati service ini.
 */
class NgDetailService
{
    /**
     * Kode dan skema tidak dijamin tiba bersamaan di produksi: rsync mendahului
     * `artisan migrate` di pipeline deploy. Semua jalur BACA tabel detail wajib
     * lewat gerbang ini supaya halaman tidak 500 di jendela antara keduanya.
     */
    public static function available(): bool
    {
        return Schema::hasTable('transaksi_produksi_ng')
            && Schema::hasTable('transaksi_qc_ng');
    }

    public function syncProduksi(TransaksiProduksi $trx, array $items): void
    {
        $this->sync($trx, $items, TransaksiProduksiNg::class, 'id_transaksi_produksi');
    }

    public function syncQc(TransaksiQc $trx, array $items): void
    {
        $this->sync($trx, $items, TransaksiQcNg::class, 'id_transaksi_qc');
    }

    private function sync(Model $trx, array $items, string $detailClass, string $foreignKey): void
    {
        $rows = $this->normalize($items);

        DB::transaction(function () use ($trx, $rows, $detailClass, $foreignKey) {
            $detailClass::where($foreignKey, $trx->getKey())->delete();

            foreach ($rows as $row) {
                $detailClass::create([
                    $foreignKey => $trx->getKey(),
                    'id_jenis_ng' => $row['id_jenis_ng'],
                    'qty' => $row['qty'],
                ]);
            }

            $trx->qty_ng = array_sum(array_column($rows, 'qty'));
            $trx->save();
        });
    }

    /**
     * Buang baris tanpa jenis atau tanpa qty, dan gabungkan qty bila jenis yang
     * sama muncul lebih dari sekali (validasi Request menolaknya lebih dulu
     * dengan pesan; ini jaring agar UNIQUE tidak pernah meledak dari sini).
     *
     * @return list<array{id_jenis_ng: int, qty: int}>
     */
    private function normalize(array $items): array
    {
        $merged = [];

        foreach ($items as $item) {
            $id = (int) ($item['id_jenis_ng'] ?? 0);
            $qty = (int) ($item['qty'] ?? 0);

            if ($id <= 0 || $qty <= 0) {
                continue;
            }

            $merged[$id] = ($merged[$id] ?? 0) + $qty;
        }

        $rows = [];
        foreach ($merged as $id => $qty) {
            $rows[] = ['id_jenis_ng' => $id, 'qty' => $qty];
        }

        return $rows;
    }
}
