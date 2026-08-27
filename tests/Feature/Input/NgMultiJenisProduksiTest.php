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

    /** Satu baris dikirim lewat endpoint batch. */
    private function simpanLhp(array $payload)
    {
        return $this->post(route('input.produksi.batch'), ['rows' => [$payload]]);
    }

    public function test_simpan_dengan_dua_jenis_ng(): void
    {
        $response = $this->actingAs($this->admin)->simpanLhp([
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

    public function test_jenis_ng_dobel_diterima_dan_qty_dijumlah(): void
    {
        // Operator boleh mencatat jenis NG yang sama dua kali dalam satu baris,
        // mis. ketemu penyok di dua waktu berbeda selama sesi itu. Dulu ditolak
        // validasi, dan itu memblokir kejadian yang wajar di lantai produksi.
        //
        // Tersimpan sebagai SATU baris dengan qty dijumlah: tabel
        // transaksi_produksi_ng punya indeks unik uniq_prod_ng
        // (id_transaksi_produksi, id_jenis_ng), jadi dua baris jenis sama tidak
        // bisa berdiri sendiri di sana — dan qty per jenis NG memang satu angka.
        $response = $this->actingAs($this->admin)->simpanLhp([
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->dented->id, 'qty' => 2],
            ],
        ]);

        $response->assertRedirect(route('input.produksi.index'));

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(7, (int) $trx->ngDetails->first()->qty);
        $this->assertSame(7, (int) $trx->qty_ng);
    }

    public function test_ada_ng_dicentang_tapi_baris_kosong_ditolak(): void
    {
        $response = $this->actingAs($this->admin)->simpanLhp([
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 0,
            'ng' => [],
        ]);

        $response->assertSessionHasErrors('rows.0.ng');
    }

    public function test_tanpa_ng_menyimpan_qty_ng_nol(): void
    {
        $this->actingAs($this->admin)->simpanLhp([
            ...$this->base,
            'ng_count' => 0,
        ]);

        $trx = TransaksiProduksi::latest('id')->first();
        $this->assertCount(0, $trx->ngDetails);
        $this->assertSame(0, (int) $trx->qty_ng);
    }

    public function test_edit_mengurangi_jenis_menurunkan_qty_ng(): void
    {
        $this->actingAs($this->admin)->simpanLhp([
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

    public function test_edit_jenis_ng_dobel_diterima_dan_qty_dijumlah(): void
    {
        // Form edit harus seragam dengan halaman batch yang sudah mencabut
        // larangannya. Kalau edit masih melarang sementara input membolehkan,
        // baris yang sah jadi tidak bisa dikoreksi.
        $this->actingAs($this->admin)->simpanLhp([
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 1,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
            ],
        ]);

        $trx = TransaksiProduksi::latest('id')->first();

        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), [
            ...$this->base,
            'ada_ng' => 1,
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->dented->id, 'qty' => 5],
                ['id_jenis_ng' => $this->dented->id, 'qty' => 3],
            ],
        ]);

        $response->assertSessionHasNoErrors();

        $trx->refresh();
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(8, (int) $trx->ngDetails->first()->qty);
        $this->assertSame(8, (int) $trx->qty_ng);
    }

    public function test_update_tanpa_field_ng_tidak_boleh_menghapus_detail(): void
    {
        // Simulasi checkbox "ada_ng" survive kegagalan Alpine (checked attribute
        // dirender server, browser tetap mengirim ada_ng=1) tapi baris ng[] tidak
        // pernah ter-render (hidup di dalam x-for Alpine), jadi field `ng` sama
        // sekali tidak ada di body POST.
        $this->actingAs($this->admin)->simpanLhp([
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
        $this->actingAs($this->admin)->simpanLhp([
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

    public function test_form_batch_tidak_lagi_pakai_field_qty_ng_legacy(): void
    {
        // Dulu menjangkar ke laci di index.blade.php (form penulis paling
        // sering dipakai). Laci itu dicabut (Task 5, LHP Produksi jadi
        // halaman batch penuh) — anchor pindah ke halaman batch, yang kini
        // satu-satunya form create dan me-render partial ng-rows yang sama.
        $response = $this->actingAs($this->admin)->get(route('input.produksi.create'));

        $response->assertOk();
        $response->assertDontSee('name="qty_ng"', false);
        $response->assertSee('ng[${i}][id_jenis_ng]', false);
    }

    public function test_update_ada_ng_absen_ng_absen_ng_count_stale_dengan_detail_asli_ditolak(): void
    {
        // Baris ini SUDAH punya 2 baris detail asli (bukan legacy). Skenario:
        // checkbox "ada_ng" di-untick oleh operator TAPI Alpine gagal/terpotong
        // sehingga hidden input ng_count tidak sempat ter-reset ke 0 — payload-nya
        // byte-identik dengan client yang rusak/terpotong: ada_ng absen, ng absen,
        // ng_count masih membawa angka lama (2). Sebelum perbaikan, cross-check
        // hanya jalan saat ada_ng dicentang, jadi request ini lolos begitu saja
        // dan syncProduksi([]) menghapus 2 baris detail + menulis qty_ng = 0 tanpa
        // error apa pun. Setelah perbaikan, cross-check jalan tanpa syarat dan
        // menolak payload ini.
        $this->actingAs($this->admin)->simpanLhp([
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
        $this->assertCount(2, $trx->ngDetails);

        $payload = $this->base;
        // sengaja TIDAK menyertakan 'ada_ng' maupun 'ng' — hanya ng_count stale.
        $payload['ng_count'] = 2;

        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), $payload);

        $response->assertSessionHasErrorsIn('updateProduksi', 'ng_count');
        $trx->refresh();
        $this->assertSame(7, (int) $trx->qty_ng, 'qty_ng tidak boleh ter-nol oleh ng_count stale saat ada_ng absen.');
        $this->assertCount(2, $trx->ngDetails);
    }

    public function test_update_untick_genuine_ada_ng_absen_ng_absen_ng_count_nol_berhasil(): void
    {
        // Jalur eksplisit operator yang sah: checkbox "ada_ng" di-untick, Alpine
        // benar-benar mengosongkan rows sehingga ng_count ikut jadi 0 — payload
        // yang dikirim: ada_ng absen, ng absen, ng_count=0. Ini HARUS tetap
        // berhasil dan menol-kan qty_ng; perbaikan gerbang tidak boleh menolak
        // jalur operator yang genuine ini.
        $this->actingAs($this->admin)->simpanLhp([
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
        // sengaja TIDAK menyertakan 'ada_ng' maupun 'ng' — ng_count=0 konsisten.
        $payload['ng_count'] = 0;

        $response = $this->actingAs($this->admin)->put(route('input.produksi.update', $trx), $payload);

        $response->assertSessionDoesntHaveErrors();
        $trx->refresh();
        $this->assertSame(0, (int) $trx->qty_ng);
        $this->assertCount(0, $trx->ngDetails);
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
