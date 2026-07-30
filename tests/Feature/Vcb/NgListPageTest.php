<?php

namespace Tests\Feature\Vcb;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PHPUnit\Framework\ExpectationFailedException;
use Tests\TestCase;

class NgListPageTest extends TestCase
{
    use RefreshDatabase;

    private function seedNg(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $operator = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'Mesin A4', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'PN-001', 'part_name' => 'Bracket', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $dent = MasterJenisNg::create(['kode' => 'NG-01', 'nama_ng' => 'Dent']);
        $burr = MasterJenisNg::create(['kode' => 'NG-02', 'nama_ng' => 'Burr']);

        $trx = TransaksiProduksi::create([
            'tanggal_produksi' => now()->toDateString(), 'id_shift' => $shift->id,
            'id_karyawan' => $operator->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 100, 'qty_ng' => 8,
            'created_by' => $user->id,
        ]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $dent->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $trx->id, 'id_jenis_ng' => $burr->id, 'qty' => 3]);

        return compact('user', 'operator', 'dent', 'burr');
    }

    public function test_admin_bisa_membuka_kedua_daftar(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);

        $this->actingAs($admin)->get(route('vcb.ng-proses.daftar'))->assertOk();
        $this->actingAs($admin)->get(route('vcb.ng-total.daftar'))->assertOk();
    }

    public function test_guest_bisa_membuka_kedua_daftar(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->get(route('vcb.ng-proses.daftar'))->assertOk();
        $this->actingAs($guest)->get(route('vcb.ng-total.daftar'))->assertOk();
    }

    /** Guest tidak boleh export, sama seperti export lain di aplikasi ini. */
    public function test_guest_tidak_bisa_export_kedua_sisi(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->get(route('vcb.ng-proses.daftar.export'))->assertForbidden();
        $this->actingAs($guest)->get(route('vcb.ng-total.daftar.export'))->assertForbidden();
    }

    public function test_staff_qc_tidak_bisa_membuka_daftar_produksi(): void
    {
        $qc = User::factory()->create(['role' => 'staff_qc']);

        $this->actingAs($qc)->get(route('vcb.ng-proses.daftar'))->assertForbidden();
        $this->actingAs($qc)->get(route('vcb.ng-total.daftar'))->assertOk();
    }

    public function test_staff_produksi_tidak_bisa_membuka_daftar_total(): void
    {
        $produksi = User::factory()->create(['role' => 'staff_produksi']);

        $this->actingAs($produksi)->get(route('vcb.ng-total.daftar'))->assertForbidden();
        $this->actingAs($produksi)->get(route('vcb.ng-proses.daftar'))->assertOk();
    }

    public function test_belum_login_dialihkan(): void
    {
        $this->get(route('vcb.ng-proses.daftar'))->assertRedirect(route('login'));
        $this->get(route('vcb.ng-total.daftar'))->assertRedirect(route('login'));
    }

    /**
     * Tanpa query param sama sekali harus 200 — filter semuanya opsional. Endpoint
     * `data` yang sudah ada memakai `required|date`; meniru itu di sini membuat
     * halaman 422 saat dibuka dari menu, dan membuat AuthorizationTest merah.
     */
    public function test_daftar_terbuka_tanpa_query_param_apa_pun(): void
    {
        $f = $this->seedNg();

        $this->actingAs($f['user'])
            ->get(route('vcb.ng-proses.daftar'))
            ->assertOk()
            ->assertSee('PN-001');
    }

    /**
     * Tanggal ditampilkan d/m/Y, sama dengan daftar LHP di /input/produksi.
     * Format ISO mentah (Y-m-d) datang langsung dari SQL dan tidak dipakai di
     * layar mana pun yang dibaca orang produksi.
     */
    public function test_tanggal_ditampilkan_format_hari_bulan_tahun(): void
    {
        $f = $this->seedNg();

        $response = $this->actingAs($f['user'])->get(route('vcb.ng-proses.daftar'))->assertOk();

        $response->assertSee(now()->format('d/m/Y'));
        $response->assertDontSee('<td class="ngl-kode">'.now()->format('Y-m-d').'</td>', false);
    }

    public function test_rentang_tanggal_default_dua_belas_bulan_terakhir(): void
    {
        $f = $this->seedNg();

        $response = $this->actingAs($f['user'])->get(route('vcb.ng-proses.daftar'));

        $response->assertOk();
        $response->assertViewHas('filter', function (array $filter) {
            return $filter['date_from'] === now()->modify('first day of -11 months')->format('Y-m-d')
                && $filter['date_to'] === now()->format('Y-m-d');
        });
    }

    public function test_filter_jenis_ng_menyaring_baris_yang_tampil(): void
    {
        $f = $this->seedNg();

        $this->actingAs($f['user'])
            ->get(route('vcb.ng-proses.daftar', ['jenis_ng_id' => $f['dent']->id]))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 1);
    }

    public function test_satu_sesi_dua_jenis_ng_tampil_dua_baris(): void
    {
        $f = $this->seedNg();

        $this->actingAs($f['user'])
            ->get(route('vcb.ng-proses.daftar'))
            ->assertOk()
            ->assertViewHas('rows', fn ($rows) => $rows->total() === 2);
    }

    public function test_tanggal_terbalik_ditolak(): void
    {
        $f = $this->seedNg();

        $this->actingAs($f['user'])
            ->get(route('vcb.ng-proses.daftar', ['date_from' => '2026-06-01', 'date_to' => '2026-01-01']))
            ->assertSessionHasErrors('date_to');
    }

    /**
     * Excel::fake() dipakai dengan sengaja. Menjalankan writer sungguhan di sini
     * membuat PHPUnit mati dengan "Premature end of PHP process" saat suite penuh
     * berjalan (lolos kalau test ini diisolasi — jadi mudah tidak ketahuan).
     * Yang perlu dikunci route ini cuma: dapat diakses, dan menyerahkan export yang
     * benar. Isi barisnya diuji tanpa Excel di NgListExportTest.
     */
    public function test_admin_bisa_export_kedua_sisi(): void
    {
        Excel::fake();
        $f = $this->seedNg();

        $this->actingAs($f['user'])->get(route('vcb.ng-proses.daftar.export'))->assertOk();
        $this->actingAs($f['user'])->get(route('vcb.ng-total.daftar.export'))->assertOk();

        Excel::assertDownloaded('daftar-ng-proses-'.now()->format('Ymd-His').'.xlsx');
        Excel::assertDownloaded('daftar-ng-total-'.now()->format('Ymd-His').'.xlsx');
    }

    public function test_export_menolak_tanggal_terbalik_sebelum_menghasilkan_file(): void
    {
        Excel::fake();
        $f = $this->seedNg();

        $this->actingAs($f['user'])
            ->get(route('vcb.ng-proses.daftar.export', ['date_from' => '2026-06-01', 'date_to' => '2026-01-01']))
            ->assertSessionHasErrors('date_to');

        // ExcelFake tidak punya assertNotDownloaded; assertDownloaded melempar
        // kalau file tak pernah diminta, jadi itu yang dipakai untuk membuktikan
        // validasi menghentikan alur sebelum export dibuat.
        $this->expectException(ExpectationFailedException::class);
        Excel::assertDownloaded('daftar-ng-proses-'.now()->format('Ymd-His').'.xlsx');
    }
}
