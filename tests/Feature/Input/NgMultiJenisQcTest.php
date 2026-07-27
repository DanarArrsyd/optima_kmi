<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgMultiJenisQcTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $base;

    private MasterJenisNg $dented;

    private MasterJenisNg $crack;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Insp Multi', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $part = MasterPart::create(['part_no' => 'QC-P1', 'part_name' => 'QC Part', 'customer' => 'Toyota']);
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
        $this->crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);
    }

    public function test_simpan_dengan_dua_jenis_ng(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 6],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
            ],
        ]);

        $response->assertRedirect(route('input.qc.index'));

        $trx = TransaksiQc::latest('id')->first();
        $this->assertCount(2, $trx->ngDetails);
        $this->assertSame(10, (int) $trx->qty_ng);
    }

    public function test_tanpa_ng_menyimpan_qty_ng_nol(): void
    {
        $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng_count' => 0,
        ]);

        $trx = TransaksiQc::latest('id')->first();
        $this->assertCount(0, $trx->ngDetails);
        $this->assertSame(0, (int) $trx->qty_ng);
    }

    public function test_qty_ok_nol_dan_tanpa_ng_ditolak(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'qty_ok' => 0,
            'ng_count' => 0,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('qty_ok');
    }

    public function test_qty_ok_nol_tapi_ada_ng_diterima(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'qty_ok' => 0,
            'ng_count' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 3],
            ],
        ]);

        $response->assertRedirect(route('input.qc.index'));
        $this->assertSame(3, (int) TransaksiQc::latest('id')->first()->qty_ng);
    }

    public function test_jenis_ng_dobel_ditolak_validasi(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 6],
                ['id_jenis_ng' => $this->dented->id, 'qty' => 4],
            ],
        ]);

        $response->assertSessionHasErrors('ng.1.id_jenis_ng');
    }

    public function test_ng_count_kosong_akibat_alpine_gagal_ditolak(): void
    {
        // Simulasi Alpine gagal boot: binding :value="rows.length" tidak pernah
        // dievaluasi, browser mengirim string kosong untuk ng_count, dan baris
        // ng[] (hidup di dalam x-for) sama sekali tidak ter-render.
        $response = $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng_count' => '',
        ]);

        $response->assertSessionHasErrors('ng_count');
        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_baris_legacy_tanpa_detail_tidak_boleh_disimpan_tanpa_klasifikasi(): void
    {
        $trx = TransaksiQc::create([
            ...$this->base,
            'jam_start' => '08:00:00',
            'jam_finish' => '12:00:00',
            'qty_ng' => 7,
            'created_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)->put(route('input.qc.update', $trx), [
            ...$this->base,
            'ng_count' => 0,
            'ng' => [],
        ]);

        // UpdateQcRequest pakai errorBag 'updateQc' (memisahkan error form edit
        // dari form create di halaman index) — bukan bag 'default'.
        $response->assertSessionHasErrorsIn('updateQc', 'ng');
        $this->assertSame(7, (int) $trx->fresh()->qty_ng);
    }

    public function test_update_ng_count_kosong_akibat_alpine_gagal_tidak_menghapus_detail(): void
    {
        $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 6],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
            ],
        ]);

        $trx = TransaksiQc::latest('id')->first();
        $this->assertSame(10, (int) $trx->qty_ng);

        $payload = $this->base;
        $payload['ng_count'] = '';
        // catatan: sengaja TIDAK menyertakan key 'ng' sama sekali, meniru Alpine
        // yang gagal boot sehingga x-for tak pernah merender baris ng[].

        $response = $this->actingAs($this->admin)->put(route('input.qc.update', $trx), $payload);

        $response->assertSessionHasErrorsIn('updateQc', 'ng_count');
        $trx->refresh();
        $this->assertSame(10, (int) $trx->qty_ng);
        $this->assertCount(2, $trx->ngDetails);
    }

    public function test_update_ng_count_klaim_dua_baris_padahal_ng_hilang_dan_detail_sudah_ada_ditolak(): void
    {
        // Baris ini SUDAH punya 2 baris detail (bukan baris legacy) — guard
        // legacy di UpdateQcRequest hanya menyala saat ngDetails()->count() === 0,
        // jadi tidak menjangkau kasus ini.
        $this->actingAs($this->admin)->post(route('input.qc.store'), [
            ...$this->base,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 6],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
            ],
        ]);

        $trx = TransaksiQc::latest('id')->first();
        $this->assertSame(10, (int) $trx->qty_ng);
        $this->assertCount(2, $trx->ngDetails);

        // ng_count=2 adalah integer valid (lolos required|integer|min:0) tapi
        // ng[] sama sekali tidak ikut terkirim — body terpotong / request gagal
        // sebagian setelah Alpine sempat menghitung 2 baris.
        $response = $this->actingAs($this->admin)->put(route('input.qc.update', $trx), [
            ...$this->base,
            'ng_count' => 2,
        ]);

        $response->assertSessionHasErrorsIn('updateQc', 'ng_count');
        $trx->refresh();
        $this->assertSame(10, (int) $trx->qty_ng, 'qty_ng tidak boleh ter-nol oleh ng_count yang tidak cocok dengan ng[] yang terkirim.');
        $this->assertCount(2, $trx->ngDetails);
    }

    public function test_drawer_index_tidak_lagi_pakai_field_qty_ng_legacy(): void
    {
        $response = $this->actingAs($this->admin)->get(route('input.qc.index'));

        $response->assertOk();
        $response->assertDontSee('name="qty_ng"', false);
        $response->assertSee('ng[${i}][id_jenis_ng]', false);
    }
}
