<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Filter per kolom di dock LHP Dandori.
 *
 * Yang diuji bukan tampilannya, melainkan bahwa tiap parameter benar-benar
 * MENYARING. Filter yang tampil rapi tapi tidak mempersempit hasil adalah
 * kegagalan senyap: pengguna mengira sedang melihat data tersaring.
 */
class DandoriFilterTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx = [];

    protected function setUp(): void
    {
        parent::setUp();

        $u = User::factory()->create(['role' => 'staff_produksi']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00']);

        $opA = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'ANDI', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $opB = MasterKaryawan::create(['nik' => 'K2', 'nama_karyawan' => 'BUDI', 'departemen' => 'Produksi', 'is_aktif' => true]);

        $mA = MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'AMADA', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $mB = MasterMesin::create(['id_mesin' => 'B-001', 'nama_mesin' => 'AIDA', 'line' => 'B', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);

        $ltA = MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'standar_menit' => 30]);
        $ltB = MasterLosstime::create(['kode' => 'LT2', 'jenis_losstime' => 'TRIAL', 'standar_menit' => 20]);

        $pA = MasterPart::create(['part_no' => 'PN-A', 'part_name' => 'PART ALFA', 'customer' => 'Honda']);
        $pB = MasterPart::create(['part_no' => 'PN-B', 'part_name' => 'PART BETA', 'customer' => 'Toyota']);
        $ppA = MasterPartProses::create(['id_part' => $pA->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 100, 'sph_standar_qc' => 50]);
        $ppB = MasterPartProses::create(['id_part' => $pB->id, 'id_proses' => 2, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 100, 'sph_standar_qc' => 50]);

        $baris = function ($op, $mes, $lt, $pp) use ($shift, $u) {
            return TransaksiDandoriRincian::create([
                'tanggal' => now()->toDateString(), 'id_shift' => $shift->id,
                'id_karyawan' => $op->id, 'id_mesin' => $mes->id,
                'id_part_proses' => $pp->id, 'id_losstime' => $lt->id,
                'actual_menit' => 40, 'std_menit' => 30, 'over_menit' => 10,
                'created_by' => $u->id,
            ]);
        };

        $this->ctx = [
            'user' => $u,
            'a' => $baris($opA, $mA, $ltA, $ppA),   // ANDI / A-001 / GANTI DIES / PART ALFA / Honda
            'b' => $baris($opB, $mB, $ltB, $ppB),   // BUDI / B-001 / TRIAL      / PART BETA / Toyota
            'opA' => $opA, 'mA' => $mA, 'ltA' => $ltA, 'pA' => $pA, 'ppA' => $ppA,
        ];
    }

    public static function filterCases(): array
    {
        return [
            'operator' => ['filter_karyawan_id', 'opA'],
            'mesin' => ['filter_mesin_id', 'mA'],
            'jenis loss time' => ['filter_losstime_id', 'ltA'],
            'part' => ['filter_part_id', 'pA'],
            'proses' => ['filter_proses_id', 'ppA'],
        ];
    }

    #[DataProvider('filterCases')]
    public function test_filter_menyaring_hasil(string $param, string $kunci): void
    {
        $baris = $this->barisTabel([$param => $this->ctx[$kunci]->id]);

        $this->assertStringContainsString('ANDI', $baris, "{$param}: baris yang cocok hilang");
        $this->assertStringNotContainsString('BUDI', $baris, "{$param}: baris yang TIDAK cocok masih tampil — filter tidak menyaring");
    }

    public function test_filter_customer_menyaring_hasil(): void
    {
        $baris = $this->barisTabel(['filter_customer' => 'Honda']);

        $this->assertStringContainsString('ANDI', $baris);
        $this->assertStringNotContainsString('BUDI', $baris, 'filter customer tidak menyaring');
    }

    public function test_tanpa_filter_semua_baris_tampil(): void
    {
        $baris = $this->barisTabel([]);

        $this->assertStringContainsString('ANDI', $baris);
        $this->assertStringContainsString('BUDI', $baris);
    }

    public function test_centang_tanggal_dilepas_membuka_seluruh_riwayat(): void
    {
        /* Baris jauh di luar rentang bawaan (bulan berjalan). */
        $lama = TransaksiDandoriRincian::create([
            'tanggal' => now()->subMonths(8)->toDateString(),
            'id_shift' => $this->ctx['a']->id_shift,
            'id_karyawan' => $this->ctx['opA']->id,
            'id_mesin' => $this->ctx['mA']->id,
            'id_part_proses' => $this->ctx['ppA']->id,
            'id_losstime' => $this->ctx['ltA']->id,
            'actual_menit' => 40, 'std_menit' => 30, 'over_menit' => 10,
            'created_by' => $this->ctx['user']->id,
        ]);

        $tglLama = $lama->tanggal->format('d/m/Y');

        $default = $this->barisTabel([]);
        $this->assertStringNotContainsString($tglLama, $default, 'rentang bawaan seharusnya menyembunyikan baris lama');

        /* filter_apply menandai form dikirim; tanpa pakai_tanggal berarti
         * centangnya dilepas. */
        $tanpaTanggal = $this->barisTabel(['filter_apply' => 1]);
        $this->assertStringContainsString($tglLama, $tanpaTanggal, 'centang dilepas tapi rentang tanggal masih memotong hasil');
    }

    /**
     * Hanya isi <tbody> tabel.
     *
     * Nama operator juga muncul di daftar pilihan dock filter, jadi memeriksa
     * seluruh HTML akan selalu menemukan "BUDI" walaupun barisnya sudah
     * tersaring — asersi yang tidak pernah bisa gagal.
     */
    private function barisTabel(array $query): string
    {
        $html = $this->actingAs($this->ctx['user'])
            ->get(route('input.dandori.index', $query))->assertOk()->getContent();

        /* Dijangkarkan ke .erp-scroll, bukan ke <tbody> pertama: <tbody>
         * pertama di halaman ini milik tabel rincian loss time DI DALAM laci
         * input, bukan grid datanya. */
        $awal = strpos($html, 'class="erp-scroll"');
        $this->assertNotFalse($awal, 'grid data tidak ditemukan');
        $akhir = strpos($html, '</table>', $awal);

        return substr($html, $awal, $akhir - $awal);
    }
}
