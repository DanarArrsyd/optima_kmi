<?php

namespace App\Exports;

use App\Services\NgListService;
use Illuminate\Support\Enumerable;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Export Daftar NG. Mengambil baris lewat {@see NgListService::rows()} — satu
 * sumber kebenaran dengan yang tampil di layar, jadi export selalu memakai filter
 * yang sedang aktif, bukan seluruh tabel.
 *
 * Kolom mesin hanya ada di sisi Produksi: `transaksi_qc` tidak punya `id_mesin`.
 */
class NgListExport implements FromCollection, WithColumnWidths, WithHeadings, WithStyles
{
    public function __construct(
        private string $sumber,
        private array $filter,
        private ?NgListService $svc = null,
    ) {
        $this->svc ??= new NgListService;
    }

    private function punyaMesin(): bool
    {
        return $this->sumber === 'produksi';
    }

    public function collection(): Enumerable
    {
        return $this->svc->rows($this->sumber, $this->filter)->map(function ($r) {
            $baris = [$r->tanggal, $r->shift];

            if ($this->punyaMesin()) {
                $baris[] = $r->mesin;
            }

            return array_merge($baris, [
                $r->customer,
                $r->part_no,
                $r->part_name,
                $r->proses,
                $r->orang,
                $r->kode_ng,
                $r->nama_ng,
                $r->qty,
            ]);
        });
    }

    public function headings(): array
    {
        $head = ['Tanggal', 'Shift'];

        if ($this->punyaMesin()) {
            $head[] = 'Mesin';
        }

        return array_merge($head, [
            'Customer',
            'Part No',
            'Part Name',
            'Proses',
            $this->punyaMesin() ? 'Operator' : 'Inspector',
            'Kode NG',
            'Jenis NG',
            'Qty NG',
        ]);
    }

    public function columnWidths(): array
    {
        $lebar = $this->punyaMesin()
            ? ['A' => 12, 'B' => 10, 'C' => 14, 'D' => 16, 'E' => 18, 'F' => 24, 'G' => 16, 'H' => 20, 'I' => 10, 'J' => 22, 'K' => 10]
            : ['A' => 12, 'B' => 10, 'C' => 16, 'D' => 18, 'E' => 24, 'F' => 16, 'G' => 20, 'H' => 10, 'I' => 22, 'J' => 10];

        return $lebar;
    }

    public function styles(Worksheet $sheet): array
    {
        $kolomTerakhir = $this->punyaMesin() ? 'K' : 'J';

        $sheet->getStyle("A1:{$kolomTerakhir}1")->getFill()
            ->setFillType(Fill::FILL_SOLID)
            ->getStartColor()->setRGB('E5E7EB');

        return [
            1 => ['font' => ['bold' => true]],
        ];
    }
}
