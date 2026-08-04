<?php

namespace App\Services;

use App\Models\DiesPreventiveLog;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class MaintenanceDiesDashboardService
{
    public const STROKE_THRESHOLD = 30000;

    /** Memo per-instance: kolom durasi_menit ada di DB? (hosting kadang telat migrasi) */
    private ?bool $durasiKolomAda = null;

    public function __construct(
        private KalenderKerjaService $kalender = new KalenderKerjaService,
    ) {}

    private function durasiKolomAda(): bool
    {
        return $this->durasiKolomAda ??= Schema::hasColumn('transaksi_produksi', 'durasi_menit');
    }

    /**
     * Per-dies+proses MTTR/MTBF/Availability (1 part bisa punya banyak proses/dies).
     * Output keys mirror the mesin service so the dashboard views stay identical
     * (kode_mesin = part_no, dst), plus 'nama_proses' untuk kolom Proses.
     */
    public function compute(Carbon $from, Carbon $to, ?int $partId = null, ?string $customer = null): array
    {
        $hasInstalasi = Schema::hasColumn('master_part', 'tanggal_instalasi');

        $partProsesList = MasterPartProses::with('part')
            ->when($partId, fn ($q) => $q->where('id_part', $partId))
            ->when($customer, fn ($q) => $q->whereHas('part', fn ($p) => $p->where('customer', $customer)))
            ->get()
            ->sortBy(fn ($pr) => $pr->part->part_no ?? '');

        $stats = TransaksiBreakdownDies::query()
            /*
             * Rentang SETENGAH TERBUKA [from, to+1hari). Bug yang SAMA dengan
             * yang sudah diperbaiki di MaintenanceDashboardService dan
             * OverviewService — ini kemunculan KETIGA.
             *
             * Kolomnya DATE tapi cast model-nya 'date' TANPA format, jadi nilai
             * yang ditulis berbentuk '2026-08-05 00:00:00'. MySQL memotong
             * jamnya sehingga prod tidak bergejala; SQLite menyimpannya, dan
             * '2026-08-05 00:00:00' <= '2026-08-05' itu FALSE secara
             * leksikografis — breakdown di HARI TERAKHIR rentang hilang.
             *
             * Ketahuan hanya karena test availability dies akhirnya ditulis:
             * angkanya 100% padahal ada downtime 2 jam. Sebelum itu halaman ini
             * nol cakupan, jadi bug-nya tak punya cara untuk gagal.
             */
            ->where('tanggal_breakdown', '>=', $from->toDateString())
            ->where('tanggal_breakdown', '<', $to->copy()->addDay()->toDateString())
            ->when($partId, fn ($q) => $q->whereHas('partProses', fn ($p) => $p->where('id_part', $partId)))
            ->get(['id_part_proses', 'repair_time', 'total_downtime'])
            ->groupBy('id_part_proses');

        $rows = [];
        foreach ($partProsesList as $pr) {
            $p = $pr->part;
            $group = $stats->get($pr->id) ?? collect();
            $count = $group->count();
            $sumRepair = round((float) $group->sum('repair_time'), 2);
            $sumDowntime = round((float) $group->sum('total_downtime'), 2);
            $mttr = $count > 0 ? round($sumRepair / $count, 2) : 0.0;

            $instalasi = $hasInstalasi ? $p?->tanggal_instalasi : null;
            $mtbfJam = $mtbfHari = $availPct = null;
            $operatingHours = null;
            $instalasiSet = false;

            if ($instalasi) {
                $instalasiSet = true;
                $windowStart = Carbon::parse($instalasi)->max($from->copy()->startOfDay());

                /*
                 * JAM TERJADWAL, sama seperti MaintenanceDashboardService.
                 *
                 * Sebuah dies memang tidak "dijadwalkan per shift" — ia dipasang
                 * saat dibutuhkan. Tapi ia juga hanya bisa RUSAK dan MENGANGGUR
                 * selama pabrik bekerja, jadi jam kerja terjadwal tetap penyebut
                 * yang benar. Yang tidak benar adalah membiarkan halaman ini
                 * memakai jam kalender sementara halaman Availability mesin
                 * memakai jam shift: dua halaman berlabel "Availability" dengan
                 * basis berbeda, dan tidak ada di layar yang memberi tahu.
                 */
                $operatingHours = $this->kalender->jamTerjadwal($windowStart, $to);

                $uptime = max(0.0, $operatingHours - $sumDowntime);
                $mtbfJam = $count > 0 ? round($uptime / $count, 2) : round($uptime, 2);
                $mtbfHari = round($mtbfJam / 24, 2);

                // Nol jam terjadwal (rentang cuma Minggu/libur) → null, BUKAN
                // 100%. "Tidak ada jam kerja" bukan berarti "sempurna".
                $availPct = $operatingHours > 0
                    ? round($uptime / $operatingHours * 100, 2)
                    : null;
            }

            // Cuma tampilkan dies+proses yang punya breakdown atau instalasi diset (hindari list ribuan baris)
            if ($count === 0 && ! $instalasiSet) {
                continue;
            }

            $rows[] = [
                'id_mesin' => $pr->id,
                'kode_mesin' => $p?->part_no,
                'nama_mesin' => $p?->part_name,
                'nama_proses' => $pr->nama_proses,
                'count' => $count,
                'sum_repair' => $sumRepair,
                'sum_downtime' => $sumDowntime,
                'mttr_jam' => $mttr,
                'mtbf_jam' => $mtbfJam,
                'mtbf_hari' => $mtbfHari,
                'availability_pct' => $availPct,
                // Ikut dikeluarkan supaya bentuk barisnya senada service mesin:
                // pemakai bisa memverifikasi availability dari penyebutnya
                // sendiri, dan agregasi lintas-dies kelak tidak perlu menebak
                // atau menghitung ulang jam jendelanya.
                'operating_hours' => $operatingHours === null ? null : round($operatingHours, 2),
                // Senada service mesin: downtime bisa melebihi jam terjadwal
                // (perbaikan dijalankan hari Minggu). Ditandai supaya 0% tidak
                // terbaca sebagai angka biasa.
                'kerugian_melebihi_jam' => $operatingHours !== null && $sumDowntime > $operatingHours,
                'instalasi_set' => $instalasiSet,
            ];
        }

        return array_values($rows);
    }

    /**
     * Total siklus berjalan (sejak preventive terakhir / instalasi) untuk satu
     * dies+proses: stroke + running time, di-SUM satu query. Running time
     * meng-agregat SEMUA mesin yang menjalankan dies ini (LHP difilter per
     * id_part_proses, bukan per mesin). Durasi dari kolom persist durasi_menit.
     *
     * @return array{stroke:int, durasi_menit:int}
     */
    public function cycleTotalsFor(MasterPartProses $pr): array
    {
        // Reuse the eager-loaded preventiveLogs relation when present (computeCurrentCycle
        // loads it) to avoid a per-dies MAX() query. toDateString() keeps the exact 'Y-m-d'
        // string that DB::max() returned, so the strict `>` boundary stays byte-identical.
        $lastPreventive = $pr->relationLoaded('preventiveLogs')
            ? optional($pr->preventiveLogs->max('tanggal_preventive'))->toDateString()
            : DiesPreventiveLog::where('id_part_proses', $pr->id)->max('tanggal_preventive');
        $cycleStart = $lastPreventive ?? $pr->part?->tanggal_instalasi;

        // DEFENSIF: kolom durasi_menit bisa belum ada di hosting (migrasi telat).
        // Kalau belum, jangan sentuh kolomnya — cukup SUM stroke, running time 0.
        $durasiExpr = $this->durasiKolomAda() ? 'COALESCE(SUM(durasi_menit),0)' : '0';

        /*
         * Stroke = qty_aktual + qty_ng. `qty_aktual` berisi barang BAIK saja
         * (dikonfirmasi ke produksi 2026-07-28, lihat OeeService), sedangkan
         * stroke mengukur aksi FISIK dies — dies tetap menekan untuk barang
         * yang jadi NG. Menghitung barang baik saja membuat siklus understated
         * dan preventive terpicu TERLAMBAT, arah error yang tidak aman.
         *
         * COALESCE di dalam SUM wajib: qty_ng nullable default null, dan
         * `qty_aktual + NULL` = NULL di SQL.
         */
        $agg = TransaksiProduksi::query()
            ->where('id_part_proses', $pr->id)
            ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
            ->selectRaw("COALESCE(SUM(qty_aktual + COALESCE(qty_ng,0)),0) as stroke, {$durasiExpr} as durasi_menit")
            ->first();

        return ['stroke' => (int) $agg->stroke, 'durasi_menit' => (int) $agg->durasi_menit];
    }

    public function currentStrokeFor(MasterPartProses $pr): int
    {
        return $this->cycleTotalsFor($pr)['stroke'];
    }

    public function computeCurrentCycle(?int $partId = null, ?string $customer = null): array
    {
        $partProsesList = MasterPartProses::with(['part', 'preventiveLogs.karyawan'])
            ->whereHas('transaksiProduksi')
            ->when($partId, fn ($q) => $q->where('id_part', $partId))
            ->when($customer, fn ($q) => $q->whereHas('part', fn ($p) => $p->where('customer', $customer)))
            ->get()
            ->sortBy(fn ($pr) => $pr->part->part_no ?? '');

        $rows = [];
        foreach ($partProsesList as $pr) {
            $totals = $this->cycleTotalsFor($pr);
            $currentStroke = $totals['stroke'];
            $pct = round($currentStroke / self::STROKE_THRESHOLD * 100, 1);
            $status = $pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok');
            $lastLog = $pr->preventiveLogs->first();

            $rows[] = [
                'id_part_proses' => $pr->id,
                'kode_mesin' => $pr->part?->part_no,
                'nama_mesin' => $pr->part?->part_name,
                'nama_proses' => $pr->nama_proses,
                'customer' => $pr->part?->customer,
                'current_stroke' => $currentStroke,
                'running_time_menit' => $totals['durasi_menit'],
                'threshold' => self::STROKE_THRESHOLD,
                'pct' => $pct,
                'status' => $status,
                'last_preventive_date' => $lastLog?->tanggal_preventive?->format('Y-m-d'),
                'last_preventive_pic' => $lastLog?->karyawan?->nama_karyawan,
            ];
        }

        $statusOrder = ['overdue' => 0, 'warning' => 1, 'ok' => 2];
        usort($rows, function ($a, $b) use ($statusOrder) {
            $byStatus = $statusOrder[$a['status']] <=> $statusOrder[$b['status']];

            return $byStatus !== 0 ? $byStatus : $b['pct'] <=> $a['pct'];
        });

        return array_values($rows);
    }
}
