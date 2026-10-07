<?php

namespace App\Imports;

use App\Imports\Concerns\LewatiBarisContoh;
use App\Models\MasterJenisNg;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class JenisNgImport implements SkipsEmptyRows, ToCollection, WithHeadingRow
{
    use LewatiBarisContoh;

    /** Judul kolom template impor — dibaca juga oleh template & ekspor halaman, supaya
     *  ekspor → sunting di Excel → impor balik selalu cocok. */
    public const KOLOM = ['Kode', 'Nama NG', 'Deskripsi'];

    /** Baris contoh template — dibaca juga oleh template-nya. Lihat {@see LewatiBarisContoh}. */
    public const CONTOH = [
        ['DIM', 'Dimensi Over/Under', 'Ukuran tidak sesuai spesifikasi'],
        ['BURR', 'Burr/Flash', 'Sisa material pada tepi produk'],
        ['SCRATCH', 'Goresan', 'Permukaan tergores'],
        ['DENT', 'Penyok', 'Permukaan penyok/deformasi'],
        ['CRACK', 'Retak', 'Material retak/pecah'],
    ];

    public int $imported = 0;

    public int $skipped = 0;

    public function collection(Collection $rows): void
    {
        foreach ($rows as $row) {
            if ($this->barisContoh($row)) {
                continue;
            }

            $kode = trim((string) ($row['kode'] ?? ''));
            $nama = trim((string) ($row['nama_ng'] ?? ''));
            if ($kode === '' || $nama === '') {
                $this->skipped++;

                continue;
            }

            $record = MasterJenisNg::withTrashed()->firstOrNew(['kode' => $kode]);
            $record->nama_ng = $nama;
            $record->deskripsi = trim((string) ($row['deskripsi'] ?? '')) ?: null;
            $record->deleted_at = null;
            $record->save();
            $this->imported++;
        }
    }
}
