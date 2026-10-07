<?php

namespace App\Exports;

use App\Imports\ProduksiImport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterShift;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class ProduksiImportTemplate implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            'Data' => new ProduksiTemplateDataSheet,
            'Referensi' => new ProduksiTemplateReferensiSheet,
        ];
    }
}

/* ── Sheet 1: Data template ───────────────────────────────────────── */
class ProduksiTemplateDataSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return [
            'Tanggal Produksi',
            'Nama Shift',
            'Nama Operator',
            'ID Mesin',
            'Part No',
            'ID Proses',
            'Jam Start',
            'Jam Finish',
            'Qty Aktual',
            'Qty NG',
            'Kode Jenis NG',
        ];
    }

    public function array(): array
    {
        return [
            // Row 2: instruction row
            [
                '* Wajib — Format: YYYY-MM-DD atau DD/MM/YYYY',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Angka proses (1, 2, 3, ...)',
                '* Wajib — Format HH:MM',
                '* Wajib — Format HH:MM',
                '* Wajib — Min 1',
                'Opsional — Kosongkan jika tidak ada NG',
                'Opsional — Lihat sheet Referensi',
            ],
            // Baris contoh: sumbernya ProduksiImport::CONTOH, supaya importer bisa mengenali dan melewatinya.
            ...ProduksiImport::CONTOH,
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'K';

        /* Header row (row 1) */
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF374151']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        /* Instruction row (row 2) */
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF6B7280']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3F4F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);

        /* Sample data row (row 3) */
        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['argb' => 'FF9CA3AF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFAFAFA']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
        ]);

        /* Data area rows 4–200 */
        $sheet->getStyle("A4:{$lastCol}200")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        /* Freeze header + instruction rows */
        $sheet->freezePane('A4');

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22, 'B' => 18, 'C' => 28, 'D' => 12,
            'E' => 18, 'F' => 12, 'G' => 12, 'H' => 12,
            'I' => 12, 'J' => 10, 'K' => 18,
        ];
    }
}

/* ── Sheet 2: Referensi ───────────────────────────────────────────── */
class ProduksiTemplateReferensiSheet implements FromArray, WithColumnWidths, WithStyles
{
    public function array(): array
    {
        $rows = [];

        /* Section: Shift */
        $rows[] = ['REFERENSI NILAI — LHP PRODUKSI', '', '', '', ''];
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['SHIFT (kolom B)', '', '', '', ''];
        $rows[] = ['Nama Shift', 'Jam Start', 'Jam Finish', '', ''];
        foreach (MasterShift::orderBy('jam_start')->get() as $s) {
            $rows[] = [$s->nama_shift, $s->jam_start, $s->jam_finish, '', ''];
        }

        /* Section: Operator */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['OPERATOR / KARYAWAN (kolom C) — Departemen: Produksi', '', '', '', ''];
        $rows[] = ['Nama Karyawan', 'NIK', '', '', ''];
        foreach (MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get() as $k) {
            $rows[] = [$k->nama_karyawan, $k->nik ?? '—', '', '', ''];
        }

        /* Section: Mesin */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['MESIN (kolom D — gunakan ID Mesin)', '', '', '', ''];
        $rows[] = ['ID Mesin', 'Nama Mesin', 'Line', '', ''];
        foreach (MasterMesin::orderBy('line')->orderBy('id_mesin')->get() as $m) {
            $rows[] = [$m->id_mesin, $m->nama_mesin, $m->line, '', ''];
        }

        /* Section: Part + Proses */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['PART & PROSES (kolom E = Part No, kolom F = ID Proses)', '', '', '', ''];
        $rows[] = ['Part No', 'Nama Part', 'ID Proses', 'Nama Proses', 'SPH Std'];
        foreach (MasterPart::with('proses')->orderBy('part_no')->get() as $p) {
            foreach ($p->proses as $pr) {
                $rows[] = [$p->part_no, $p->part_name, $pr->id_proses, $pr->nama_proses, $pr->sph_standar_produksi];
            }
        }

        /* Section: Jenis NG */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['JENIS NG (kolom K — gunakan Kode)', '', '', '', ''];
        $rows[] = ['Kode', 'Nama NG', 'Deskripsi', '', ''];
        foreach (MasterJenisNg::orderBy('kode')->get() as $j) {
            $rows[] = [$j->kode, $j->nama_ng, $j->deskripsi ?? '', '', ''];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        /* Title */
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1F2937']],
        ]);

        /* Section headers detection — applied via loop would be complex,
           style the whole sheet minimally and let users read it */
        $sheet->getStyle('A1:E200')->applyFromArray([
            'font' => ['size' => 10],
        ]);

        return [];
    }

    public function columnWidths(): array
    {
        return ['A' => 22, 'B' => 30, 'C' => 18, 'D' => 28, 'E' => 14];
    }
}
