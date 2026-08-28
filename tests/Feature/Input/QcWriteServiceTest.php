<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use App\Services\QcWriteService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QcWriteServiceTest extends TestCase
{
    use RefreshDatabase;

    private array $base;

    private MasterJenisNg $dented;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Insp Tulis', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $part = MasterPart::create(['part_no' => 'QW-P1', 'part_name' => 'QC Write Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->base = [
            'tgl_pengecekan' => '2026-07-28',
            'tgl_produksi_part' => '2026-07-27',
            'id_shift' => $shift->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00',
            'jam_finish' => '12:00',
            'qty_ok' => 90,
        ];

        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
    }

    public function test_menulis_baris_qc_dengan_cycle_time_terhitung(): void
    {
        $trx = app(QcWriteService::class)->simpan([...$this->base, 'ng' => [], 'ng_count' => 0]);

        // 4 jam = 14400 detik, 90 barang diperiksa → 160 detik per barang.
        $this->assertSame(160.0, (float) $trx->actual_cycle_time);
        $this->assertSame(0, (int) $trx->qty_ng);
        $this->assertSame(auth()->id(), $trx->created_by);
    }

    public function test_menulis_rincian_ng_bersama_barisnya(): void
    {
        $trx = app(QcWriteService::class)->simpan([
            ...$this->base,
            'ng' => [['id_jenis_ng' => $this->dented->id, 'qty' => 6]],
            'ng_count' => 1,
        ]);

        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(6, (int) $trx->fresh()->qty_ng);
    }

    public function test_kunci_bantu_tidak_ikut_tertulis_ke_kolom(): void
    {
        // `ng` dan `ng_count` adalah kunci form, bukan kolom tabel, dan
        // simpan() membuangnya lewat unset() sebelum create().
        //
        // Mode ketat DINYALAKAN khusus di test ini supaya asersinya benar-benar
        // bisa gagal. Tanpa itu test ini mustahil merah: `ng`/`ng_count` tidak
        // ada di $fillable dan app ini tidak memanggil
        // preventSilentlyDiscardingAttributes(), jadi mass assignment membuang
        // keduanya sendiri — unset() di simpan() bisa dicabut dan asersi apa pun
        // atas $trx->getAttributes() tetap hijau.
        //
        // Dengan mode ketat, kunci yang tidak fillable membuat create() MELEMPAR,
        // jadi hilangnya unset() langsung ketahuan.
        Model::preventSilentlyDiscardingAttributes();

        try {
            $trx = app(QcWriteService::class)->simpan([
                ...$this->base,
                'ng' => [['id_jenis_ng' => $this->dented->id, 'qty' => 3]],
                'ng_count' => 1,
            ]);
        } finally {
            Model::preventSilentlyDiscardingAttributes(false);
        }

        $this->assertArrayNotHasKey('ng', $trx->getAttributes());
        $this->assertArrayNotHasKey('ng_count', $trx->getAttributes());
    }
}
