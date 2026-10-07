<?php

namespace App\Services;

use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\TransaksiRepair;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Deteksi duplikat (natural key) sebelum baris transaksi ditulis.
 *
 * Kasusnya: admin meninggalkan meja, kembali, lupa batch mana yang sudah
 * dikirim, lalu mengirimnya lagi. Kunci kirim-ganda di layout tidak menangkap
 * ini — jaraknya menit sampai jam. Probe prod 7 Okt 2026: 147 baris LHP dan
 * 728 baris dandori kembar, termasuk satu batch utuh yang masuk dua kali.
 *
 * Sengaja BUKAN unique index: prod sudah berisi kembar (migrasi akan meledak),
 * SoftDeletes membuat baris terhapus ikut menghalangi, dan ada kembar yang
 * sah — pemanggil boleh melewati peringatan ini ("Tetap simpan").
 *
 * KUNCI per modul = kolom yang menandai satu kejadian nyata. Angka hasil (qty)
 * sengaja tidak ikut, supaya input ulang dengan qty salah ketik tetap
 * tertangkap — kecuali dandori: tanpa jam, dua sesi loss time sejenis di shift
 * yang sama itu sah, jadi menitnya ikut membedakan.
 */
class PenjagaKembar
{
    public const KUNCI = [
        'produksi' => [TransaksiProduksi::class, [
            'tanggal_produksi' => 'tanggal', 'id_shift' => 'id', 'id_karyawan' => 'id', 'id_mesin' => 'id',
            'id_part_proses' => 'id', 'jam_start' => 'jam', 'jam_finish' => 'jam',
        ]],
        'qc' => [TransaksiQc::class, [
            'tgl_pengecekan' => 'tanggal', 'tgl_produksi_part' => 'tanggal', 'id_shift' => 'id', 'id_karyawan_qc' => 'id',
            'id_part_proses' => 'id', 'jam_start' => 'jam', 'jam_finish' => 'jam',
        ]],
        'dandori' => [TransaksiDandoriRincian::class, [
            'tanggal' => 'tanggal', 'id_shift' => 'id', 'id_karyawan' => 'id', 'id_mesin' => 'id',
            'id_part_proses' => 'id', 'id_losstime' => 'id', 'actual_menit' => 'angka',
        ]],
        'breakdown_mesin' => [TransaksiBreakdownMesin::class, [
            'id_mesin' => 'id', 'tanggal_breakdown' => 'tanggal', 'jam_breakdown' => 'jam',
        ]],
        'breakdown_dies' => [TransaksiBreakdownDies::class, [
            'id_part_proses' => 'id', 'tanggal_breakdown' => 'tanggal', 'jam_breakdown' => 'jam',
        ]],
        'repair' => [TransaksiRepair::class, [
            'tanggal_repair' => 'tanggal', 'id_shift' => 'id', 'id_karyawan' => 'id', 'id_part_proses_repair' => 'id',
            'jam_start' => 'jam', 'jam_finish' => 'jam',
        ]],
        'repair_produksi' => [TransaksiRepairProduksi::class, [
            'tanggal_repair_produksi' => 'tanggal', 'id_shift' => 'id', 'id_karyawan' => 'id',
            'id_part_proses_repair_produksi' => 'id', 'jam_start' => 'jam', 'jam_finish' => 'jam',
        ]],
    ];

    /** Baris tersimpan (belum dihapus) yang identik dengan $baris, atau null. */
    public function cari(string $modul, array $baris): ?Model
    {
        [$model, $kolom] = self::KUNCI[$modul];

        $q = $model::query();
        foreach ($kolom as $nama => $jenis) {
            $nilai = $baris[$nama] ?? null;
            if ($nilai === null || $nilai === '') {
                return null;
            }
            $this->saring($q, $nama, $jenis, $nilai);
        }

        return $q->oldest('id')->first();
    }

    /**
     * Periksa satu batch. Mengembalikan galat per baris dengan kunci
     * `rows.{i}.kembar` — bentuk yang sudah dibaca batchDraft() untuk
     * menandai baris draft merah. Kosong = aman ditulis.
     *
     * @param  callable|null  $pecah  baris batch → daftar baris tabel (dandori: satu sesi = N rincian)
     * @return array<string, string>
     */
    public function periksaBatch(string $modul, array $rows, ?callable $pecah = null): array
    {
        $pecah ??= fn (array $r) => [$r];
        $galat = [];
        $dilihat = [];

        foreach ($rows as $i => $row) {
            $no = $i + 1;
            foreach ($pecah($row) as $baris) {
                $sidik = $this->sidik($modul, $baris);
                if ($sidik === null) {
                    continue;
                }

                if (isset($dilihat[$sidik])) {
                    $galat["rows.{$i}.kembar"] = "Baris {$no} kembar dengan baris {$dilihat[$sidik]} di draft ini.";
                    break;
                }
                $dilihat[$sidik] = $no;

                if ($ada = $this->cari($modul, $baris)) {
                    $galat["rows.{$i}.kembar"] = "Baris {$no} identik dengan data yang sudah tersimpan ".$this->jejak($ada).'.';
                    break;
                }
            }
        }

        return $galat;
    }

    /** "(ID 123, diinput 06-10-2026 10:37 WIB oleh Admin)" — cukup untuk mencarinya di daftar. */
    public function jejak(Model $ada): string
    {
        $bagian = ["ID {$ada->getKey()}"];
        if ($ada->created_at) {
            $bagian[] = 'diinput '.Carbon::parse($ada->created_at)->timezone('Asia/Jakarta')->format('d-m-Y H:i').' WIB';
        }
        if ($ada->created_by && ($nama = User::whereKey($ada->created_by)->value('name'))) {
            $bagian[] = "oleh {$nama}";
        }

        return '('.implode(', ', $bagian).')';
    }

    private function sidik(string $modul, array $baris): ?string
    {
        $isi = [];
        foreach (self::KUNCI[$modul][1] as $nama => $jenis) {
            $nilai = $baris[$nama] ?? null;
            if ($nilai === null || $nilai === '') {
                return null;
            }
            $isi[] = $this->normal($jenis, $nilai);
        }

        return implode('|', $isi);
    }

    private function saring(Builder $q, string $nama, string $jenis, mixed $nilai): void
    {
        $normal = $this->normal($jenis, $nilai);
        match ($jenis) {
            'tanggal' => $q->whereDate($nama, $normal),
            // Kolom TIME di MySQL kembali "07:00:00", input form "07:00".
            'jam' => $q->whereIn($nama, [$normal, "{$normal}:00"]),
            'angka' => $q->whereBetween($nama, [(float) $normal - 0.001, (float) $normal + 0.001]),
            default => $q->where($nama, $normal),
        };
    }

    private function normal(string $jenis, mixed $nilai): string
    {
        return match ($jenis) {
            'tanggal' => Carbon::parse($nilai)->toDateString(),
            'jam' => preg_match('/^(\d{1,2}):(\d{2})/', (string) $nilai, $m) ? sprintf('%02d:%s', $m[1], $m[2]) : (string) $nilai,
            'angka' => (string) round((float) $nilai, 2),
            default => (string) $nilai,
        };
    }
}
