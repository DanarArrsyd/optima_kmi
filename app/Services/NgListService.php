<?php

namespace App\Services;

use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Collection;

/**
 * Daftar baris NG per jenis untuk dua halaman: Daftar NG Proses (sisi produksi)
 * dan Daftar NG Total (sisi quality). Satu baris = satu jenis NG dalam satu sesi.
 *
 * Terpisah dari {@see NgDashboardService} dengan sengaja: yang itu agregasi per
 * bulan, yang ini query terfilter berpaginasi. Tanggung jawab berbeda.
 *
 * Query BERANGKAT DARI MODEL INDUK, bukan dari tabel detail, supaya global scope
 * SoftDeletes induk berlaku otomatis — sesi yang dihapus tidak boleh muncul. Tabel
 * detail di-join untuk memecah satu sesi menjadi satu baris per jenis NG.
 *
 * Join ke tabel master sengaja join MENTAH (tanpa scope SoftDeletes): jenis NG atau
 * part yang sudah dihapus tetap harus menampilkan baris NG historisnya, bukan
 * membuat barisnya lenyap dari daftar.
 */
class NgListService
{
    /**
     * @return array{model: class-string, tabel: string, detail: string, fk: string,
     *               tanggal: string, karyawan: string, punyaMesin: bool}
     */
    private function config(string $sumber): array
    {
        return match ($sumber) {
            'produksi' => [
                'model' => TransaksiProduksi::class,
                'tabel' => 'transaksi_produksi',
                'detail' => 'transaksi_produksi_ng',
                'fk' => 'id_transaksi_produksi',
                'tanggal' => 'tanggal_produksi',
                'karyawan' => 'id_karyawan',
                'punyaMesin' => true,
            ],
            'qc' => [
                'model' => TransaksiQc::class,
                'tabel' => 'transaksi_qc',
                'detail' => 'transaksi_qc_ng',
                'fk' => 'id_transaksi_qc',
                'tanggal' => 'tgl_pengecekan',
                'karyawan' => 'id_karyawan_qc',
                // transaksi_qc tidak punya id_mesin — batas data, bukan pilihan desain.
                'punyaMesin' => false,
            ],
            default => throw new \InvalidArgumentException(
                "Sumber NG tidak dikenal: {$sumber}. Yang sah: 'produksi' atau 'qc'."
            ),
        };
    }

    /** Baris tanpa paginasi — dipakai export, supaya layar dan Excel satu sumber. */
    public function rows(string $sumber, array $filter): Collection
    {
        $cfg = $this->config($sumber);

        if (! NgDetailService::available()) {
            return collect();
        }

        return $this->query($cfg, $filter)->get();
    }

    public function paginate(string $sumber, array $filter, int $perPage = 50): LengthAwarePaginator
    {
        $cfg = $this->config($sumber);

        if (! NgDetailService::available()) {
            return new LengthAwarePaginator([], 0, $perPage, Paginator::resolveCurrentPage());
        }

        return $this->query($cfg, $filter)
            ->paginate($perPage)
            ->withQueryString();
    }

    private function query(array $cfg, array $filter): Builder
    {
        $t = $cfg['tabel'];
        $d = $cfg['detail'];

        $q = $cfg['model']::query()
            ->join("{$d} as d", "d.{$cfg['fk']}", '=', "{$t}.id")
            ->join('master_jenis_ng as j', 'j.id', '=', 'd.id_jenis_ng')
            ->join('master_part_proses as pp', 'pp.id', '=', "{$t}.id_part_proses")
            ->join('master_part as p', 'p.id', '=', 'pp.id_part')
            ->leftJoin('master_shift as s', 's.id', '=', "{$t}.id_shift")
            ->leftJoin('master_karyawan as k', 'k.id', '=', "{$t}.{$cfg['karyawan']}")
            ->select([
                "{$t}.id as id_sesi",
                "{$t}.{$cfg['tanggal']} as tanggal",
                's.nama_shift as shift',
                'p.customer as customer',
                'p.part_no as part_no',
                'p.part_name as part_name',
                'pp.nama_proses as proses',
                'k.nama_karyawan as orang',
                'j.kode as kode_ng',
                'j.nama_ng as nama_ng',
                'd.qty as qty',
            ]);

        if ($cfg['punyaMesin']) {
            $q->leftJoin('master_mesin as m', 'm.id', '=', "{$t}.id_mesin")
                ->addSelect('m.nama_mesin as mesin');
        }

        /*
         * Tanggal terbaru dulu, lalu qty terbesar. `d.id` sebagai pemecah seri
         * terakhir: tanpa itu dua baris dengan tanggal & qty sama bisa berpindah
         * urutan antar halaman, dan paginasi kehilangan/menggandakan baris.
         */
        $q->orderByDesc("{$t}.{$cfg['tanggal']}")
            ->orderByDesc('d.qty')
            ->orderByDesc('d.id');

        return $this->applyFilters($q, $cfg, $filter);
    }

    /**
     * Filter kosong berarti TIDAK menyaring. Filter aktif digabung AND; hanya
     * pencarian teks yang ber-OR, dan OR itu dikurung sendiri supaya tidak
     * menelan kondisi lain.
     */
    private function applyFilters(Builder $q, array $cfg, array $filter): Builder
    {
        $tanggal = "{$cfg['tabel']}.{$cfg['tanggal']}";

        return $q
            ->when($filter['date_from'] ?? null, fn ($q, $v) => $q->whereDate($tanggal, '>=', $v))
            ->when($filter['date_to'] ?? null, fn ($q, $v) => $q->whereDate($tanggal, '<=', $v))
            ->when($filter['jenis_ng_id'] ?? null, fn ($q, $v) => $q->where('d.id_jenis_ng', $v))
            ->when(
                $filter['karyawan_id'] ?? null,
                fn ($q, $v) => $q->where("{$cfg['tabel']}.{$cfg['karyawan']}", $v)
            )
            ->when($this->trimmed($filter['q'] ?? null), function ($q, string $term) {
                $like = '%'.$this->escapeLike($term).'%';

                $q->where(function ($q) use ($like) {
                    $q->where('p.part_no', 'like', $like)
                        ->orWhere('p.part_name', 'like', $like)
                        ->orWhere('p.customer', 'like', $like);
                });
            });
    }

    /** String yang isinya cuma spasi diperlakukan sebagai filter kosong. */
    private function trimmed(?string $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * `%` dan `_` yang diketik user adalah karakter literal, bukan wildcard —
     * tanpa ini "50%" mencocokkan hampir semua baris. Backslash di-escape lebih
     * dulu supaya tidak melucuti escape berikutnya.
     */
    private function escapeLike(string $term): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $term);
    }
}
