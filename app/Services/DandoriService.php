<?php
namespace App\Services;
use App\Models\MasterLosstime;

class DandoriService
{
    public function hitungMenitOver(MasterLosstime $losstime, string $namaProses, int $menitLoss): int
    {
        $isProgressive = strtoupper(trim($namaProses)) === 'PROGRESSIVE';
        $stdMenit = $isProgressive
            ? $losstime->std_menit_progressive
            : $losstime->std_menit_default;

        if ($stdMenit === null) {
            return $menitLoss;
        }

        return max($menitLoss - $stdMenit, 0);
    }

    public function hitungStatus(int $totalAktualMenit, int $standardTimeMenit): string
    {
        return $totalAktualMenit > $standardTimeMenit ? 'OVER' : 'OK';
    }

    public function processRincian(array $rincianInput, string $namaProses): array
    {
        $losstimeMap = MasterLosstime::whereIn('id', collect($rincianInput)->pluck('id_losstime'))
            ->get()
            ->keyBy('id');

        return collect($rincianInput)->map(function ($item) use ($losstimeMap, $namaProses) {
            $losstime  = $losstimeMap->get($item['id_losstime']);
            $menitLoss = (int) $item['menit_loss'];
            $menitOver = $losstime ? $this->hitungMenitOver($losstime, $namaProses, $menitLoss) : $menitLoss;

            return [
                'id_losstime' => $item['id_losstime'],
                'menit_loss'  => $menitLoss,
                'menit_over'  => $menitOver,
            ];
        })->toArray();
    }
}
