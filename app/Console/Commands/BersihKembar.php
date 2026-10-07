<?php

namespace App\Console\Commands;

use App\Services\PembersihKembar;
use Illuminate\Console\Command;

/**
 * Bersihkan baris transaksi kembar lama (lihat PembersihKembar).
 *
 * Tanpa --terapkan cuma mencetak rencana (dry-run, nol tulis). Dengan
 * --terapkan baris di-soft delete dan id-nya ditulis ke
 * storage/app/bersih-kembar/*.json; --pulihkan=<berkas> membatalkannya.
 *
 *   php artisan optima:bersih-kembar --simpan=breakdown_dies:149
 *   php artisan optima:bersih-kembar --simpan=breakdown_dies:149 --terapkan
 *   php artisan optima:bersih-kembar --pulihkan=bersih-kembar/20261007-170000.json
 */
class BersihKembar extends Command
{
    protected $signature = 'optima:bersih-kembar
        {--simpan=* : modul:id yang wajib disimpan di grupnya (mis. breakdown_dies:149)}
        {--terapkan : benar-benar soft delete (default dry-run)}
        {--pulihkan= : berkas JSON hasil --terapkan yang mau dibatalkan}';

    protected $description = 'Soft delete baris transaksi kembar lama (dry-run kecuali --terapkan)';

    public function handle(PembersihKembar $pembersih): int
    {
        if ($berkas = $this->option('pulihkan')) {
            $this->info('Dipulihkan: '.$pembersih->pulihkan($berkas).' baris.');

            return self::SUCCESS;
        }

        $paksa = [];
        foreach ($this->option('simpan') as $s) {
            [$modul, $id] = array_pad(explode(':', $s, 2), 2, null);
            $paksa[$modul][] = (int) $id;
        }

        $rencana = $pembersih->rencana($paksa);

        foreach ($rencana as $modul => $r) {
            $this->line(sprintf('=== %s: hapus %d, ragu (tidak dihapus) %d%s', $modul, count($r['hapus']), count($r['ragu']),
                $r['paksa'] ? ', simpan paksa #'.implode(', #', $r['paksa']) : ''));
            foreach ($r['ragu'] as $ket) {
                $this->line("  RAGU  {$ket}");
            }
            foreach ($r['hapus'] as $ket) {
                $this->line("  hapus {$ket}");
            }
        }
        $total = array_sum(array_map(fn ($r) => count($r['hapus']), $rencana));
        $this->line("TOTAL hapus: {$total}");

        if (! $this->option('terapkan')) {
            $this->warn('DRY-RUN — tidak ada yang ditulis. Tambah --terapkan untuk menjalankan.');

            return self::SUCCESS;
        }

        $path = $pembersih->terapkan($rencana);
        $this->info("Soft delete {$total} baris. Pemulih: php artisan optima:bersih-kembar --pulihkan={$path}");

        return self::SUCCESS;
    }
}
