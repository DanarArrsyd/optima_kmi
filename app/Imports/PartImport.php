<?php

namespace App\Imports;

use App\Imports\Concerns\LewatiBarisContoh;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Support\SaneDate;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class PartImport implements SkipsEmptyRows, ToCollection
{
    use LewatiBarisContoh;

    /** Baris contoh template — dibaca juga oleh template-nya. Lihat {@see LewatiBarisContoh}. */
    public const CONTOH = [
        ['Toyota', 'BT-091A-3', 'Bracket RH', '2024-03-10', 1, 'PROGRESSIVE', 800, 400, 5, 15],
        ['Toyota', 'BT-091A-3', 'Bracket RH', '2024-03-10', 2, 'BENDING', 600, 300, 5, 15],
        ['Honda', 'HC-202B', 'Cover LH', '2023-11-20', 1, 'PROGRESSIVE', 750, 375, 5, 20],
    ];

    public int $imported = 0;

    public int $skipped = 0;

    /** Normalize header string → snake_case key */
    private function normalizeKey(string $raw): string
    {
        return strtolower(trim(preg_replace('/[\s\/\-]+/', '_', $raw)));
    }

    /** Cari nilai dari row berdasarkan beberapa kemungkinan key */
    private function get(Collection $row, array $keys): string
    {
        foreach ($keys as $key) {
            $val = trim((string) ($row[$key] ?? ''));
            if ($val !== '') {
                return $val;
            }
        }

        return '';
    }

    /**
     * Parse Excel date cell (serial number atau string) → Y-m-d, atau null.
     *
     * null berarti "tidak ada nilai yang bisa dipakai" — caller menafsirkannya sebagai
     * jangan-sentuh, bukan hapus. Batas bawah 1990 (bukan 2020 seperti tabel transaksi):
     * ini tanggal dies dipasang, bisa jauh lebih tua dari umur OPTIMA.
     */
    private function parseDate(string $raw): ?string
    {
        if (trim($raw) === '') {
            return null;
        }
        try {
            $parsed = is_numeric($raw)
                ? Date::excelToDateTimeObject((float) $raw)->format('Y-m-d')
                : Carbon::parse(trim($raw))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }

        return SaneDate::isSane($parsed, SaneDate::MIN_INSTALASI) ? $parsed : null;
    }

    public function collection(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        // Baris pertama = header, normalize semua key-nya
        $rawHeaders = $rows->first()->toArray();
        $headers = array_map(fn ($h) => $this->normalizeKey((string) $h), $rawHeaders);
        $dataRows = $rows->skip(1);

        foreach ($dataRows as $rawRow) {
            if ($this->barisContoh($rawRow)) {
                continue;
            }

            // Map kolom by normalized header
            $row = collect(array_combine($headers, $rawRow->toArray()));

            $partNo = $this->get($row, ['part_no', 'part_number', 'no_part', 'partno']);
            $partName = $this->get($row, ['part_name', 'nama_part', 'name', 'partname']);

            if ($partNo === '' || $partName === '') {
                $this->skipped++;

                continue;
            }

            /* Cari by part_no + part_name (unique bersama setelah constraint part_no saja dihapus).
             * Update customer jika sudah ada, restore jika soft-deleted. */
            $part = MasterPart::withTrashed()
                ->where('part_no', $partNo)
                ->where('part_name', $partName)
                ->first()
                    ?? new MasterPart(['part_no' => $partNo, 'part_name' => $partName]);
            // Kolom hilang / sel kosong tidak boleh menimpa nilai lama — record lama dimuat
            // ulang di sini. Record baru tetap diisi (customer nullable, '' aman).
            $customer = $this->get($row, ['customer', 'pelanggan', 'cust']);
            if ($customer !== '' || ! $part->exists) {
                $part->customer = $customer;
            }
            // Hanya set kalau ada nilai yang bisa dibaca — lihat catatan sama di MesinImport:
            // record lama dimuat ulang, jadi null di sini menghapus tanggal instalasi dies
            // yang sudah ada cuma karena kolomnya tidak ikut di file Excel.
            $tanggalInstalasi = $this->parseDate(
                $this->get($row, ['tanggal_instalasi', 'tgl_instalasi', 'instalasi', 'tanggal_instalasi_dies'])
            );
            if ($tanggalInstalasi !== null) {
                $part->tanggal_instalasi = $tanggalInstalasi;
            }
            $part->deleted_at = null;
            $part->save();

            /* Import the Proses row if id_proses + nama_proses present */
            $idProses = $this->get($row, ['id_proses', 'proses_id', 'id_process', 'no_proses']);
            $namaProses = $this->get($row, ['nama_proses', 'process_name', 'nama_process', 'proses']);

            if ($idProses === '' || $namaProses === '') {
                $this->imported++;

                continue;
            }

            $proses = MasterPartProses::withTrashed()->firstOrNew([
                'id_part' => $part->id,
                'id_proses' => (int) $idProses,
            ]);

            $sphProd = $this->get($row, ['sph_produksi', 'sph_prod', 'sph_production', 'sph']);
            $sphQc = $this->get($row, ['sph_qc', 'sph_quality', 'sph_q']);
            $trial = $this->get($row, ['std_trial_qc_menit', 'trial_qc', 'trial_menit', 'std_trial']);
            $setting = $this->get($row, ['std_setting_dies_menit', 'setting_dies', 'setting_menit', 'std_setting']);

            $proses->nama_proses = $namaProses;

            // Sel kosong tidak boleh menurunkan standar yang sudah benar jadi 0. SPH 0 tak
            // melempar error — DashboardService memfilter `sph_standar_produksi > 0`, jadi
            // part-nya cuma diam-diam hilang dari statistik achievement dan target_qty-nya
            // jadi 0. Record baru tetap dapat 0 seperti sebelumnya (default kolom).
            foreach ([
                'sph_standar_produksi' => [$sphProd, 'float'],
                'sph_standar_qc' => [$sphQc,   'float'],
                'std_trial_qc_menit' => [$trial,   'int'],
                'std_setting_dies_menit' => [$setting, 'int'],
            ] as $kolom => [$raw, $tipe]) {
                if (is_numeric($raw)) {
                    $proses->$kolom = $tipe === 'float' ? (float) $raw : (int) $raw;
                } elseif (! $proses->exists) {
                    $proses->$kolom = 0;
                }
            }

            $proses->deleted_at = null;
            $proses->save();

            $this->imported++;
        }
    }
}
