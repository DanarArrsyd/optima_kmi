<?php

namespace App\Imports;

use App\Http\Requests\StoreRepairBatchRequest;
use App\Http\Requests\StoreRepairProduksiBatchRequest;
use App\Imports\Concerns\LewatiBarisContoh;
use App\Imports\Concerns\ParsesStrictImportDateTime;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Services\RepairProduksiWriteService;
use App\Services\RepairWriteService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Maatwebsite\Excel\Concerns\SkipsEmptyRows;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Concerns\WithStartRow;

/**
 * Impor Excel LHR Repair dan LHR Repair Produksi — satu kelas, dua jenis.
 *
 * Aturan per baris DIAMBIL dari FormRequest batch masing-masing ('rows.*.x'
 * → 'x'), ditambah aturan jam mulai ≠ jam selesai, lalu ditulis lewat
 * WriteService yang sama dengan input batch. Impor tidak boleh jadi pintu
 * belakang yang melonggarkan aturan input manual.
 *
 * Baris valid disimpan, baris salah dilaporkan per nomor baris Excel — pola
 * sama dengan impor LHP Produksi.
 */
class LhrImport implements SkipsEmptyRows, ToCollection, WithHeadingRow, WithStartRow
{
    use LewatiBarisContoh;
    use ParsesStrictImportDateTime;

    public const KOLOM = ['Tanggal', 'Shift', 'Operator', 'Part No', 'ID Proses', 'Jam Start', 'Jam Finish', 'Qty Act Repair'];

    public const KETERANGAN = [
        '* Wajib — YYYY-MM-DD atau DD/MM/YYYY',
        '* Wajib — nama shift (sheet Referensi)',
        '* Wajib — nama operator (sheet Referensi)',
        '* Wajib — Part No',
        '* Wajib — angka ID proses repair part itu',
        '* Wajib — HH:MM',
        '* Wajib — HH:MM, beda dari Jam Start',
        '* Wajib — minimal 1',
    ];

    /** Baris contoh template — dilewati selama belum diubah. */
    public const CONTOH = [
        ['2026-01-15', 'Shift 1', 'Contoh Operator', 'BT-001', '1', '08:00', '10:00', 120],
    ];

    private const JENIS = [
        'repair' => [
            'proses' => MasterPartProsesRepair::class,
            'fk' => 'id_part_proses_repair',
            'tanggal' => 'tanggal_repair',
            'request' => StoreRepairBatchRequest::class,
            'service' => RepairWriteService::class,
        ],
        'repair_produksi' => [
            'proses' => MasterPartProsesRepairProduksi::class,
            'fk' => 'id_part_proses_repair_produksi',
            'tanggal' => 'tanggal_repair_produksi',
            'request' => StoreRepairProduksiBatchRequest::class,
            'service' => RepairProduksiWriteService::class,
        ],
    ];

    public int $importedCount = 0;

    /** @var list<string> */
    public array $errors = [];

    private array $cfg;

    public function __construct(string $jenis)
    {
        abort_unless(isset(self::JENIS[$jenis]), 500, "Jenis LHR tak dikenal: {$jenis}");
        $this->cfg = self::JENIS[$jenis];
    }

    /** Baris 2 = keterangan pengisian, bukan data. */
    public function startRow(): int
    {
        return 3;
    }

    /** Aturan baris dari FormRequest batch, tanpa awalan 'rows.*.'. */
    private function aturan(): array
    {
        $aturan = [];
        foreach ((new $this->cfg['request'])->rules() as $kunci => $rule) {
            if (str_starts_with($kunci, 'rows.*.')) {
                $aturan[substr($kunci, 7)] = $rule;
            }
        }

        return $aturan;
    }

    public function collection(Collection $rows): void
    {
        $aturan = $this->aturan();
        $service = app($this->cfg['service']);

        foreach ($rows as $i => $row) {
            $nomor = $i + 3;
            if ($this->barisContoh($row)) {
                continue;
            }

            $galat = [];
            $namaShift = trim((string) ($row['shift'] ?? ''));
            $namaOp = trim((string) ($row['operator'] ?? ''));
            $partNo = trim((string) ($row['part_no'] ?? ''));
            $idProses = trim((string) ($row['id_proses'] ?? ''));

            $shift = $namaShift === '' ? null : MasterShift::whereRaw('LOWER(nama_shift) = ?', [mb_strtolower($namaShift)])->first();
            if ($namaShift !== '' && ! $shift) {
                $galat[] = "Shift \"{$namaShift}\" tidak ditemukan di master Shift";
            }
            $op = $namaOp === '' ? null : MasterKaryawan::whereRaw('LOWER(nama_karyawan) = ?', [mb_strtolower($namaOp)])->first();
            if ($namaOp !== '' && ! $op) {
                $galat[] = "Operator \"{$namaOp}\" tidak ditemukan di master Karyawan";
            }
            $part = $partNo === '' ? null : MasterPart::whereRaw('LOWER(TRIM(part_no)) = ?', [mb_strtolower($partNo)])->first();
            $proses = null;
            if ($partNo !== '' && ! $part) {
                $galat[] = "Part No \"{$partNo}\" tidak ditemukan di master Part";
            } elseif ($part && $idProses !== '') {
                $proses = $this->cfg['proses']::where('id_part', $part->id)->where('id_proses', $idProses)->first();
                if (! $proses) {
                    $galat[] = "Proses \"{$idProses}\" untuk Part No \"{$partNo}\" tidak ditemukan";
                }
            }

            $payload = [
                $this->cfg['tanggal'] => $this->parseDate($row['tanggal'] ?? null),
                'id_shift' => $shift?->id,
                'id_karyawan' => $op?->id,
                $this->cfg['fk'] => $proses?->id,
                'jam_start' => $this->parseTime($row['jam_start'] ?? null),
                'jam_finish' => $this->parseTime($row['jam_finish'] ?? null),
                'qty_aktual' => $row['qty_act_repair'] ?? null,
            ];

            if ($galat === []) {
                $v = Validator::make($payload, $aturan);
                $v->after(function ($v) use ($payload) {
                    if ($payload['jam_start'] && $payload['jam_start'] === $payload['jam_finish']) {
                        $v->errors()->add('jam_finish', 'jam mulai dan jam selesai tidak boleh sama.');
                    }
                });
                if ($v->fails()) {
                    $galat = $v->errors()->all();
                }
            }

            if ($galat !== []) {
                $this->errors[] = "Baris {$nomor}: ".implode('; ', $galat).'.';

                continue;
            }

            DB::transaction(fn () => $service->simpan($payload));
            $this->importedCount++;
        }
    }
}
