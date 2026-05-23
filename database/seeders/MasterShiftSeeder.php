<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\MasterShift;

class MasterShiftSeeder extends Seeder
{
    public function run(): void
    {
        $shifts = [
            ['nama_shift' => 'Shift 1', 'jam_start' => '06:00:00', 'jam_finish' => '14:00:00'],
            ['nama_shift' => 'Shift 2', 'jam_start' => '14:00:00', 'jam_finish' => '22:00:00'],
            ['nama_shift' => 'Shift 3', 'jam_start' => '22:00:00', 'jam_finish' => '06:00:00'],
        ];
        foreach ($shifts as $shift) {
            MasterShift::firstOrCreate(['nama_shift' => $shift['nama_shift']], $shift);
        }
    }
}
