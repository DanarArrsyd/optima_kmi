<?php

namespace Tests\Feature;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Tombol "View Detail" di tiap baris daftar LHP Produksi, LHP Quality,
 * Breakdown Mesin, Breakdown Dies, dan halaman detail di baliknya.
 *
 * Hak akses detail = hak akses daftar: siapa pun yang melihat barisnya boleh
 * membuka detailnya (termasuk guest di LHP), dan role yang tak boleh melihat
 * daftar juga tak boleh membuka detail.
 */
class ViewDetailBarisTest extends TestCase
{
    use RefreshDatabase;

    /** Teks panjang yang di daftar dipotong Str::limit(…, 70). */
    private const TINDAKAN_PANJANG = 'DIE BLANK DIGRINDING 0.35 MM, DIE PIERCE DIGRINDING 0.2 MM DAN DISHIMMING, '
        .'SETTING CLEARANCE DIE TERHADAP PUNCH, TRIAL 20 PCS OK, EKOR KALIMAT YANG DULU TERPOTONG';

    private MasterPartProses $proses;

    private MasterMesin $mesin;

    private TransaksiProduksi $lhp;

    private TransaksiQc $qc;

    private TransaksiBreakdownMesin $bdMesin;

    private TransaksiBreakdownDies $bdDies;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $operator = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $inspector = MasterKaryawan::create(['nik' => 'Q1', 'nama_karyawan' => 'INSPECTOR SATU', 'departemen' => 'QC', 'is_aktif' => true]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'A4', 'nama_mesin' => 'PRESS AIDA 200T', 'line' => 'A',
            'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => '55192-BZ230', 'part_name' => 'BRACKET JUNCTION BLOCK MTG', 'customer' => 'ADM']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 400,
        ]);
        $ng = MasterJenisNg::create(['kode' => 'NG01', 'nama_ng' => 'PENYOK', 'kategori' => 'Visual']);
        $penginput = User::factory()->create(['name' => 'PENGINPUT LHP']);

        $this->lhp = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-11-03', 'id_shift' => $shift->id, 'id_karyawan' => $operator->id,
            'id_mesin' => $this->mesin->id, 'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00', 'jam_finish' => '09:00', 'qty_aktual' => 900, 'qty_ng' => 7,
            'actual_cycle_time' => 8, 'staff_note' => 'CATATAN STAFF RAHASIA', 'created_by' => $penginput->id,
        ]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $this->lhp->id, 'id_jenis_ng' => $ng->id, 'qty' => 7]);

        $this->qc = TransaksiQc::create([
            'tgl_pengecekan' => '2026-11-04', 'tgl_produksi_part' => '2026-11-03', 'id_shift' => $shift->id,
            'id_karyawan_qc' => $inspector->id, 'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00', 'jam_finish' => '08:00', 'qty_ok' => 300, 'qty_ng' => 5, 'actual_cycle_time' => 11,
            'created_by' => $penginput->id,
        ]);
        TransaksiQcNg::create(['id_transaksi_qc' => $this->qc->id, 'id_jenis_ng' => $ng->id, 'qty' => 5]);

        $breakdown = [
            'tanggal_breakdown' => '2026-11-07', 'jam_breakdown' => '08:15:00',
            'tanggal_mulai_perbaikan' => '2026-11-07', 'jam_mulai_perbaikan' => '08:30:00',
            'tanggal_selesai_perbaikan' => '2026-11-07', 'jam_selesai_perbaikan' => '11:00:00',
            'penyebab_breakdown' => 'PUNCH AUS', 'tindakan_perbaikan' => self::TINDAKAN_PANJANG,
            'status_breakdown' => 'Sedang', 'pic_maintenance' => 'TEKNISI SATU',
            'repair_time' => 2.5, 'total_downtime' => 2.75,
        ];
        $this->bdMesin = TransaksiBreakdownMesin::create($breakdown + [
            'no_lkm' => '230/LKM/MTC/KMI/XI/26', 'id_mesin' => $this->mesin->id, 'problem_mesin' => 'OLI BOCOR',
        ]);
        $this->bdDies = TransaksiBreakdownDies::create($breakdown + [
            'no_lkd' => '226/LKD/MTC/KMI/XI/26', 'id_part_proses' => $this->proses->id, 'problem_dies' => 'BURRY',
        ]);

        /* 01:30 UTC = 08:30 WIB — membuktikan konversi di lapisan tampilan. */
        foreach ([$this->lhp, $this->qc, $this->bdMesin, $this->bdDies] as $m) {
            $m->forceFill(['created_at' => Carbon::parse('2026-11-16 01:30:00', 'UTC')])->saveQuietly();
        }
    }

    private function sebagai(string $role): static
    {
        return $this->actingAs(User::factory()->create(['role' => $role]));
    }

    /** @return array<string, array{0: string, 1: string}> [halaman => [route daftar, route detail]] */
    private function halaman(): array
    {
        return [
            'produksi' => ['input.produksi.index', 'input.produksi.show'],
            'qc' => ['input.qc.index', 'input.qc.show'],
            'breakdown' => ['maintenance.breakdown.index', 'maintenance.breakdown.show'],
            'breakdown-dies' => ['maintenance.breakdown-dies.index', 'maintenance.breakdown-dies.show'],
        ];
    }

    private function record(string $halaman)
    {
        return match ($halaman) {
            'produksi' => $this->lhp,
            'qc' => $this->qc,
            'breakdown' => $this->bdMesin,
            'breakdown-dies' => $this->bdDies,
        };
    }

    public static function boleh(): array
    {
        return [
            'produksi admin' => ['produksi', 'admin'],
            'produksi staff_produksi' => ['produksi', 'staff_produksi'],
            'produksi guest' => ['produksi', 'guest'],
            'qc admin' => ['qc', 'admin'],
            'qc staff_qc' => ['qc', 'staff_qc'],
            'qc guest' => ['qc', 'guest'],
            'breakdown admin' => ['breakdown', 'admin'],
            'breakdown staff_maintenance' => ['breakdown', 'staff_maintenance'],
            'breakdown manager' => ['breakdown', 'manager'],
            'breakdown-dies admin' => ['breakdown-dies', 'admin'],
            'breakdown-dies staff_maintenance' => ['breakdown-dies', 'staff_maintenance'],
            'breakdown-dies manager' => ['breakdown-dies', 'manager'],
        ];
    }

    public static function ditolak(): array
    {
        return [
            'produksi staff_qc' => ['produksi', 'staff_qc'],
            'produksi manager' => ['produksi', 'manager'],
            'produksi staff_maintenance' => ['produksi', 'staff_maintenance'],
            'qc staff_produksi' => ['qc', 'staff_produksi'],
            'qc manager' => ['qc', 'manager'],
            'breakdown guest' => ['breakdown', 'guest'],
            'breakdown staff_produksi' => ['breakdown', 'staff_produksi'],
            'breakdown-dies guest' => ['breakdown-dies', 'guest'],
            'breakdown-dies staff_qc' => ['breakdown-dies', 'staff_qc'],
        ];
    }

    #[DataProvider('boleh')]
    public function test_role_yang_melihat_daftar_melihat_tombol_dan_bisa_membuka_detail(string $halaman, string $role): void
    {
        [$daftar, $detail] = $this->halaman()[$halaman];
        $record = $this->record($halaman);
        $this->sebagai($role);

        /* Rentang tanggal dibuka lebar supaya baris fixture pasti tampil. */
        $this->get(route($daftar, ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))
            ->assertOk()
            ->assertSee('View Detail')
            ->assertSee(route($detail, $record), false);

        $this->get(route($detail, $record))->assertOk();
    }

    #[DataProvider('ditolak')]
    public function test_role_tanpa_akses_daftar_ditolak_di_detail(string $halaman, string $role): void
    {
        [, $detail] = $this->halaman()[$halaman];
        $this->sebagai($role);

        $this->get(route($detail, $this->record($halaman)))->assertForbidden();
    }

    public function test_detail_lhp_produksi_menampilkan_field_kunci_dan_relasi_bukan_id(): void
    {
        $this->sebagai('staff_produksi');

        $this->get(route('input.produksi.show', $this->lhp))
            ->assertOk()
            ->assertSee('Detail LHP Produksi')
            ->assertSee('03/11/2026')
            ->assertSee('Shift 1')
            ->assertSee('OPERATOR SATU (K1)')
            ->assertSee('A4 — PRESS AIDA 200T')
            ->assertSee('55192-BZ230')
            ->assertSee('BRACKET JUNCTION BLOCK MTG')
            ->assertSee('BLANKING')
            ->assertSee('900 pcs')
            ->assertSee('NG01 — PENYOK: 7 pcs')
            ->assertSee('CATATAN STAFF RAHASIA')
            ->assertSee('PENGINPUT LHP · 16/11/2026 08:30 WIB')
            ->assertDontSee(route('input.produksi.edit', $this->lhp), false);
    }

    public function test_detail_lhp_produksi_guest_tanpa_catatan_staff_dan_tanpa_edit(): void
    {
        $this->sebagai('guest');

        $this->get(route('input.produksi.show', $this->lhp))
            ->assertOk()
            ->assertSee('OPERATOR SATU')
            ->assertDontSee('CATATAN STAFF RAHASIA')
            ->assertDontSee(route('input.produksi.edit', $this->lhp), false);
    }

    public function test_detail_admin_punya_tombol_edit(): void
    {
        $this->sebagai('admin');

        $this->get(route('input.produksi.show', $this->lhp))->assertSee(route('input.produksi.edit', $this->lhp), false);
        $this->get(route('maintenance.breakdown-dies.show', $this->bdDies))->assertSee(route('maintenance.breakdown-dies.edit', $this->bdDies), false);
    }

    public function test_detail_lhp_quality_menampilkan_field_kunci(): void
    {
        $this->sebagai('staff_qc');

        $this->get(route('input.qc.show', $this->qc))
            ->assertOk()
            ->assertSee('Detail LHP Quality')
            ->assertSee('04/11/2026')
            ->assertSee('INSPECTOR SATU (Q1)')
            ->assertSee('55192-BZ230')
            ->assertSee('300 pcs')
            ->assertSee('NG01 — PENYOK: 5 pcs')
            ->assertSee('16/11/2026 08:30 WIB');
    }

    public function test_detail_breakdown_mesin_menampilkan_uraian_utuh(): void
    {
        $this->sebagai('manager');

        /* Daftar memotong uraian — ekornya tidak ada di sana … */
        $this->get(route('maintenance.breakdown.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))
            ->assertDontSee('EKOR KALIMAT YANG DULU TERPOTONG');

        /* … tapi utuh di detail. */
        $this->get(route('maintenance.breakdown.show', $this->bdMesin))
            ->assertOk()
            ->assertSee('230/LKM/MTC/KMI/XI/26')
            ->assertSee('A4 — PRESS AIDA 200T')
            ->assertSee('07/11/2026 08:15')
            ->assertSee('07/11/2026 11:00')
            ->assertSee('OLI BOCOR')
            ->assertSee('PUNCH AUS')
            ->assertSee(self::TINDAKAN_PANJANG)
            ->assertSee('TEKNISI SATU')
            ->assertSee('16/11/2026 08:30 WIB');
    }

    public function test_detail_breakdown_dies_menampilkan_uraian_utuh(): void
    {
        $this->sebagai('staff_maintenance');

        $this->get(route('maintenance.breakdown-dies.index', ['date_from' => '2026-11-01', 'date_to' => '2026-11-30']))
            ->assertDontSee('EKOR KALIMAT YANG DULU TERPOTONG');

        $this->get(route('maintenance.breakdown-dies.show', $this->bdDies))
            ->assertOk()
            ->assertSee('226/LKD/MTC/KMI/XI/26')
            ->assertSee('55192-BZ230')
            ->assertSee('BRACKET JUNCTION BLOCK MTG')
            ->assertSee('BLANKING')
            ->assertSee('BURRY')
            ->assertSee(self::TINDAKAN_PANJANG)
            ->assertSee('16/11/2026 08:30 WIB');
    }

    public function test_tombol_kembali_membawa_filter_daftar(): void
    {
        $this->sebagai('admin');
        $backQuery = http_build_query(['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'page' => 2]);

        $this->get(route('maintenance.breakdown.show', $this->bdMesin).'?back_query='.urlencode($backQuery))
            ->assertSee(e(route('maintenance.breakdown.index', ['date_from' => '2026-07-01', 'date_to' => '2026-07-31', 'page' => 2])), false);
    }

    public function test_baris_terhapus_404(): void
    {
        $this->sebagai('admin');
        $this->lhp->delete();

        $this->get(route('input.produksi.show', $this->lhp))->assertNotFound();
    }

    /**
     * Rute detail terdaftar di grup daftar, SEBELUM produksi/create & /export.
     * Tanpa whereNumber, {produksi} menelan "create" → 404 model binding.
     */
    public function test_rute_statis_tidak_ditelan_rute_detail(): void
    {
        $this->sebagai('admin');

        $this->get('/input/produksi/create')->assertOk()->assertDontSee('Detail LHP Produksi');
        $this->get('/maintenance/breakdown/create')->assertOk()->assertDontSee('Detail Breakdown Mesin');
        $this->get('/maintenance/breakdown-dies/create')->assertOk()->assertDontSee('Detail Breakdown Dies');
    }
}
