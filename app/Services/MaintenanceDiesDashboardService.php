<?php
namespace App\Services;

use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class MaintenanceDiesDashboardService
{
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
}
