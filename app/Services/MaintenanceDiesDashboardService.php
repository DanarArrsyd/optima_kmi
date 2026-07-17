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
            ->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()])
            ->when($partId, fn ($q) => $q->whereHas('partProses', fn ($p) => $p->where('id_part', $partId)))
            ->get(['id_part_proses', 'repair_time', 'total_downtime'])
            ->groupBy('id_part_proses');

        $rows = [];
        foreach ($partProsesList as $pr) {
            $p            = $pr->part;
            $group        = $stats->get($pr->id) ?? collect();
            $count        = $group->count();
            $sumRepair    = round((float) $group->sum('repair_time'), 2);
            $sumDowntime  = round((float) $group->sum('total_downtime'), 2);
            $mttr         = $count > 0 ? round($sumRepair / $count, 2) : 0.0;

            $instalasi    = $hasInstalasi ? $p?->tanggal_instalasi : null;
            $mtbfJam = $mtbfHari = $availPct = null;
            $instalasiSet = false;

            if ($instalasi) {
                $instalasiSet  = true;
                $windowStart   = Carbon::parse($instalasi)->max($from->copy()->startOfDay());
                $operatingHours = max(0.0, $windowStart->diffInRealHours($to->copy()->endOfDay(), false));
                $uptime         = max(0.0, $operatingHours - $sumDowntime);
                $mtbfJam        = $count > 0 ? round($uptime / $count, 2) : round($uptime, 2);
                $mtbfHari       = round($mtbfJam / 24, 2);
                $availPct       = $operatingHours > 0 ? round($uptime / $operatingHours * 100, 2) : 100.0;
            }

            // Cuma tampilkan dies+proses yang punya breakdown atau instalasi diset (hindari list ribuan baris)
            if ($count === 0 && ! $instalasiSet) {
                continue;
            }

            $rows[] = [
                'id_mesin'         => $pr->id,
                'kode_mesin'       => $p?->part_no,
                'nama_mesin'       => $p?->part_name,
                'nama_proses'      => $pr->nama_proses,
                'count'            => $count,
                'sum_repair'       => $sumRepair,
                'sum_downtime'     => $sumDowntime,
                'mttr_jam'         => $mttr,
                'mtbf_jam'         => $mtbfJam,
                'mtbf_hari'        => $mtbfHari,
                'availability_pct' => $availPct,
                'instalasi_set'    => $instalasiSet,
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
        $cycleStart     = $lastPreventive ?? $pr->part?->tanggal_instalasi;

        // DEFENSIF: kolom durasi_menit bisa belum ada di hosting (migrasi telat).
        // Kalau belum, jangan sentuh kolomnya — cukup SUM stroke, running time 0.
        $durasiExpr = $this->durasiKolomAda() ? 'COALESCE(SUM(durasi_menit),0)' : '0';

        $agg = TransaksiProduksi::query()
            ->where('id_part_proses', $pr->id)
            ->when($cycleStart, fn ($q) => $q->where('tanggal_produksi', '>', $cycleStart))
            ->selectRaw("COALESCE(SUM(qty_aktual),0) as stroke, {$durasiExpr} as durasi_menit")
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
            $totals        = $this->cycleTotalsFor($pr);
            $currentStroke = $totals['stroke'];
            $pct           = round($currentStroke / self::STROKE_THRESHOLD * 100, 1);
            $status        = $pct >= 100 ? 'overdue' : ($pct >= 80 ? 'warning' : 'ok');
            $lastLog       = $pr->preventiveLogs->first();

            $rows[] = [
                'id_part_proses'        => $pr->id,
                'kode_mesin'            => $pr->part?->part_no,
                'nama_mesin'            => $pr->part?->part_name,
                'nama_proses'           => $pr->nama_proses,
                'customer'              => $pr->part?->customer,
                'current_stroke'        => $currentStroke,
                'running_time_menit'    => $totals['durasi_menit'],
                'threshold'             => self::STROKE_THRESHOLD,
                'pct'                   => $pct,
                'status'                => $status,
                'last_preventive_date'  => $lastLog?->tanggal_preventive?->format('Y-m-d'),
                'last_preventive_pic'   => $lastLog?->karyawan?->nama_karyawan,
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
