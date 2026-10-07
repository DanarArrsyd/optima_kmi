<?php

namespace App\Services;

use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Membersihkan baris transaksi kembar yang masuk SEBELUM PenjagaKembar ada
 * (probe prod 7 Okt 2026: 147 LHP, 564 dandori, 2 breakdown).
 *
 * Kunci kembar = PenjagaKembar::KUNCI, satu definisi dengan peringatan input.
 * Per grup satu baris disimpan: id yang dipaksa ($simpanPaksa) > baris yang
 * sudah direview anomalinya > id terkecil (input pertama). Sisanya di-SOFT
 * delete lewat Eloquent (tercatat di log aktivitas) dan id-nya ditulis ke
 * berkas supaya bisa dipulihkan utuh.
 *
 * Tidak pernah dihapus, dilaporkan sebagai RAGU:
 *  - baris yang punya staff note (catatan staf tidak boleh hilang diam-diam)
 *  - dandori yang kembar DI DETIK YANG SAMA tapi baris LHP-nya cuma satu:
 *    satu sesi LHP memang bisa berisi dua rincian loss time sama persis,
 *    jadi itu belum tentu dobel.
 */
class PembersihKembar
{
    /**
     * @param  array<string, int[]>  $simpanPaksa  modul => id yang wajib disimpan
     * @return array<string, array{hapus: array<int, string>, ragu: array<int, string>, paksa: int[]}>
     */
    public function rencana(array $simpanPaksa = []): array
    {
        $hasil = [];
        foreach (PenjagaKembar::KUNCI as $modul => [$model, $kunci]) {
            $hasil[$modul] = ['hapus' => [], 'ragu' => [], 'paksa' => []];
            $tabel = (new $model)->getTable();
            $kol = array_keys($kunci);
            $adaNote = Schema::hasColumn($tabel, 'staff_note');
            $adaReview = Schema::hasColumn($tabel, 'anomaly_reviewed_at');

            foreach ($this->grup($tabel, $kol) as $isi) {
                $paksa = array_values(array_filter($isi, fn ($r) => in_array($r->id, $simpanPaksa[$modul] ?? [])));
                $review = array_values(array_filter($isi, fn ($r) => $adaReview && $r->anomaly_reviewed_at));
                $simpan = $paksa[0] ?? $review[0] ?? $isi[0];
                if ($paksa) {
                    $hasil[$modul]['paksa'][] = $simpan->id;
                }

                foreach ($isi as $r) {
                    if ($r->id === $simpan->id) {
                        continue;
                    }
                    $ket = "#{$r->id} (simpan #{$simpan->id}) ".implode(' ', array_map(fn ($c) => $r->{$c}, $kol));
                    $ragu = match (true) {
                        $adaNote && filled($r->staff_note) => 'punya staff note',
                        $modul === 'dandori' && $r->created_at === $simpan->created_at && ! $this->lhpIkutDobel($r) => 'sedetik, LHP-nya tidak dobel',
                        default => null,
                    };
                    if ($ragu) {
                        $hasil[$modul]['ragu'][$r->id] = "{$ket} — {$ragu}";
                    } else {
                        $hasil[$modul]['hapus'][$r->id] = $ket;
                    }
                }
            }
        }

        return $hasil;
    }

    /** Soft delete semua 'hapus', tulis berkas pemulih. Mengembalikan path berkas. */
    public function terapkan(array $rencana): string
    {
        $ids = array_map(fn ($m) => array_keys($m['hapus']), $rencana);

        DB::transaction(function () use ($ids) {
            foreach ($ids as $modul => $daftar) {
                $model = PenjagaKembar::KUNCI[$modul][0];
                foreach (array_chunk($daftar, 200) as $potong) {
                    $model::whereIn('id', $potong)->get()->each->delete();
                }
            }
        });

        $path = 'bersih-kembar/'.now()->format('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($ids, JSON_PRETTY_PRINT));

        return $path;
    }

    /** Kebalikan terapkan(): kembalikan semua id di berkas. Mengembalikan jumlah baris. */
    public function pulihkan(string $path): int
    {
        $ids = json_decode(Storage::disk('local')->get($path), true, flags: JSON_THROW_ON_ERROR);
        $n = 0;
        DB::transaction(function () use ($ids, &$n) {
            foreach ($ids as $modul => $daftar) {
                $model = PenjagaKembar::KUNCI[$modul][0];
                foreach (array_chunk($daftar, 200) as $potong) {
                    $model::onlyTrashed()->whereIn('id', $potong)->get()->each(function ($r) use (&$n) {
                        $r->restore();
                        $n++;
                    });
                }
            }
        });

        return $n;
    }

    /** @return array<int, object[]> grup baris aktif berkunci sama, urut id */
    private function grup(string $tabel, array $kol): array
    {
        $daftar = implode(',', $kol);
        $rows = DB::table("{$tabel} as t")
            ->joinSub(
                DB::table($tabel)->whereNull('deleted_at')->groupBy($kol)->havingRaw('COUNT(*) > 1')->select($kol),
                'g',
                fn ($j) => array_map(fn ($c) => $j->on("t.{$c}", '=', "g.{$c}"), $kol)
            )
            ->whereNull('t.deleted_at')
            ->orderBy('t.id')
            ->select('t.*')
            ->get();

        $grup = [];
        foreach ($rows as $r) {
            $grup[implode('|', array_map(fn ($c) => $r->{$c}, $kol))][] = $r;
        }

        return array_values($grup);
    }

    /** Baris LHP sesi dandori ini juga tertulis ≥2× di detik yang sama = kiriman LHP dobel. */
    private function lhpIkutDobel(object $d): bool
    {
        $tanggal = substr((string) $d->tanggal, 0, 10);
        $lhp = TransaksiProduksi::query()
            ->whereDate('tanggal_produksi', $tanggal)
            ->where('id_shift', $d->id_shift)->where('id_mesin', $d->id_mesin)->where('id_part_proses', $d->id_part_proses)
            ->where('created_at', $d->created_at)
            ->count();

        if ($lhp >= 2) {
            return true;
        }
        if ($lhp === 1) {
            return false;
        }

        /* Bukan dari LHP (form Dandori): dobel bila SELURUH sesi di detik itu
         * tertulis rangkap — tiap rincian muncul genap. */
        return TransaksiDandoriRincian::query()
            ->whereDate('tanggal', $tanggal)
            ->where('id_shift', $d->id_shift)->where('id_karyawan', $d->id_karyawan)
            ->where('id_mesin', $d->id_mesin)->where('id_part_proses', $d->id_part_proses)
            ->where('created_at', $d->created_at)
            ->get(['id_losstime', 'actual_menit'])
            ->countBy(fn ($r) => $r->id_losstime.'|'.$r->actual_menit)
            ->every(fn ($n) => $n % 2 === 0);
    }
}
