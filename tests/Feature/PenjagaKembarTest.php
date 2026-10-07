<?php

namespace Tests\Feature;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterPartProsesRepair;
use App\Models\MasterPartProsesRepairProduksi;
use App\Models\MasterShift;
use App\Models\TransaksiBreakdownDies;
use App\Models\TransaksiBreakdownMesin;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiQc;
use App\Models\TransaksiRepair;
use App\Models\TransaksiRepairProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Peringatan data dobel (App\Services\PenjagaKembar).
 *
 * Probe prod 7 Okt 2026: satu batch LHP + dandori-nya masuk dua kali dengan
 * jarak 4 menit (admin mengirim ulang draft yang sudah tersimpan). Sebelum
 * penjaga ini, kiriman kedua selalu tertulis — test "kirim_ulang" di bawah
 * MERAH di kode lama (2 baris, bukan 1).
 */
class PenjagaKembarTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create(['role' => 'admin', 'name' => 'Admin Uji']));

        $part = MasterPart::create(['part_no' => 'K-1', 'part_name' => 'PART KEMBAR', 'customer' => 'Toyota']);
        $this->ref = [
            'shift' => MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00'])->id,
            'op' => MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'Budi Santoso', 'departemen' => 'Produksi', 'is_aktif' => true])->id,
            'qc' => MasterKaryawan::create(['nik' => 'K2', 'nama_karyawan' => 'Insp', 'departemen' => 'QC', 'is_aktif' => true])->id,
            'mesin' => MasterMesin::create(['id_mesin' => 'A9', 'nama_mesin' => 'MESIN A9', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100])->id,
            'proses' => MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250])->id,
            'repair' => MasterPartProsesRepair::create(['id_part' => $part->id, 'id_proses' => 'R1', 'nama_proses' => 'LAS', 'sph_standar_repair' => 100])->id,
            'repairProd' => MasterPartProsesRepairProduksi::create(['id_part' => $part->id, 'id_proses' => 'RP1', 'nama_proses' => 'LAS', 'sph_standar_repair_produksi' => 100])->id,
            'lt' => MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'Ganti Dies', 'std_menit_default' => 20, 'std_menit_progressive' => 35])->id,
        ];
    }

    public static function modul(): array
    {
        return [
            'lhp produksi' => ['produksi'],
            'lhp quality' => ['qc'],
            'dandori' => ['dandori'],
            'breakdown mesin' => ['breakdown_mesin'],
            'breakdown dies' => ['breakdown_dies'],
            'lhr repair' => ['repair'],
            'lhr repair produksi' => ['repair_produksi'],
        ];
    }

    /** [route batch, route create, model, satu baris draft] */
    private function cfg(string $modul): array
    {
        $r = $this->ref;
        $breakdown = [
            'nomor_urut' => 1, 'tanggal_breakdown' => '2026-07-28', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-28', 'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-28', 'jam_selesai_perbaikan' => '11:00',
            'penyebab_breakdown' => 'aus', 'tindakan_perbaikan' => 'ganti', 'pic_maintenance' => 'Budi',
        ];
        $lhr = ['id_shift' => $r['shift'], 'id_karyawan' => $r['op'], 'jam_start' => '07:00', 'jam_finish' => '09:00', 'qty_aktual' => 100];

        return match ($modul) {
            'produksi' => ['input.produksi.batch', 'input.produksi.create', TransaksiProduksi::class, [
                'tanggal_produksi' => '2026-07-28', 'id_shift' => $r['shift'], 'id_karyawan' => $r['op'], 'id_mesin' => $r['mesin'],
                'id_part_proses' => $r['proses'], 'jam_start' => '07:00', 'jam_finish' => '09:00', 'qty_aktual' => 1000, 'ng_count' => 0,
            ]],
            'qc' => ['input.qc.batch', 'input.qc.create', TransaksiQc::class, [
                'tgl_pengecekan' => '2026-07-28', 'tgl_produksi_part' => '2026-07-27', 'id_shift' => $r['shift'], 'id_karyawan_qc' => $r['qc'],
                'id_part_proses' => $r['proses'], 'jam_start' => '08:00', 'jam_finish' => '12:00', 'qty_ok' => 90, 'ng_count' => 0, 'ng' => [],
            ]],
            'dandori' => ['input.dandori.batch', 'input.dandori.create', TransaksiDandoriRincian::class, [
                'tanggal' => '2026-07-28', 'id_shift' => $r['shift'], 'id_karyawan' => $r['op'], 'id_mesin' => $r['mesin'],
                'id_part_proses' => $r['proses'], 'rincian' => [['id_losstime' => $r['lt'], 'actual_menit' => 25]],
            ]],
            'breakdown_mesin' => ['maintenance.breakdown.batch', 'maintenance.breakdown.create', TransaksiBreakdownMesin::class,
                $breakdown + ['id_mesin' => $r['mesin'], 'problem_mesin' => 'Motor overheat']],
            'breakdown_dies' => ['maintenance.breakdown-dies.batch', 'maintenance.breakdown-dies.create', TransaksiBreakdownDies::class,
                $breakdown + ['id_part_proses' => $r['proses'], 'problem_dies' => 'Punch patah']],
            'repair' => ['input.repair.batch', 'input.repair.create', TransaksiRepair::class,
                $lhr + ['tanggal_repair' => '2026-09-01', 'id_part_proses_repair' => $r['repair']]],
            'repair_produksi' => ['input.repair-produksi.batch', 'input.repair-produksi.create', TransaksiRepairProduksi::class,
                $lhr + ['tanggal_repair_produksi' => '2026-09-01', 'id_part_proses_repair_produksi' => $r['repairProd']]],
        };
    }

    /** Baris kedua yang beda kejadian (jam/menit lain), bukan dobel. */
    private function lain(string $modul, array $baris): array
    {
        return match ($modul) {
            'dandori' => ['rincian' => [['id_losstime' => $this->ref['lt'], 'actual_menit' => 30]]] + $baris,
            'breakdown_mesin', 'breakdown_dies' => ['nomor_urut' => 2, 'jam_breakdown' => '08:30'] + $baris,
            default => ['jam_start' => '13:00', 'jam_finish' => '14:00'] + $baris,
        };
    }

    /* Kiriman ulang yang No LKM/LKD-nya sudah dinomori ulang — dengan nomor
     * sama, cek tabrakan No LKM lama sudah menolaknya lebih dulu. */
    private function ulang(string $modul, array $baris): array
    {
        return in_array($modul, ['breakdown_mesin', 'breakdown_dies'], true) ? ['nomor_urut' => 7] + $baris : $baris;
    }

    private function kirim(string $modul, array $rows, array $tambahan = [])
    {
        [$batch, $create] = $this->cfg($modul);

        return $this->from(route($create))->post(route($batch), ['rows' => $rows] + $tambahan);
    }

    #[DataProvider('modul')]
    public function test_kirim_ulang_batch_yang_sudah_tersimpan_ditahan(string $modul): void
    {
        [, $create, $model, $baris] = $this->cfg($modul);
        $this->kirim($modul, [$baris])->assertSessionHasNoErrors();
        $this->assertSame(1, $model::count());

        $this->kirim($modul, [$this->lain($modul, $baris), $this->ulang($modul, $baris)])
            ->assertRedirect(route($create))
            ->assertSessionHasErrors('rows.1.kembar')
            ->assertSessionMissing('batch_tersimpan')
            ->assertSessionHas('kembar_bisa_dilewati', 1);

        $this->assertSame(1, $model::count(), 'batch dobel tetap tertulis — termasuk baris yang tidak kembar (harus utuh ditahan)');
    }

    #[DataProvider('modul')]
    public function test_tetap_simpan_melewati_peringatan(string $modul): void
    {
        [, , $model, $baris] = $this->cfg($modul);
        $this->kirim($modul, [$baris]);

        $this->kirim($modul, [$this->ulang($modul, $baris)], ['izinkan_kembar' => '1'])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('batch_tersimpan');

        $this->assertSame(2, $model::count());
    }

    /* LHP Produksi tidak ikut: dua baris identik di satu draft sudah ditolak
     * lebih dulu oleh aturan "jam tumpang tindih pada mesin yang sama". */
    #[DataProvider('modul')]
    public function test_dua_baris_identik_dalam_satu_draft_ditahan(string $modul): void
    {
        if ($modul === 'produksi') {
            $this->expectNotToPerformAssertions();

            return;
        }
        [, , $model, $baris] = $this->cfg($modul);
        $kedua = in_array($modul, ['breakdown_mesin', 'breakdown_dies'], true) ? ['nomor_urut' => 2] + $baris : $baris;

        $this->kirim($modul, [$baris, $kedua])->assertSessionHasErrors('rows.1.kembar');

        $this->assertStringContainsString('kembar dengan baris 1', session('errors')->first('rows.1.kembar'));
        $this->assertSame(0, $model::count());
    }

    #[DataProvider('modul')]
    public function test_halaman_batch_menampilkan_tombol_tetap_simpan_hanya_saat_ditahan(string $modul): void
    {
        [, $create] = $this->cfg($modul);

        $this->get(route($create))->assertOk()->assertDontSee('name="izinkan_kembar"', false);

        $this->withSession(['kembar_bisa_dilewati' => 1])->get(route($create))->assertOk()
            ->assertSee('form="formBatch" name="izinkan_kembar" value="1"', false)
            ->assertSee('Kemungkinan data dobel');
    }

    public function test_qty_beda_tetap_dianggap_dobel_karena_kejadiannya_sama(): void
    {
        [, , , $baris] = $this->cfg('produksi');
        $this->kirim('produksi', [$baris]);

        $this->kirim('produksi', [['qty_aktual' => 999] + $baris])->assertSessionHasErrors('rows.0.kembar');
    }

    public function test_pesan_menunjuk_data_lama_dengan_waktu_wib_dan_penginput(): void
    {
        $this->travelTo('2026-10-06 03:37:00');
        [, , , $baris] = $this->cfg('produksi');
        $this->kirim('produksi', [$baris]);
        $id = TransaksiProduksi::first()->id;

        $this->kirim('produksi', [$baris])->assertSessionHasErrors([
            'rows.0.kembar' => "Baris 1 identik dengan data yang sudah tersimpan (ID {$id}, diinput 06-10-2026 10:37 WIB, oleh Admin Uji).",
        ]);
    }

    public function test_jam_tersimpan_hh_mm_ss_tetap_tertangkap(): void
    {
        [, , , $baris] = $this->cfg('produksi');
        TransaksiProduksi::create(['jam_start' => '07:00:00', 'jam_finish' => '09:00:00', 'tanggal_produksi' => '2026-07-28 00:00:00',
            'actual_cycle_time' => 1, 'created_by' => auth()->id()] + $baris);

        $this->kirim('produksi', [$baris])->assertSessionHasErrors('rows.0.kembar');
    }

    public function test_data_yang_sudah_dihapus_tidak_menghalangi(): void
    {
        [, , , $baris] = $this->cfg('produksi');
        $this->kirim('produksi', [$baris]);
        TransaksiProduksi::first()->delete();

        $this->kirim('produksi', [$baris])->assertSessionHasNoErrors();
        $this->assertSame(1, TransaksiProduksi::count());
    }

    public function test_dandori_dari_centang_lhp_ikut_diperiksa_terhadap_form_dandori(): void
    {
        [, , , $dandori] = $this->cfg('dandori');
        $this->kirim('dandori', [$dandori]);

        [, , , $lhp] = $this->cfg('produksi');
        $this->kirim('produksi', [$lhp + [
            'ada_dandori' => 1, 'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->ref['lt'], 'actual_menit' => 25]],
        ]])->assertSessionHasErrors('rows.0.kembar');

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    /** Impor ulang file yang sama: baris yang sudah ada dilewati & dilaporkan. */
    public function test_impor_ulang_file_lhp_tidak_menggandakan(): void
    {
        $tpl = $this->get(route('input.produksi.import-template'))->baseResponse->getFile()->getPathname();
        $buku = IOFactory::load($tpl);
        $buku->setActiveSheetIndex(0)->fromArray([['2026-08-20', 'S1', 'Budi Santoso', 'A9', 'K-1', '1', '08:00', '16:00', 3000, '', '']], null, 'A3');
        $file = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        IOFactory::createWriter($buku, 'Xlsx')->save($file);
        $unggah = fn () => $this->post(route('input.produksi.import'), ['file' => UploadedFile::fake()->createWithContent('t.xlsx', file_get_contents($file))]);

        $unggah();
        $this->assertSame(1, TransaksiProduksi::count());

        $unggah();
        $this->assertSame(1, TransaksiProduksi::count(), 'impor ulang menggandakan data');
        $this->assertStringContainsString('dilewati — data identik sudah ada', implode("\n", session('import_errors')));
    }
}
