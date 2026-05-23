<?php
namespace App\Services;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\TransaksiDandori;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * VCB Produksi: aggregate by karyawan for date range.
     */
    public function getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null): array
    {
        $records = TransaksiProduksi::with(['karyawan', 'partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_karyawan')->map(function ($rows) {
            $totalQtyAktual     = $rows->sum('qty_aktual');
            $totalDurationHours = $rows->sum(fn($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty     = $rows->sum(fn($r) =>
                $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_produksi
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyAktual / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyAktual / $totalDurationHours, 2)
                : 0;

            return [
                'karyawan'             => $rows->first()->karyawan->nama_karyawan,
                'total_qty_aktual'     => $totalQtyAktual,
                'total_target_qty'     => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct'      => $achievementPct,
                'avg_actual_sph'       => $avgActualSph,
                'traffic_light'        => $this->getTrafficLight($achievementPct),
                'detail'               => $rows->map(fn($r) => [
                    'tanggal'    => $r->tanggal_produksi instanceof \Carbon\Carbon
                        ? $r->tanggal_produksi->format('d/m/Y')
                        : \Carbon\Carbon::parse($r->tanggal_produksi)->format('d/m/Y'),
                    'mesin'      => $r->mesin->nama_mesin,
                    'part'       => $r->partProses->part->part_name ?? '-',
                    'proses'     => $r->partProses->nama_proses,
                    'qty_aktual' => $r->qty_aktual,
                    'target_qty' => round($this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_produksi, 0),
                    'actual_ct'  => $r->actual_cycle_time,
                ])->values()->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Mesin: aggregate by mesin for date range.
     */
    public function getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null): array
    {
        $records = TransaksiProduksi::with(['mesin', 'partProses'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_mesin')->map(function ($rows) {
            $totalQtyAktual     = $rows->sum('qty_aktual');
            $totalDurationHours = $rows->sum(fn($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty     = $rows->sum(fn($r) =>
                $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_produksi
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyAktual / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyAktual / $totalDurationHours, 2)
                : 0;

            $mesin = $rows->first()->mesin;
            return [
                'mesin'                => $mesin->nama_mesin,
                'id_mesin'             => $mesin->id_mesin,
                'line'                 => $mesin->line,
                'tipe_mesin'           => $mesin->tipe_mesin,
                'total_qty_aktual'     => $totalQtyAktual,
                'total_target_qty'     => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct'      => $achievementPct,
                'avg_actual_sph'       => $avgActualSph,
                'traffic_light'        => $this->getTrafficLight($achievementPct),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Quality: aggregate by karyawan_qc for date range.
     */
    public function getVcbQuality(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null): array
    {
        $records = TransaksiQc::with(['karyawanQc', 'partProses'])
            ->whereBetween('tgl_pengecekan', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_karyawan_qc')->map(function ($rows) {
            $totalQtyOk         = $rows->sum('qty_ok');
            $totalQtyNg         = $rows->sum('qty_ng');
            $totalQtyCheck      = $totalQtyOk + $totalQtyNg;
            $totalDurationHours = $rows->sum(fn($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty     = $rows->sum(fn($r) =>
                $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_qc
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyCheck / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyCheck / $totalDurationHours, 2)
                : 0;

            return [
                'karyawan'             => $rows->first()->karyawanQc->nama_karyawan,
                'total_qty_ok'         => $totalQtyOk,
                'total_qty_ng'         => $totalQtyNg,
                'total_qty_check'      => $totalQtyCheck,
                'total_target_qty'     => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct'      => $achievementPct,
                'avg_actual_sph'       => $avgActualSph,
                'traffic_light'        => $this->getTrafficLight($achievementPct),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Dandori: aggregate menit_loss and menit_over by mesin × jenis_loss.
     */
    public function getVcbDandori(?string $bulan = null, ?string $week = null, ?int $mesinId = null, ?int $shiftId = null): array
    {
        $query = TransaksiDandori::with(['mesin', 'rincian.losstime'])
            ->when($shiftId, fn($q) => $q->where('id_shift', $shiftId))
            ->when($mesinId, fn($q) => $q->where('id_mesin', $mesinId))
            ->when($bulan, function ($q) use ($bulan) {
                $monthNum = date('m', strtotime("1 {$bulan} 2000"));
                $q->whereMonth('tanggal', $monthNum);
            })
            ->when($week, function ($q) use ($week) {
                $weekNum = (int) str_replace('WEEK ', '', $week);
                $start   = ($weekNum - 1) * 7 + 1;
                $end     = min($weekNum * 7, 31);
                $q->whereDay('tanggal', '>=', $start)->whereDay('tanggal', '<=', $end);
            });

        $dandoriRecords = $query->get();

        $pivot    = [];
        $mesinMap = [];
        $jenisSet = [];

        foreach ($dandoriRecords as $d) {
            $mesinKey = $d->mesin->nama_mesin;
            $mesinMap[$mesinKey] = $d->mesin;
            if (!isset($pivot[$mesinKey])) {
                $pivot[$mesinKey] = [];
            }
            foreach ($d->rincian as $r) {
                $jenis = $r->losstime->jenis_losstime;
                $jenisSet[$jenis] = true;
                if (!isset($pivot[$mesinKey][$jenis])) {
                    $pivot[$mesinKey][$jenis] = ['total_loss' => 0, 'total_over' => 0];
                }
                $pivot[$mesinKey][$jenis]['total_loss'] += $r->menit_loss;
                $pivot[$mesinKey][$jenis]['total_over'] += $r->menit_over;
            }
        }

        $jenisList = array_keys($jenisSet);
        sort($jenisList);

        $rows = [];
        foreach ($pivot as $mesinKey => $jenisPivot) {
            $row = ['mesin' => $mesinKey, 'total_loss' => 0, 'total_over' => 0];
            foreach ($jenisList as $jenis) {
                $row[$jenis . '_loss'] = $jenisPivot[$jenis]['total_loss'] ?? 0;
                $row[$jenis . '_over'] = $jenisPivot[$jenis]['total_over'] ?? 0;
                $row['total_loss'] += $row[$jenis . '_loss'];
                $row['total_over'] += $row[$jenis . '_over'];
            }
            $rows[] = $row;
        }

        return [
            'rows'       => $rows,
            'jenis_list' => $jenisList,
        ];
    }

    /**
     * Traffic light: Green >= 100%, Yellow 85-99%, Red < 85%
     */
    public function getTrafficLight(float $achievementPct): string
    {
        if ($achievementPct >= 100) return 'success';
        if ($achievementPct >= 85)  return 'warning';
        return 'danger';
    }

    private function durationHours(string $jamStart, string $jamFinish): float
    {
        $startSec    = $this->timeToSeconds($jamStart);
        $finishSec   = $this->timeToSeconds($jamFinish);
        $durationSec = $finishSec >= $startSec
            ? $finishSec - $startSec
            : (86400 - $startSec) + $finishSec;
        return $durationSec / 3600;
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        return ((int)($parts[0] ?? 0)) * 3600 + ((int)($parts[1] ?? 0)) * 60 + ((int)($parts[2] ?? 0));
    }
}
