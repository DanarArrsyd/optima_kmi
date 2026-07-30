<?php

namespace Tests\Unit;

use App\Exports\NgListExport;
use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\TransaksiProduksiNg;
use App\Models\TransaksiQc;
use App\Models\TransaksiQcNg;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Isi export diuji langsung lewat collection()/headings(), TANPA menjalankan
 * writer Excel: writer sungguhan membunuh proses PHPUnit saat suite penuh jalan.
 */
class NgListExportTest extends TestCase
{
    use RefreshDatabase;

    private function seedNg(): array
    {
        $user = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $operator = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Operator A', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $inspector = MasterKaryawan::create(['nik' => uniqid(), 'nama_karyawan' => 'Inspector B', 'departemen' => 'QC', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A4', 'nama_mesin' => 'Mesin A4', 'line' => '1', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'PN-001', 'part_name' => 'Bracket', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $dent = MasterJenisNg::create(['kode' => 'NG-01', 'nama_ng' => 'Dent']);
        $burr = MasterJenisNg::create(['kode' => 'NG-02', 'nama_ng' => 'Burr']);

        $prod = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-03-01', 'id_shift' => $shift->id,
            'id_karyawan' => $operator->id, 'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_aktual' => 100, 'qty_ng' => 8,
            'created_by' => $user->id,
        ]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $prod->id, 'id_jenis_ng' => $dent->id, 'qty' => 5]);
        TransaksiProduksiNg::create(['id_transaksi_produksi' => $prod->id, 'id_jenis_ng' => $burr->id, 'qty' => 3]);

        $qc = TransaksiQc::create([
            'tgl_pengecekan' => '2026-03-01', 'tgl_produksi_part' => '2026-03-01', 'id_shift' => $shift->id,
            'id_karyawan_qc' => $inspector->id, 'id_part_proses' => $proses->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00', 'qty_ok' => 100, 'qty_ng' => 4,
            'created_by' => $user->id,
        ]);
        TransaksiQcNg::create(['id_transaksi_qc' => $qc->id, 'id_jenis_ng' => $dent->id, 'qty' => 4]);

        return compact('dent', 'burr');
    }

    private function semua(array $extra = []): array
    {
        return array_merge(['date_from' => '2020-01-01', 'date_to' => '2030-12-31'], $extra);
    }

    public function test_produksi_headings_punya_kolom_mesin_dan_label_operator(): void
    {
        $head = (new NgListExport('produksi', $this->semua()))->headings();

        $this->assertContains('Mesin', $head);
        $this->assertContains('Operator', $head);
        $this->assertNotContains('Inspector', $head);
    }

    /** transaksi_qc tidak punya id_mesin — kolomnya harus absen, bukan kosong. */
    public function test_qc_headings_tanpa_kolom_mesin_dan_berlabel_inspector(): void
    {
        $head = (new NgListExport('qc', $this->semua()))->headings();

        $this->assertNotContains('Mesin', $head);
        $this->assertContains('Inspector', $head);
    }

    public function test_jumlah_kolom_baris_sama_dengan_jumlah_heading(): void
    {
        $this->seedNg();

        foreach (['produksi', 'qc'] as $sumber) {
            $export = new NgListExport($sumber, $this->semua());
            $baris = $export->collection()->first();

            $this->assertCount(count($export->headings()), $baris, "Sumber {$sumber}: kolom baris dan heading tidak sepadan.");
        }
    }

    public function test_satu_sesi_dua_jenis_ng_jadi_dua_baris_excel(): void
    {
        $this->seedNg();

        $this->assertCount(2, (new NgListExport('produksi', $this->semua()))->collection());
    }

    /** Export mengikuti filter yang aktif, bukan seluruh tabel. */
    public function test_export_memakai_filter_yang_aktif(): void
    {
        $f = $this->seedNg();

        $rows = (new NgListExport('produksi', $this->semua(['jenis_ng_id' => $f['burr']->id])))->collection();

        $this->assertCount(1, $rows);
        $this->assertContains('NG-02', $rows->first());
        $this->assertContains(3, $rows->first());
    }

    public function test_export_produksi_tidak_memuat_baris_qc(): void
    {
        $this->seedNg();

        $qtyProduksi = (new NgListExport('produksi', $this->semua()))->collection()->map(fn ($r) => end($r));
        $qtyQc = (new NgListExport('qc', $this->semua()))->collection()->map(fn ($r) => end($r));

        $this->assertEqualsCanonicalizing([5, 3], $qtyProduksi->all());
        $this->assertEqualsCanonicalizing([4], $qtyQc->all());
    }
}
