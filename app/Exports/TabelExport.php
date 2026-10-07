<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Ekspor tabel generik: judul kolom + baris yang SUDAH jadi.
 *
 * Dipakai halaman yang isinya dihitung service (VCB, analitik) — controller
 * mengambil baris dari method service yang sama dengan endpoint JSON layarnya,
 * lalu memetakannya ke kolom. Dengan begitu isi file selalu sama dengan yang
 * tampil, tidak ada rumus kedua yang bisa melenceng.
 */
class TabelExport implements FromArray, ShouldAutoSize, WithHeadings, WithStyles, WithTitle
{
    /**
     * @param  list<string>  $judulKolom
     * @param  list<list<mixed>>  $baris
     */
    public function __construct(
        private array $judulKolom,
        private array $baris,
        private string $namaSheet = 'Data',
    ) {}

    public function array(): array
    {
        return $this->baris;
    }

    public function headings(): array
    {
        return $this->judulKolom;
    }

    public function title(): string
    {
        // Excel menolak nama sheet > 31 karakter.
        return mb_substr($this->namaSheet, 0, 31);
    }

    public function styles(Worksheet $sheet): array
    {
        $kolomTerakhir = Coordinate::stringFromColumnIndex(max(1, count($this->judulKolom)));

        $sheet->getStyle("A1:{$kolomTerakhir}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E5E7EB');
        $sheet->freezePane('A2');

        return [1 => ['font' => ['bold' => true]]];
    }
}
