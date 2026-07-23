<?php

namespace App\Exports;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
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

class QcImportTemplate implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            'Data' => new QcTemplateDataSheet,
            'Referensi' => new QcTemplateReferensiSheet,
        ];
    }
}

/* ── Sheet 1: Data template ───────────────────────────────────────── */
class QcTemplateDataSheet implements FromArray, WithColumnWidths, WithHeadings, WithStyles
{
    public function headings(): array
    {
        return [
            'Tgl Pengecekan',
            'Tgl Produksi Part',
            'Nama Shift',
            'Nama Inspector',
            'Part No',
            'Jam Start',
            'Jam Finish',
            'Qty OK',
            'Qty NG',
            'Kode Jenis NG',
        ];
    }

    public function array(): array
    {
        return [
            // Row 2: instructions
            [
                '* Wajib — Format: YYYY-MM-DD atau DD/MM/YYYY',
                '* Wajib — Tanggal produksi part yang diinspeksi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi (dept QC)',
                '* Wajib — Proses finish good otomatis dipilih',
                '* Wajib — Format HH:MM',
                '* Wajib — Format HH:MM',
                '* Wajib — Min 0 (Qty OK + Qty NG harus > 0)',
                '* Wajib — Min 0',
                'Opsional — Lihat sheet Referensi',
            ],
            // Row 3: sample
            [
                '2026-01-15',
                '2026-01-14',
                'Shift 1',
                'Contoh Inspector',
                'BT-001',
                '08:00',
                '16:00',
                490,
                10,
                '',
            ],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'J';

        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font' => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF374151']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font' => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF6B7280']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3F4F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);

        $sheet->getStyle("A3:{$lastCol}3")->applyFromArray([
            'font' => ['italic' => true, 'size' => 10, 'color' => ['argb' => 'FF9CA3AF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFAFAFA']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
        ]);

        $sheet->getStyle("A4:{$lastCol}200")->applyFromArray([
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        $sheet->freezePane('A4');

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 22, 'B' => 22, 'C' => 18, 'D' => 28,
            'E' => 18, 'F' => 12, 'G' => 12, 'H' => 10,
            'I' => 10, 'J' => 18,
        ];
    }
}

/* ── Sheet 2: Referensi ───────────────────────────────────────────── */
class QcTemplateReferensiSheet implements FromArray, WithColumnWidths, WithStyles
{
    public function array(): array
    {
        $rows = [];

        $rows[] = ['REFERENSI NILAI — LHP QUALITY', '', '', '', ''];
        $rows[] = ['', '', '', '', ''];

        /* Shift */
        $rows[] = ['SHIFT (kolom C)', '', '', '', ''];
        $rows[] = ['Nama Shift', 'Jam Start', 'Jam Finish', '', ''];
        foreach (MasterShift::orderBy('jam_start')->get() as $s) {
            $rows[] = [$s->nama_shift, $s->jam_start, $s->jam_finish, '', ''];
        }

        /* QC Inspector */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['QC INSPECTOR (kolom D) — Departemen: QC', '', '', '', ''];
        $rows[] = ['Nama Inspector', 'NIK', '', '', ''];
        foreach (MasterKaryawan::where('departemen', 'QC')->orderBy('nama_karyawan')->get() as $k) {
            $rows[] = [$k->nama_karyawan, $k->nik ?? '—', '', '', ''];
        }

        /* Part — Finish Good */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['PART (kolom E) — Proses finish good dipilih otomatis', '', '', '', ''];
        $rows[] = ['Part No', 'Nama Part', 'Proses Finish Good', 'SPH Std QC', ''];
        foreach (MasterPart::with('proses')->orderBy('part_no')->get() as $p) {
            $fg = $p->proses->sortByDesc('id_proses')->first();
            if ($fg) {
                $rows[] = [$p->part_no, $p->part_name, "{$fg->id_proses} – {$fg->nama_proses}", $fg->sph_standar_qc, ''];
            }
        }

        /* Jenis NG */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['JENIS NG (kolom J — gunakan Kode)', '', '', '', ''];
        $rows[] = ['Kode', 'Nama NG', 'Deskripsi', '', ''];
        foreach (MasterJenisNg::orderBy('kode')->get() as $j) {
            $rows[] = [$j->kode, $j->nama_ng, $j->deskripsi ?? '', '', ''];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 12, 'color' => ['argb' => 'FF1F2937']],
        ]);
        $sheet->getStyle('A1:E200')->applyFromArray(['font' => ['size' => 10]]);

        return [];
    }

    public function columnWidths(): array
    {
        return ['A' => 22, 'B' => 30, 'C' => 28, 'D' => 14, 'E' => 14];
    }
}
