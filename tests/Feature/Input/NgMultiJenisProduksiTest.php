<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NgMultiJenisProduksiTest extends TestCase
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
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Op Multi', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'MJ-01', 'nama_mesin' => 'Press MJ', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'MJ-P1', 'part_name' => 'Multi Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->base = [
            'tanggal_produksi' => '2026-07-28',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00',
            'jam_finish' => '12:00',
            'qty_aktual' => 100,
        ];

        $this->dented = MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented']);
        $this->crack = MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack']);
    }

    public function test_simpan_dengan_dua_jenis_ng(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ],
        ]);

        $response->assertRedirect(route('input.produksi.index'));

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertCount(2, $trx->ngDetails);
        $this->assertSame(7, (int) $trx->qty_ng);
    }

    public function test_jenis_ng_dobel_ditolak_validasi(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->dented->id, 'qty' => 2],
            ],
        ]);

        $response->assertSessionHasErrors('ng.1.id_jenis_ng');
        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_ada_ng_dicentang_tapi_baris_kosong_ditolak(): void
    {
        $response = $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 0,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('ng');
    }

    public function test_tanpa_ng_menyimpan_qty_ng_nol(): void
    {
        $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ng_count' => 0,
        ]);

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertCount(0, $trx->ngDetails);
        $this->assertSame(0, (int) $trx->qty_ng);
    }

    public function test_edit_mengurangi_jenis_menurunkan_qty_ng(): void
    {
        $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ],
        ]);

        $trx = TransaksiProduksi::latest('id')->first();

        $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->crack->id, 'qty' => 4],
            ],
        ]);

        $trx->refresh();
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(4, (int) $trx->qty_ng);
    }

    public function test_update_tanpa_field_ng_tidak_boleh_menghapus_detail(): void
    {
        // Simulasi checkbox "ada_ng" survive kegagalan Alpine (checked attribute
        // dirender server, browser tetap mengirim ada_ng=1) tapi baris ng[] tidak
        // pernah ter-render (hidup di dalam x-for Alpine), jadi field `ng` sama
        // sekali tidak ada di body POST.
        $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ],
        ]);

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertSame(7, (int) $trx->qty_ng);

        $payload = $this->base;
        $payload['ada_ng'] = 1;
        // catatan: sengaja TIDAK menyertakan key 'ng' maupun 'ng_count' sama
        // sekali — meniru kegagalan Alpine total (komponen tidak pernah boot,
        // hidden input ng_count juga tidak pernah dievaluasi/terkirim).

        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), $payload);

        $trx->refresh();

        $response->assertSessionHasErrorsIn('updateProduksi', 'ng');
        $this->assertSame(7, (int) $trx->qty_ng);
        $this->assertCount(2, $trx->ngDetails);
    }

    public function test_update_ng_count_klaim_dua_baris_padahal_ng_hilang_dan_detail_sudah_ada_ditolak(): void
    {
        // Baris ini SUDAH punya 2 baris detail (bukan baris legacy) — guard
        // legacy di UpdateProduksiRequest hanya menyala saat
        // ngDetails()->count() === 0, jadi tidak menjangkau kasus ini.
        $this->actingAs($this->admin)->post(route('input.produksi.store'), [
            ...$this->base,
            'ng_count' => 2,
            'ada_ng' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->crack->id, 'qty' => 2],
            ],
        ]);

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertSame(7, (int) $trx->qty_ng);
        $this->assertCount(2, $trx->ngDetails);

        // ng_count=2 adalah integer valid (lolos required|integer|min:0), ada_ng
        // masih tercentang, tapi ng[] sama sekali tidak ikut terkirim — body
        // terpotong / request gagal sebagian setelah Alpine sempat menghitung
        // 2 baris.
        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
        ]);

        $response->assertSessionHasErrorsIn('updateProduksi', 'ng_count');
        $trx->refresh();
        $this->assertSame(7, (int) $trx->qty_ng, 'qty_ng tidak boleh ter-nol oleh ng_count yang tidak cocok dengan ng[] yang terkirim.');
        $this->assertCount(2, $trx->ngDetails);
    }

    public function test_drawer_index_tidak_lagi_pakai_field_qty_ng_legacy(): void
    {
        $response = $this->actingAs($this->admin)->get(route('input.produksi.index'));

        $response->assertOk();
        $response->assertDontSee('name="qty_ng"', false);
        $response->assertSee('ng[${i}][id_jenis_ng]', false);
    }

    public function test_baris_legacy_tanpa_detail_tidak_boleh_disimpan_tanpa_klasifikasi(): void
    {
        // Baris gaya lama: qty_ng terisi, nol baris detail — meniru data yang
        // di-skip migrasi karena id_jenis_ng NULL.
        $trx = TransaksiProduksi::create([...$this->base, 'jam_start' => '08:00:00', 'jam_finish' => '12:00:00', 'created_by' => $this->admin->id]);
        $trx->forceFill(['qty_ng' => 6])->save();

        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), [
            ...$this->base,
            'ng' => [],
        ]);

        // UpdateProduksiRequest pakai errorBag 'updateProduksi' (memisahkan error
        // form edit dari form create di halaman index) — bukan bag 'default'.
        $response->assertSessionHasErrorsIn('updateProduksi', 'ng');
        $this->assertSame(6, (int) $trx->fresh()->qty_ng);
    }
}
