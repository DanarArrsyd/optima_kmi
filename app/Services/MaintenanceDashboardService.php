<?php
namespace App\Services;

use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

class MaintenanceDashboardService
{
    public function compute(Carbon $from, Carbon $to, ?int $mesinId = null, ?string $tipeMesin = null): array
    {
        $hasInstalasi = Schema::hasColumn('master_mesin', 'tanggal_instalasi');
        $hasTipe      = Schema::hasColumn('master_mesin', 'tipe_mesin');

        $mesins = MasterMesin::when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
            ->orderBy('id_mesin')->get();

        $stats = TransaksiBreakdownMesin::query()
            ->whereBetween('tanggal_breakdown', [$from->toDateString(), $to->toDateString()])
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->get(['id_mesin', 'repair_time', 'total_downtime'])
            ->groupBy('id_mesin');

        $rows = [];
        foreach ($mesins as $m) {
            $group        = $stats->get($m->id) ?? collect();
            $count        = $group->count();
            $sumRepair    = round((float) $group->sum('repair_time'), 2);
            $sumDowntime  = round((float) $group->sum('total_downtime'), 2);
            $mttr         = $count > 0 ? round($sumRepair / $count, 2) : 0.0;

            $instalasi    = $hasInstalasi ? $m->tanggal_instalasi : null;
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

            $rows[] = [
                'id_mesin'         => $m->id,
                'kode_mesin'       => $m->id_mesin,
                'nama_mesin'       => $m->nama_mesin,
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

        return $rows;
    }

    public function computeStroke(Carbon $from, Carbon $to, ?int $mesinId = null, ?string $tipeMesin = null): array
    {
        $hasTipe = Schema::hasColumn('master_mesin', 'tipe_mesin');

        $mesins = MasterMesin::when($mesinId, fn ($q) => $q->where('id', $mesinId))
            ->when($tipeMesin && $hasTipe, fn ($q) => $q->where('tipe_mesin', $tipeMesin))
            ->orderBy('id_mesin')->get();

        $stats = TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$from->toDateString(), $to->toDateString()])
            ->when($mesinId, fn ($q) => $q->where('id_mesin', $mesinId))
            ->selectRaw('id_mesin, SUM(qty_aktual) as total_stroke')
            ->groupBy('id_mesin')
            ->pluck('total_stroke', 'id_mesin');

        return $mesins->map(fn ($m) => [
            'id_mesin'     => $m->id,
            'kode_mesin'   => $m->id_mesin,
            'nama_mesin'   => $m->nama_mesin,
            'total_stroke' => (int) ($stats->get($m->id) ?? 0),
        ])->values()->all();
    }
}
