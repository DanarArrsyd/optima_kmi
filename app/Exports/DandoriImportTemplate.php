<?php
namespace App\Exports;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
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

class DandoriImportTemplate implements WithMultipleSheets
{
    public function sheets(): array
    {
        return [
            'Data'      => new DandoriTemplateDataSheet(),
            'Referensi' => new DandoriTemplateReferensiSheet(),
        ];
    }
}

/* ── Sheet 1: Data template ──────────────────────────────────────── */
class DandoriTemplateDataSheet implements FromArray, WithHeadings, WithStyles, WithColumnWidths
{
    public function headings(): array
    {
        return [
            'Tanggal',
            'Nama Shift',
            'Nama Operator',
            'ID Mesin',
            'Part No',
            'ID Proses',
            'Kode Loss Time',
            'Actual Menit',
        ];
    }

    public function array(): array
    {
        return [
            /* Row 2: petunjuk pengisian */
            [
                '* Wajib — Format: YYYY-MM-DD atau DD/MM/YYYY',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Angka ID proses (1, 2, 3, ...)',
                '* Wajib — Lihat sheet Referensi',
                '* Wajib — Desimal diizinkan, contoh: 15.5',
            ],
            /* Row 3: contoh sesi (2 loss time, 1 sesi) */
            ['2026-01-15', 'Shift 1', 'Contoh Operator', 'A1', 'BT-001', '1', 'SD', 15],
            ['2026-01-15', 'Shift 1', 'Contoh Operator', 'A1', 'BT-001', '1', 'GB', 8],
            /* Row 5: contoh sesi lain */
            ['2026-01-16', 'Shift 2', 'Operator Lain',   'B2', 'BT-002', '2', 'SD', 12],
        ];
    }

    public function styles(Worksheet $sheet): array
    {
        $lastCol = 'H';

        /* Header row 1 */
        $sheet->getStyle("A1:{$lastCol}1")->applyFromArray([
            'font'      => ['bold' => true, 'size' => 10, 'color' => ['argb' => 'FFFFFFFF']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF1F2937']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF374151']]],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(22);

        /* Petunjuk row 2 */
        $sheet->getStyle("A2:{$lastCol}2")->applyFromArray([
            'font'      => ['italic' => true, 'size' => 8, 'color' => ['argb' => 'FF6B7280']],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFF3F4F6']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT, 'wrapText' => true],
        ]);
        $sheet->getRowDimension(2)->setRowHeight(30);

        /* Contoh data rows 3-5 */
        $sheet->getStyle("A3:{$lastCol}5")->applyFromArray([
            'font'    => ['italic' => true, 'size' => 10, 'color' => ['argb' => 'FF9CA3AF']],
            'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFFAFAFA']],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
        ]);

        /* Garis pemisah tipis antar sesi contoh (baris 5) */
        $sheet->getStyle("A5:{$lastCol}5")->applyFromArray([
            'borders' => ['top' => ['borderStyle' => Border::BORDER_MEDIUM, 'color' => ['argb' => 'FFD1D5DB']]],
        ]);

        /* Area data kosong rows 6–500 */
        $sheet->getStyle("A6:{$lastCol}500")->applyFromArray([
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFE5E7EB']]],
            'alignment' => ['vertical' => Alignment::VERTICAL_CENTER],
        ]);

        /* Freeze baris 1-2 (header + petunjuk) */
        $sheet->freezePane('A3');

        return [];
    }

    public function columnWidths(): array
    {
        return [
            'A' => 26, // Tanggal
            'B' => 18, // Shift
            'C' => 28, // Operator
            'D' => 12, // ID Mesin
            'E' => 16, // Part No
            'F' => 12, // ID Proses
            'G' => 18, // Kode Loss Time
            'H' => 16, // Actual Menit
        ];
    }
}

/* ── Sheet 2: Referensi ──────────────────────────────────────────── */
class DandoriTemplateReferensiSheet implements FromArray, WithStyles, WithColumnWidths
{
    public function array(): array
    {
        $rows = [];

        /* Judul */
        $rows[] = ['REFERENSI NILAI — IMPORT DANDORI', '', '', '', ''];
        $rows[] = ['Gunakan nilai persis seperti yang tertera di bawah ini.', '', '', '', ''];
        $rows[] = ['', '', '', '', ''];

        /* Shift */
        $rows[] = ['SHIFT (kolom B — Nama Shift)', '', '', '', ''];
        $rows[] = ['Nama Shift', 'Jam Start', 'Jam Finish', '', ''];
        foreach (MasterShift::orderBy('jam_start')->get() as $s) {
            $rows[] = [$s->nama_shift, $s->jam_start, $s->jam_finish, '', ''];
        }

        /* Operator */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['OPERATOR (kolom C — Nama Operator) — Departemen: Produksi', '', '', '', ''];
        $rows[] = ['Nama Karyawan', 'NIK', '', '', ''];
        foreach (MasterKaryawan::where('departemen', 'Produksi')->orderBy('nama_karyawan')->get() as $k) {
            $rows[] = [$k->nama_karyawan, $k->nik ?? '—', '', '', ''];
        }

        /* Mesin */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['MESIN (kolom D — ID Mesin)', '', '', '', ''];
        $rows[] = ['ID Mesin', 'Nama Mesin', 'Line', '', ''];
        foreach (MasterMesin::orderBy('line')->orderBy('id_mesin')->get() as $m) {
            $rows[] = [$m->id_mesin, $m->nama_mesin, $m->line, '', ''];
        }

        /* Part + Proses */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['PART & PROSES (kolom E = Part No, kolom F = ID Proses)', '', '', '', ''];
        $rows[] = ['Part No', 'Nama Part', 'ID Proses', 'Nama Proses', 'Keterangan'];
        foreach (MasterPart::with('proses')->orderBy('part_no')->get() as $p) {
            foreach ($p->proses as $pr) {
                $isProgressive = strtoupper(trim($pr->nama_proses)) === 'PROGRESSIVE';
                $rows[] = [
                    $p->part_no,
                    $p->part_name,
                    $pr->id_proses,
                    $pr->nama_proses,
                    $isProgressive ? '★ Progressive — gunakan std_menit_progressive' : '',
                ];
            }
        }

        /* Loss Time */
        $rows[] = ['', '', '', '', ''];
        $rows[] = ['LOSS TIME (kolom G — Kode Loss Time)', '', '', '', ''];
        $rows[] = ['Kode', 'Jenis Loss Time', 'Std Default (mnt)', 'Std Progressive (mnt)', ''];
        foreach (MasterLosstime::orderBy('kode')->get() as $lt) {
            $rows[] = [
                $lt->kode,
                $lt->jenis_losstime,
                $lt->std_menit_default   ?? '—',
                $lt->std_menit_progressive ?? '—',
                '',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet): array
    {
        /* Judul */
        $sheet->getStyle('A1')->applyFromArray([
            'font' => ['bold' => true, 'size' => 13, 'color' => ['argb' => 'FF1F2937']],
        ]);
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['italic' => true, 'size' => 9, 'color' => ['argb' => 'FF6B7280']],
        ]);

        /* Global font */
        $sheet->getStyle('A1:E300')->applyFromArray([
            'font' => ['size' => 10],
        ]);

        return [];
    }

    public function columnWidths(): array
    {
        return ['A' => 20, 'B' => 32, 'C' => 22, 'D' => 26, 'E' => 40];
    }
}
