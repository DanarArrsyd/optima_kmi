<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\MasterLosstime;

class MasterLosstimeSeeder extends Seeder
{
    public function run(): void
    {
        $losstimes = [
            ['kode' => '1', 'jenis_losstime' => 'Mesin_Rusak',      'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '2', 'jenis_losstime' => 'Tunggu_Crane',     'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '3', 'jenis_losstime' => 'Tunggu_Material',  'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '4', 'jenis_losstime' => 'Tunggu_Dies',      'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '5', 'jenis_losstime' => 'MP_Tidak_Ada',     'std_menit_default' => null, 'std_menit_progressive' => null],
            ['kode' => '7', 'jenis_losstime' => 'Trial_QC',         'std_menit_default' => 5,    'std_menit_progressive' => 5],
            ['kode' => '9', 'jenis_losstime' => 'Setting_Dies',     'std_menit_default' => 10,   'std_menit_progressive' => 15],
        ];
        foreach ($losstimes as $lt) {
            MasterLosstime::firstOrCreate(['kode' => $lt['kode']], $lt);
        }
    }
}
