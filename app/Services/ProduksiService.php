<?php
namespace App\Services;

class ProduksiService
{
    public function calculateDurationSeconds(string $jamStart, string $jamFinish): int
    {
        $startSec  = $this->timeToSeconds($jamStart);
        $finishSec = $this->timeToSeconds($jamFinish);
        if ($finishSec >= $startSec) {
            return $finishSec - $startSec;
        }
        return (86400 - $startSec) + $finishSec;
    }

    public function calculateDurationHours(string $jamStart, string $jamFinish): float
    {
        return round($this->calculateDurationSeconds($jamStart, $jamFinish) / 3600, 4);
    }

    public function calculateActualCycleTime(string $jamStart, string $jamFinish, int $qtyAktual): float
    {
        if ($qtyAktual === 0) {
            return 0.0;
        }
        return round($this->calculateDurationSeconds($jamStart, $jamFinish) / $qtyAktual, 2);
    }

    public function calculateTargetQty(string $jamStart, string $jamFinish, float $sphStandar): float
    {
        return round($this->calculateDurationHours($jamStart, $jamFinish) * $sphStandar, 2);
    }

    private function timeToSeconds(string $time): int
    {
        $parts = explode(':', $time);
        return ((int)($parts[0] ?? 0)) * 3600 + ((int)($parts[1] ?? 0)) * 60 + ((int)($parts[2] ?? 0));
    }
}
