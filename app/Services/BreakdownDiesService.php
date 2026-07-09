<?php
namespace App\Services;

use App\Models\TransaksiBreakdownDies;
use Illuminate\Support\Carbon;

class BreakdownDiesService
{
    private const ROMAN = [1=>'I',2=>'II',3=>'III',4=>'IV',5=>'V',6=>'VI',7=>'VII',8=>'VIII',9=>'IX',10=>'X',11=>'XI',12=>'XII'];

    public function toRoman(int $month): string
    {
        return self::ROMAN[$month] ?? 'I';
    }

    public function generateNoLkd(int $nomorUrut, string $tanggalBreakdown): string
    {
        $date = Carbon::parse($tanggalBreakdown);
        $roman = $this->toRoman((int) $date->month);
        $yy = $date->format('y');
        return "{$nomorUrut}/LKD/MTC/KMI/{$roman}/{$yy}";
    }

    public function nextNomorUrut(): int
    {
        // Highest leading number across existing no_lkd values, +1.
        $max = TransaksiBreakdownDies::withTrashed()->get('no_lkd')
            ->map(fn ($r) => (int) explode('/', (string) $r->no_lkd)[0])
            ->max();
        return ((int) $max) + 1;
    }

    public function hoursBetween(string $startDate, string $startTime, string $endDate, string $endTime): float
    {
        try {
            $start = Carbon::parse("{$startDate} {$startTime}");
            $end   = Carbon::parse("{$endDate} {$endTime}");
            // Carbon 3: diffInHours returns float when absolute=false
            $hours = $start->diffInHours($end, false);
            return max(0.0, round($hours, 2));
        } catch (\Throwable $e) {
            return 0.0;
        }
    }

    public function statusFromRepairHours(float $hours): string
    {
        if ($hours < 1) return 'Ringan';
        if ($hours < 3) return 'Sedang';
        return 'Berat';
    }

    public function computeDerived(array $in): array
    {
        $repair = $this->hoursBetween(
            $in['tanggal_mulai_perbaikan'], $in['jam_mulai_perbaikan'],
            $in['tanggal_selesai_perbaikan'], $in['jam_selesai_perbaikan']
        );
        $downtime = $this->hoursBetween(
            $in['tanggal_breakdown'], $in['jam_breakdown'],
            $in['tanggal_selesai_perbaikan'], $in['jam_selesai_perbaikan']
        );
        return [
            'repair_time'      => $repair,
            'total_downtime'   => $downtime,
            'status_breakdown' => $this->statusFromRepairHours($repair),
        ];
    }
}
