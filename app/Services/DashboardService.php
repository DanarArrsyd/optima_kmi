<?php

namespace App\Services;

use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use Illuminate\Support\Carbon;

class DashboardService
{
    /**
     * VCB Produksi: aggregate by karyawan for date range.
     */
    public function getVcbProduksi(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = []): array
    {
        $records = TransaksiProduksi::with(['karyawan', 'partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
            ->get();

        return $records->groupBy('id_karyawan')->map(function ($rows) {
            $totalQtyAktual = $rows->sum('qty_aktual');
            $totalDurationHours = $rows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty = $rows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish) * ($r->partProses?->sph_standar_produksi ?? 0)
            );

            // Opsi A: rata-rata per sesi (bobot sama) — SPH ACT vs SPH STD per sesi
            $sessionsWithStd = $rows->filter(fn ($r) => ($r->partProses?->sph_standar_produksi ?? 0) > 0);
            $sessionCount = $sessionsWithStd->count();

            $avgSphStd = $sessionCount > 0
                ? round($sessionsWithStd->avg(fn ($r) => $r->partProses->sph_standar_produksi), 0)
                : 0;
            $avgActualSph = $sessionCount > 0
                ? round($sessionsWithStd->avg(fn ($r) => $r->sph_aktual), 0)
                : 0;
            $achievementPct = $sessionCount > 0
                ? round($sessionsWithStd->avg(fn ($r) => $r->sph_aktual / $r->partProses->sph_standar_produksi * 100
                ), 2)
                : 0;

            return [
                'id_karyawan' => $rows->first()->id_karyawan,
                'karyawan' => $rows->first()->karyawan?->nama_karyawan ?? '—',
                'total_qty_aktual' => $totalQtyAktual,
                'total_target_qty' => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct' => $achievementPct,
                'avg_actual_sph' => $avgActualSph,
                'avg_sph_std' => $avgSphStd,
                'traffic_light' => $this->getTrafficLight($achievementPct),
                'detail' => $rows->map(fn ($r) => [
                    'tanggal' => $r->tanggal_produksi instanceof \Carbon\Carbon
                        ? $r->tanggal_produksi->format('d/m/Y')
                        : \Carbon\Carbon::parse($r->tanggal_produksi)->format('d/m/Y'),
                    'mesin' => $r->mesin?->nama_mesin ?? '—',
                    'part' => $r->partProses?->part?->part_name ?? '-',
                    'proses' => $r->partProses?->nama_proses ?? '—',
                    'qty_aktual' => $r->qty_aktual,
                    'target_qty' => round($this->durationHours($r->jam_start, $r->jam_finish) * ($r->partProses?->sph_standar_produksi ?? 0), 0),
                    'actual_ct' => $r->actual_cycle_time,
                ])->values()->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Produksi: aggregate by DATE for a specific operator.
     * Returns daily achievement breakdown with per-session detail.
     */
    public function getVcbProduksiByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanId, ?int $shiftId = null, array $lines = []): array
    {
        $records = TransaksiProduksi::with(['partProses.part', 'mesin'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('id_karyawan', $karyawanId)
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
            ->orderBy('tanggal_produksi')
            ->orderBy('jam_start')
            ->get();

        return $records->groupBy('tanggal_produksi')->map(function ($dayRows, $date) {
            $totalQtyAktual = $dayRows->sum('qty_aktual');
            $totalDurationHours = $dayRows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty = $dayRows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish) * ($r->partProses?->sph_standar_produksi ?? 0)
            );

            // Opsi A: rata-rata per sesi (bobot sama)
            $sessionsWithStd = $dayRows->filter(fn ($r) => ($r->partProses?->sph_standar_produksi ?? 0) > 0);
            $sessionCount = $sessionsWithStd->count();

            $avgSphStd = $sessionCount > 0
                ? (int) round($sessionsWithStd->avg(fn ($r) => $r->partProses->sph_standar_produksi))
                : 0;
            $avgSphAct = $sessionCount > 0
                ? (int) round($sessionsWithStd->avg(fn ($r) => $r->sph_aktual))
                : 0;
            $achievementPct = $sessionCount > 0
                ? round($sessionsWithStd->avg(fn ($r) => $r->sph_aktual / $r->partProses->sph_standar_produksi * 100
                ), 1)
                : 0;

            return [
                'tanggal' => Carbon::parse($date)->format('d/m/y'),
                'tanggal_raw' => $date,
                'achievement_pct' => $achievementPct,
                'avg_sph_std' => $avgSphStd,
                'avg_sph_act' => $avgSphAct,
                'traffic_light' => $this->getTrafficLight($achievementPct),
                'sessions' => $dayRows->map(fn ($r) => [
                    'mesin' => $r->mesin?->id_mesin ?? '—',
                    'nama_mesin' => $r->mesin?->nama_mesin ?? '—',
                    'customer' => $r->partProses?->part?->customer ?? '—',
                    'part_no' => $r->partProses?->part?->part_no ?? '—',
                    'part_name' => $r->partProses?->part?->part_name ?? '—',
                    'id_proses' => $r->partProses?->id_proses ?? '—',
                    'nama_proses' => $r->partProses?->nama_proses ?? '—',
                    'jam' => substr((string) $r->jam_start, 0, 5).'–'.substr((string) $r->jam_finish, 0, 5),
                    'waktu_kerja' => round($this->durationHours($r->jam_start, $r->jam_finish), 1),
                    'sph_std' => (int) ($r->partProses?->sph_standar_produksi ?? 0),
                    'sph_act' => (int) $r->sph_aktual,
                    'pct' => ($r->partProses?->sph_standar_produksi ?? 0) > 0
                        ? round($r->sph_aktual / $r->partProses->sph_standar_produksi * 100, 1)
                        : 0,
                ])->values()->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Mesin: aggregate by mesin for date range.
     */
    public function getVcbMesin(Carbon $dateFrom, Carbon $dateTo, ?int $shiftId = null, array $lines = []): array
    {
        $records = TransaksiProduksi::with(['mesin', 'partProses'])
            ->whereBetween('tanggal_produksi', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->when(! empty($lines), fn ($q) => $q->whereHas('mesin', fn ($q2) => $q2->whereIn('line', $lines)))
            ->get();

        return $records->groupBy('id_mesin')->map(function ($rows) {
            $totalQtyAktual = $rows->sum('qty_aktual');
            $totalDurationHours = $rows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty = $rows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish) * ($r->partProses?->sph_standar_produksi ?? 0)
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyAktual / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyAktual / $totalDurationHours, 2)
                : 0;

            $mesin = $rows->first()->mesin;

            return [
                'mesin' => $mesin?->nama_mesin ?? '—',
                'id_mesin' => $mesin?->id_mesin ?? '—',
                'line' => $mesin?->line ?? '—',
                'tipe_mesin' => $mesin?->tipe_mesin ?? '—',
                'total_qty_aktual' => $totalQtyAktual,
                'total_target_qty' => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct' => $achievementPct,
                'avg_actual_sph' => $avgActualSph,
                'traffic_light' => $this->getTrafficLight($achievementPct),
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
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->get();

        return $records->groupBy('id_karyawan_qc')->map(function ($rows) {
            $totalQtyOk = $rows->sum('qty_ok');
            $totalQtyNg = $rows->sum('qty_ng');
            $totalQtyCheck = $totalQtyOk + $totalQtyNg;
            $totalDurationHours = $rows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty = $rows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish) * $r->partProses->sph_standar_qc
            );

            $achievementPct = $totalTargetQty > 0
                ? round(($totalQtyCheck / $totalTargetQty) * 100, 2)
                : 0;
            $avgActualSph = $totalDurationHours > 0
                ? round($totalQtyCheck / $totalDurationHours, 2)
                : 0;

            return [
                'id_karyawan_qc' => $rows->first()->id_karyawan_qc,
                'karyawan' => $rows->first()->karyawanQc->nama_karyawan,
                'total_qty_ok' => $totalQtyOk,
                'total_qty_ng' => $totalQtyNg,
                'total_qty_check' => $totalQtyCheck,
                'ng_rate' => $totalQtyCheck > 0 ? round($totalQtyNg / $totalQtyCheck * 100, 1) : 0,
                'total_target_qty' => round($totalTargetQty, 2),
                'total_duration_hours' => round($totalDurationHours, 2),
                'achievement_pct' => $achievementPct,
                'avg_actual_sph' => $avgActualSph,
                'traffic_light' => $this->getTrafficLight($achievementPct),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Quality: aggregate by DATE for a specific QC inspector.
     */
    public function getVcbQualityByDate(Carbon $dateFrom, Carbon $dateTo, int $karyawanQcId, ?int $shiftId = null): array
    {
        $records = TransaksiQc::with(['partProses.part'])
            ->whereBetween('tgl_pengecekan', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->where('id_karyawan_qc', $karyawanQcId)
            ->when($shiftId, fn ($q) => $q->where('id_shift', $shiftId))
            ->orderBy('tgl_pengecekan')
            ->orderBy('jam_start')
            ->get();

        return $records->groupBy('tgl_pengecekan')->map(function ($dayRows, $date) {
            $totalQtyOk = $dayRows->sum('qty_ok');
            $totalQtyNg = $dayRows->sum('qty_ng');
            $totalQtyCheck = $totalQtyOk + $totalQtyNg;
            $totalDurationHours = $dayRows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish));
            $totalTargetQty = $dayRows->sum(fn ($r) => $this->durationHours($r->jam_start, $r->jam_finish) * ($r->partProses?->sph_standar_qc ?? 0)
            );

            $achievementPct = $totalTargetQty > 0
                ? round($totalQtyCheck / $totalTargetQty * 100, 1)
                : 0;
            $avgSphStd = $totalDurationHours > 0
                ? (int) round($totalTargetQty / $totalDurationHours)
                : 0;
            $avgSphAct = $totalDurationHours > 0
                ? (int) round($totalQtyCheck / $totalDurationHours)
                : 0;

            return [
                'tanggal' => Carbon::parse($date)->format('d/m/y'),
                'tanggal_raw' => $date,
                'achievement_pct' => $achievementPct,
                'avg_sph_std' => $avgSphStd,
                'avg_sph_act' => $avgSphAct,
                'total_qty_ok' => $totalQtyOk,
                'total_qty_ng' => $totalQtyNg,
                'ng_rate' => $totalQtyCheck > 0 ? round($totalQtyNg / $totalQtyCheck * 100, 1) : 0,
                'traffic_light' => $this->getTrafficLight($achievementPct),
                'sessions' => $dayRows->map(fn ($r) => [
                    'customer' => $r->partProses?->part?->customer ?? '—',
                    'part_no' => $r->partProses?->part?->part_no ?? '—',
                    'part_name' => $r->partProses?->part?->part_name ?? '—',
                    'id_proses' => $r->partProses?->id_proses ?? '—',
                    'nama_proses' => $r->partProses?->nama_proses ?? '—',
                    'waktu_kerja' => round($this->durationHours($r->jam_start, $r->jam_finish), 1),
                    'qty_ok' => (int) $r->qty_ok,
                    'qty_ng' => (int) $r->qty_ng,
                    'ng_rate' => ($r->qty_ok + $r->qty_ng) > 0
                        ? round($r->qty_ng / ($r->qty_ok + $r->qty_ng) * 100, 1)
                        : 0,
                    'sph_std' => (int) ($r->partProses?->sph_standar_qc ?? 0),
                    'sph_act' => (int) $r->sph_aktual,
                    'pct' => ($r->partProses?->sph_standar_qc ?? 0) > 0
                        ? round($r->sph_aktual / $r->partProses->sph_standar_qc * 100, 1)
                        : 0,
                ])->values()->toArray(),
            ];
        })->values()->toArray();
    }

    /**
     * VCB Dandori: aggregate menit_loss and menit_over by mesin × jenis_loss.
     */
    public function getVcbDandori(string $dateFrom, string $dateTo, array $mesinIds = []): array
    {
        $records = TransaksiDandoriRincian::with(['mesin', 'losstime'])
            ->whereDate('tanggal', '>=', $dateFrom)
            ->whereDate('tanggal', '<=', $dateTo)
            ->when(! empty($mesinIds), fn ($q) => $q->whereIn('id_mesin', $mesinIds))
            ->orderBy('tanggal')
            ->get();

        $totalActual = 0.0;
        $totalOver = 0.0;
        $byJenis = [];   // kode  => [kode, jenis, actual, over]
        $byMesin = [];   // mesin DB id => [id_mesin, nama_mesin, actual, over]
        $byWeek = [];   // "WEEK N (Mon)" => [label, sort_key, actual, over]
        $byMonth = [];   // "Jan 2026" => [label, sort_key, actual, over]

        foreach ($records as $r) {
            $actual = (float) $r->actual_menit;
            $over = (float) $r->over_menit;
            $totalActual += $actual;
            $totalOver += $over;

            /* ── By jenis loss time ─────────────────── */
            $kode = $r->losstime->kode;
            $jenis = $r->losstime->jenis_losstime;
            if (! isset($byJenis[$kode])) {
                $byJenis[$kode] = ['kode' => $kode, 'jenis' => $jenis, 'actual' => 0.0, 'over' => 0.0];
            }
            $byJenis[$kode]['actual'] += $actual;
            $byJenis[$kode]['over'] += $over;

            /* ── By mesin ───────────────────────────── */
            $mid = $r->id_mesin;
            if (! isset($byMesin[$mid])) {
                $byMesin[$mid] = [
                    'id_mesin' => $r->mesin->id_mesin,
                    'nama_mesin' => $r->mesin->nama_mesin,
                    'actual' => 0.0, 'over' => 0.0,
                ];
            }
            $byMesin[$mid]['actual'] += $actual;
            $byMesin[$mid]['over'] += $over;

            /* ── By ISO week ────────────────────────── */
            $tanggal = $r->tanggal; // Carbon date
            $weekNum = (int) $tanggal->format('W');
            $yearWeek = $tanggal->format('o').sprintf('%02d', $weekNum); // e.g. 202620
            $weekLbl = 'W'.$weekNum.' ('.$tanggal->startOfWeek()->format('d M').')';
            if (! isset($byWeek[$yearWeek])) {
                $byWeek[$yearWeek] = ['label' => $weekLbl, 'sort' => $yearWeek, 'actual' => 0.0, 'over' => 0.0];
            }
            $byWeek[$yearWeek]['actual'] += $actual;
            $byWeek[$yearWeek]['over'] += $over;

            /* ── By month ───────────────────────────── */
            $monthKey = $r->tanggal->format('Y-m');
            $monthLbl = $r->tanggal->format('M Y');
            if (! isset($byMonth[$monthKey])) {
                $byMonth[$monthKey] = ['label' => $monthLbl, 'sort' => $monthKey, 'actual' => 0.0, 'over' => 0.0];
            }
            $byMonth[$monthKey]['actual'] += $actual;
            $byMonth[$monthKey]['over'] += $over;
        }

        /* Sort: jenis & mesin by over desc; week & month by date asc */
        usort($byJenis, fn ($a, $b) => $b['over'] <=> $a['over']);
        usort($byMesin, fn ($a, $b) => $b['over'] <=> $a['over']);
        ksort($byWeek);
        ksort($byMonth);

        /* Round all floats */
        $round = fn ($arr) => array_map(function ($row) {
            $row['actual'] = round($row['actual'], 1);
            $row['over'] = round($row['over'], 1);

            return $row;
        }, array_values($arr));

        return [
            'total_actual' => round($totalActual, 1),
            'total_over' => round($totalOver, 1),
            'by_jenis' => $round($byJenis),
            'by_mesin' => $round($byMesin),
            'by_week' => $round($byWeek),
            'by_month' => $round($byMonth),
        ];
    }

    /**
     * Traffic light: Green >= 100%, Yellow 85-99%, Red < 85%
     */
    public function getTrafficLight(float $achievementPct): string
    {
        if ($achievementPct > 100) {
            return 'info';
        }
        if ($achievementPct >= 100) {
            return 'success';
        }

        return 'danger';
    }

    private function durationHours(string $jamStart, string $jamFinish): float
    {
        $startSec = $this->timeToSeconds($jamStart);
        $finishSec = $this->timeToSeconds($jamFinish);
        $durationSec = $finishSec >= $startSec
            ? $finishSec - $startSec
            : (86400 - $startSec) + $finishSec;

        return $durationSec / 3600;
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);

        return ((int) ($parts[0] ?? 0)) * 3600 + ((int) ($parts[1] ?? 0)) * 60 + ((int) ($parts[2] ?? 0));
    }
}
