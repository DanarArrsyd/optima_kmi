<?php

namespace App\Exports;

use App\Imports\LhrImport;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Template impor LHR Repair / LHR Repair Produksi. Judul, keterangan, dan
 * contoh dibaca dari LhrImport — template dan importer tidak bisa melenceng.
 * Baris 2 keterangan (dilewati importer), baris 3 contoh (dilewati selama
 * belum diubah).
 */
class LhrImportTemplate implements FromArray, ShouldAutoSize, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return LhrImport::KOLOM;
    }

    public function array(): array
    {
        return [LhrImport::KETERANGAN, ...LhrImport::CONTOH];
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1:H1')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('E5E7EB');
        $sheet->getStyle('A2:H2')->getFont()->setItalic(true)->setSize(8);
        $sheet->freezePane('A3');

        return [1 => ['font' => ['bold' => true]]];
    }
}
