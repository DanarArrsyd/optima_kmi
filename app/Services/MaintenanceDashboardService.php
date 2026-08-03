<?php

namespace App\Services;

use App\Models\MasterMesin;
use App\Models\MesinPreventiveLog;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class MaintenanceDashboardService
{
    /** Target preventive mesin (stroke). Sengaja 10× threshold dies: stroke mesin
        adalah gabungan semua part yang dikerjakan mesin itu. */
    public const STROKE_THRESHOLD = 300000;

    /** Memo per-instance: kolom durasi_menit ada di DB? (hosting kadang telat migrasi) */
    private ?bool $durasiKolomAda = null;

    private function durasiKolomAda(): bool
    {
        return $this->durasiKolomAda ??= Schema::hasColumn('transaksi_produksi', 'durasi_menit');
    }

    public function compute(Carbon $from, Carbon $to, ?int $mesinId = null, ?string $tipeMesin = null): array
    {
        $hasInstalasi = Schema::hasColumn('master_mesin', 'tanggal_instalasi');
        $hasTipe = Schema::hasColumn('master_mesin', 'tipe_mesin');

        $mesins = MasterMesin::when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
            ->orderBy('id_mesin')->get();

        /*
         * Rentang SETENGAH TERBUKA [from, to+1hari), bukan whereBetween dengan
         * batas atas $to->toDateString().
         *
         * Kolomnya DATE, tapi cast model-nya 'date' TANPA format (bandingkan
         * TransaksiProduksi yang pakai 'date:Y-m-d'). Akibatnya nilai yang
         * ditulis berbentuk '2026-07-17 00:00:00'. Di MySQL kolom DATE
         * memotong jamnya, jadi tak ada gejala. Di SQLite tidak ada tipe DATE:
         * jamnya tersimpan, dan '2026-07-17 00:00:00' <= '2026-07-17' itu FALSE
         * secara leksikografis — breakdown di HARI TERAKHIR rentang hilang.
         *
         * Dampaknya bukan teoretis: halaman Overview memakai rentang satu hari
         * (from == to), jadi versi whereBetween membuat SELURUH breakdown hari
         * itu tak terhitung di Availability. Di prod (MySQL) angkanya benar,
         * tapi tak ada satu test pun yang bisa membuktikannya — persis kelas
         * bug "fitur mustahil diuji karena dialek SQL".
         *
         * Setengah terbuka benar di kedua dialek, ada atau tidak ada komponen
         * jam, dan tetap memakai indeks tanggal_breakdown (tidak seperti
         * whereDate yang membungkus kolom dalam fungsi).
         */
        $stats = TransaksiBreakdownMesin::query()
            ->where('tanggal_breakdown', '>=', $from->toDateString())
            ->where('tanggal_breakdown', '<', $to->copy()->addDay()->toDateString())
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->get(['id_mesin', 'repair_time', 'total_downtime'])
            ->groupBy('id_mesin');

        $rows = [];
        foreach ($mesins as $m) {
            $group = $stats->get($m->id) ?? collect();
            $count = $group->count();
            $sumRepair = round((float) $group->sum('repair_time'), 2);
            $sumDowntime = round((float) $group->sum('total_downtime'), 2);
            $mttr = $count > 0 ? round($sumRepair / $count, 2) : 0.0;

            $instalasi = $hasInstalasi ? $m->tanggal_instalasi : null;
            $mtbfJam = $mtbfHari = $availPct = null;
            $operatingHours = null;
            $instalasiSet = false;

            if ($instalasi) {
                $instalasiSet = true;
                $windowStart = Carbon::parse($instalasi)->max($from->copy()->startOfDay());
                $operatingHours = max(0.0, $windowStart->diffInRealHours($to->copy()->endOfDay(), false));
                $uptime = max(0.0, $operatingHours - $sumDowntime);
                $mtbfJam = $count > 0 ? round($uptime / $count, 2) : round($uptime, 2);
                $mtbfHari = round($mtbfJam / 24, 2);
                $availPct = $operatingHours > 0 ? round($uptime / $operatingHours * 100, 2) : 100.0;
            }

            $rows[] = [
                'id_mesin' => $m->id,
                'kode_mesin' => $m->id_mesin,
                'nama_mesin' => $m->nama_mesin,
                'count' => $count,
                'sum_repair' => $sumRepair,
                'sum_downtime' => $sumDowntime,
                'mttr_jam' => $mttr,
                'mtbf_jam' => $mtbfJam,
                'mtbf_hari' => $mtbfHari,
                'availability_pct' => $availPct,
                // Jam jendela yang dipakai sebagai PENYEBUT availability_pct, ikut
                // dikeluarkan supaya pemakai yang meng-agregasi banyak mesin
                // (OverviewService: availability pabrik) bisa menimbangnya dengan
                // benar tanpa menyalin rumus jendela di atas. Rata-rata polos
                // availability_pct antar-mesin salah begitu ada mesin yang
                // instalasinya jatuh di tengah rentang — penyebutnya beda.
                // null = tanggal instalasi belum diset, sama seperti availability_pct.
                'operating_hours' => $operatingHours === null ? null : round($operatingHours, 2),
                'instalasi_set' => $instalasiSet,
            ];
        }

        return $rows;
    }

    /**
     * Total siklus berjalan (sejak preventive terakhir / instalasi): stroke +
     * running time. Stroke & durasi di-SUM dalam SATU query — durasi dari kolom
     * persist durasi_menit ({@see TransaksiProduksi}), jadi tetap murni SQL.
     *
     * @return array{stroke:int, durasi_menit:int}
     */
    public function cycleTotalsFor(MasterMesin $m): array
    {
        // Reuse the eager-loaded preventiveLogs relation when present (computeCurrentCycle
        // loads it) to avoid a per-mesin MAX() query. toDateString() keeps the exact 'Y-m-d'
        // string that DB::max() returned, so the strict `>` boundary stays byte-identical.
        $lastPreventive = $m->relationLoaded('preventiveLogs')
            ? optional($m->preventiveLogs->max('tanggal_preventive'))->toDateString()
            : MesinPreventiveLog::where('id_mesin', $m->id)->max('tanggal_preventive');
        $cycleStart = $lastPreventive ?? $m->tanggal_instalasi;

        // DEFENSIF: kolom durasi_menit bisa belum ada di hosting (migrasi telat).
        // Kalau belum, jangan sentuh kolomnya — cukup SUM stroke, running time 0.
        $durasiExpr = $this->durasiKolomAda() ? 'COALESCE(SUM(durasi_menit),0)' : '0';

        /*
         * Stroke = qty_aktual + qty_ng. `qty_aktual` berisi barang BAIK saja
         * (dikonfirmasi ke produksi 2026-07-28, lihat OeeService), sedangkan
         * stroke mengukur aksi FISIK mesin — mesin tetap menekan untuk barang
         * yang jadi NG. Menghitung barang baik saja membuat siklus understated
         * dan preventive terpicu TERLAMBAT, arah error yang tidak aman.
         *
         * COALESCE di dalam SUM wajib: qty_ng nullable default null, dan
         * `qty_aktual + NULL` = NULL di SQL.
         */
        $agg = TransaksiProduksi::query()
            ->where('id_mesin', $m->id)
            ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
            ->selectRaw("COALESCE(SUM(qty_aktual + COALESCE(qty_ng,0)),0) as stroke, {$durasiExpr} as durasi_menit")
            ->first();

        return ['stroke' => (int) $agg->stroke, 'durasi_menit' => (int) $agg->durasi_menit];
    }

    public function currentStrokeFor(MasterMesin $m): int
    {
        return $this->cycleTotalsFor($m)['stroke'];
    }

    public function computeCurrentCycle(?int $mesinId = null, ?string $tipeMesin = null): array
    {
        $hasTipe = Schema::hasColumn('master_mesin', 'tipe_mesin');

        // Sengaja TANPA whereHas('transaksiProduksi') — beda dari service Dies yang memfilter.
        // Mesin jumlahnya sedikit; mesin nganggur (0 stroke) tetap informasi berguna.
        $mesins = MasterMesin::with('preventiveLogs.karyawan')
            ->when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
            ->orderBy('id_mesin')->get();

        $rows = [];
        foreach ($mesins as $m) {
            $totals = $this->cycleTotalsFor($m);
            $currentStroke = $totals['stroke'];
            $pct = round($currentStroke / self::STROKE_THRESHOLD * 100, 1);
            $status = $pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok');
            $lastLog = $m->preventiveLogs->first();

            $rows[] = [
                'id_mesin' => $m->id,
                'kode_mesin' => $m->id_mesin,
                'nama_mesin' => $m->nama_mesin,
                'tipe_mesin' => $m->tipe_mesin,
                'line' => $m->line,
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
