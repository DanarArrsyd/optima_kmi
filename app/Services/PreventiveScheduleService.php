<?php

namespace App\Services;

use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class PreventiveScheduleService
{
    private const ROMAN = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Slot preventive per minggu, per jenis. Hardcode — nol UI setting (lihat spec). */
    private const KAPASITAS = ['mesin' => 1, 'dies' => 5];

    public function __construct(
        private MaintenanceDashboardService $mesinSvc,
        private MaintenanceDiesDashboardService $diesSvc,
    ) {}

    /**
     * Generate (atau regenerate) jadwal preventive satu bulan, satu jenis.
     * Regenerate bulan yang SAMA: UPDATE record (nomor_dokumen dipertahankan),
     * items lama dibuang dan diganti snapshot baru — jadwal yg udah dicetak nggak
     * berubah kalau stroke jalan terus SETELAH dicetak, tapi regenerate manual
     * sengaja menang (operator yg klik ulang mau data terbaru).
     */
    public function generate(string $jenis, Carbon $bulan): PreventiveSchedule
    {
        if (! array_key_exists($jenis, self::KAPASITAS)) {
            throw new \InvalidArgumentException("Jenis preventive schedule tidak dikenal: {$jenis}");
        }

        $bulan = $bulan->copy()->startOfMonth();

        return DB::transaction(function () use ($jenis, $bulan) {
            $schedule = PreventiveSchedule::firstOrNew([
                'bulan' => $bulan->toDateString(),
                'jenis' => $jenis,
            ]);
            if (! $schedule->exists) {
                $schedule->nomor_dokumen = $this->nomorDokumen($jenis, $bulan);
            }
            $schedule->generated_at = now();
            $schedule->generated_by = auth()->id();
            $schedule->save();

            $schedule->items()->delete();

            $eligible = $this->eligibleItems($jenis, $bulan);
            $hariKerja = $this->hariKerjaDalamBulan($bulan);
            $mingguBuckets = $this->kelompokkanPerMinggu($hariKerja);
            $kapasitas = self::KAPASITAS[$jenis];

            $idx = 0;
            foreach ($mingguBuckets as $mingguIndex => $hariList) {
                $mingguKe = $mingguIndex + 1;
                for ($slot = 0; $slot < $kapasitas; $slot++) {
                    if ($idx >= $eligible->count()) {
                        break 2;
                    }
                    if (! isset($hariList[$slot])) {
                        // Minggu ini kehabisan hari kerja buat slot ke-$slot (mis. minggu
                        // pertama/terakhir bulan cuma 1-2 hari kerja) — kapasitas sisa
                        // minggu ini HANGUS, bukan dipindah ke minggu lain.
                        continue;
                    }

                    $item = $eligible[$idx];
                    PreventiveScheduleItem::create([
                        'schedule_id' => $schedule->id,
                        'id_mesin' => $jenis === 'mesin' ? $item['id_mesin'] : null,
                        'id_part_proses' => $jenis === 'dies' ? $item['id_part_proses'] : null,
                        'stroke_snapshot' => $item['current_stroke'],
                        'pct_snapshot' => $item['pct'],
                        'threshold_snapshot' => $item['threshold'],
                        'tanggal_rencana' => $hariList[$slot]->toDateString(),
                        'minggu_ke' => $mingguKe,
                    ]);
                    $idx++;
                }
            }

            return $schedule->fresh('items');
        });
    }

    /**
     * Laju stroke per hari, SATU query batch per jenis (bukan N+1 per item — dies
     * bisa ratusan baris). Window 30 hari kalender SELESAI sebelum hari ini
     * (T-30 s.d. T-1) — hari T sendiri tidak dihitung karena datanya masih parsial.
     *
     * @return array<int, float> [id_mesin_atau_id_part_proses => stroke/hari]
     */
    private function lajuHarian(string $jenis): array
    {
        $akhir = Carbon::now()->subDay()->toDateString();
        $mulai = Carbon::now()->subDays(30)->toDateString();
        $kolom = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

        return TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$mulai, $akhir])
            ->selectRaw("{$kolom} as target_id, COALESCE(SUM(qty_aktual + COALESCE(qty_ng,0)),0) as total")
            ->groupBy($kolom)
            ->pluck('total', 'target_id')
            ->map(fn ($total) => (float) $total / 30)
            ->all();
    }

    /**
     * Union dua kriteria — pct≥80% (perilaku lama, safety net) ATAU proyeksi
     * overdue sebelum generate bulan depan (proaktif, baru) — diurut proyeksi
     * PALING DEKAT duluan. Item overdue sekarang (pct≥100) otomatis proyeksi=hari
     * ini, jadi selalu paling depan.
     */
    private function eligibleItems(string $jenis, Carbon $bulan): Collection
    {
        $rows = collect($jenis === 'mesin' ? $this->mesinSvc->computeCurrentCycle() : $this->diesSvc->computeCurrentCycle());
        $laju = $this->lajuHarian($jenis);
        $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';
        $today = Carbon::now();
        $batasProyeksi = $bulan->copy()->addMonthNoOverflow()->startOfMonth();

        return $rows
            ->map(function ($row) use ($laju, $kolomId, $today) {
                $rate = $laju[$row[$kolomId]] ?? 0.0;
                $sisa = max(0, $row['threshold'] - $row['current_stroke']);

                if ($row['pct'] >= 100) {
                    $proyeksi = $today->copy();
                } elseif ($rate > 0) {
                    $proyeksi = $today->copy()->addDays((int) ceil($sisa / $rate));
                } elseif ($row['pct'] >= 80) {
                    $proyeksi = $today->copy();
                } else {
                    $proyeksi = null;
                }

                $row['proyeksi_overdue'] = $proyeksi;

                return $row;
            })
            ->filter(fn ($row) => $row['pct'] >= 80 || ($row['proyeksi_overdue'] && $row['proyeksi_overdue']->lt($batasProyeksi)))
            ->sortBy(fn ($row) => $row['proyeksi_overdue']?->timestamp ?? PHP_INT_MAX)
            ->values();
    }

    /** Semua hari kerja (nol Minggu, nol hari_libur) dalam satu bulan, urut tanggal. */
    private function hariKerjaDalamBulan(Carbon $bulan): Collection
    {
        $akhir = $bulan->copy()->endOfMonth();
        $hari = collect();
        for ($d = $bulan->copy(); $d->lte($akhir); $d->addDay()) {
            if (KalenderKerjaService::hariKerja($d)) {
                $hari->push($d->copy());
            }
        }

        return $hari;
    }

    /**
     * Kelompokkan hari kerja per minggu kalender (Senin-Minggu ISO). minggu_ke yang
     * disimpan adalah URUTAN RELATIF dalam bulan ini (1, 2, 3, ...), BUKAN nomor
     * minggu ISO mentah (mis. "minggu 35") — lebih gampang dibaca di PDF.
     *
     * @return Collection<int, Collection<int, Carbon>>
     */
    private function kelompokkanPerMinggu(Collection $hariKerja): Collection
    {
        return $hariKerja
            ->groupBy(fn (Carbon $d) => $d->format('o-W'))
            ->values()
            ->map(fn ($grp) => $grp->values());
    }

    private function nomorDokumen(string $jenis, Carbon $bulan): string
    {
        $prefix = $jenis === 'mesin' ? 'PM-MESIN' : 'PM-DIES';
        $tahun = $bulan->year;

        $maxNomor = PreventiveSchedule::where('jenis', $jenis)
            ->whereYear('bulan', $tahun)
            ->get()
            ->map(function ($s) {
                $bagian = explode('/', $s->nomor_dokumen);

                return (int) ($bagian[1] ?? 0);
            })
            ->max() ?? 0;

        $roman = self::ROMAN[$bulan->month] ?? 'I';

        return "{$prefix}/".($maxNomor + 1)."/{$roman}/{$tahun}";
    }
}
