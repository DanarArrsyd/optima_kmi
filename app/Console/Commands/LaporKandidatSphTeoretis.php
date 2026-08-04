<?php

namespace App\Console\Commands;

use App\Services\SphTeoretisService;
use Illuminate\Console\Command;

/**
 * Laporan kandidat SPH teoretis per part+proses — READ-ONLY.
 *
 * Sengaja artisan command, bukan halaman web. Master Data sudah 7 anak dan
 * tingkat atas sidebar 9 baris: 9+7 = 16 = PLAFON_TERBUKA, dan sisa napas
 * sidebar 28px sementara satu baris anak 36px. Halaman baru beneran tidak muat.
 *
 * Dijalankan di prod lewat mode di .github/workflows/debug-log.yml, pola yang
 * sudah dipakai untuk probe prod lain. Aman di-merge karena nol tulisan ke DB —
 * mode yang MENULIS tidak boleh menetap di workflow itu.
 */
class LaporKandidatSphTeoretis extends Command
{
    protected $signature = 'optima:sph-teoretis
                            {--status= : Saring status: siap, curiga, kurang_data}
                            {--csv : Keluarkan CSV, bukan tabel}';

    protected $description = 'Usulkan SPH teoretis per part+proses dari LHP historis (read-only, untuk ditinjau)';

    public function handle(SphTeoretisService $svc): int
    {
        $baris = $svc->kandidat();

        if ($baris === []) {
            $this->warn('Belum ada LHP produksi — tidak ada yang bisa diusulkan.');

            return self::SUCCESS;
        }

        if ($status = $this->option('status')) {
            $baris = array_values(array_filter($baris, fn (array $b) => $b['status'] === $status));
        }

        $header = ['Part No', 'Proses', 'Target', 'Kandidat', 'Rasio', 'Bersih', 'Dibuang', 'Tgl', 'Status', 'Alasan'];

        $tabel = array_map(fn (array $b) => [
            $b['part_no'],
            $b['nama_proses'],
            $b['sph_target'],
            $b['kandidat'] ?? '—',
            $b['rasio'] ?? '—',
            $b['baris_bersih'],
            $b['baris_dibuang'],
            $b['tanggal_berbeda'],
            $b['status'],
            $b['alasan'],
        ], $baris);

        if ($this->option('csv')) {
            $this->line(implode(',', $header));
            foreach ($tabel as $t) {
                $this->line(implode(',', array_map(fn ($v) => '"'.str_replace('"', '""', (string) $v).'"', $t)));
            }
        } else {
            $this->table($header, $tabel);
        }

        $hitung = array_count_values(array_column($baris, 'status'));
        $this->newLine();
        $this->line(sprintf(
            'siap=%d  curiga=%d  kurang_data=%d  (total %d part+proses)',
            $hitung['siap'] ?? 0,
            $hitung['curiga'] ?? 0,
            $hitung['kurang_data'] ?? 0,
            count($baris)
        ));

        $this->newLine();
        $this->line('Kandidat = persentil '.SphTeoretisService::PERSENTIL.' SPH atas jam PRODUKTIF (setelah setup),');
        $this->line('dari baris LHP bukan-anomali (atau anomali yang sudah di-review admin).');
        $this->line('Minimum '.SphTeoretisService::MIN_BARIS.' baris dan '.SphTeoretisService::MIN_TANGGAL.' tanggal berbeda.');
        $this->line('Rasio > '.SphTeoretisService::MAKS_RASIO.'× target ditandai CURIGA — jangan dipakai tanpa dicek.');
        $this->newLine();
        $this->warn('Angka ini USULAN, bukan keputusan. Salah input yang KONSISTEN tidak tampak sebagai');
        $this->warn('outlier dan hanya tertangkap oleh orang yang tahu part-nya. Tinjau sebelum dipakai.');

        return self::SUCCESS;
    }
}
