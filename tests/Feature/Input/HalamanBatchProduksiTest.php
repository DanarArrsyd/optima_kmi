<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchProduksiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);
        MasterJenisNg::create(['kode' => 'NG1', 'nama_ng' => 'GORES']);
        MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 45]);

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function halaman(): string
    {
        return $this->get(route('input.produksi.create'))->assertOk()->getContent();
    }

    public function test_form_batch_mengirim_ke_endpoint_batch(): void
    {
        $this->assertStringContainsString(route('input.produksi.batch'), $this->halaman());
    }

    public function test_halaman_punya_tabel_draft_dan_tombolnya(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('DRAFT TERSIMPAN', $html);
        $this->assertStringContainsString('SAVE TO DRAFT', $html);
        $this->assertStringContainsString('SUBMIT DRAFT', $html);
    }

    public function test_halaman_memuat_blok_ng_dan_dandori(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('name="ada_ng"', $html);
        $this->assertStringContainsString('name="ada_dandori"', $html);
        $this->assertStringContainsString('class="ng-rows"', $html);
        $this->assertStringContainsString('class="dandori-rows"', $html);
    }

    public function test_pilihan_proses_membawa_penanda_progressive(): void
    {
        // Tanpa penanda ini pratinjau Std/Over di blok dandori diam-diam selalu
        // memakai std_menit_default.
        $this->assertStringContainsString('data-progressive', $this->halaman());
    }

    public function test_kolom_dandori_ada_di_tabel_draft(): void
    {
        // Satu baris bisa punya NG dan dandori sekaligus; rancangan awal cuma
        // punya kolom NG.
        $html = $this->halaman();

        $this->assertStringContainsString('>Dandori<', $html);
        $this->assertStringContainsString('>Jenis NG<', $html);
    }

    public function test_kunci_draft_dipisah_per_pengguna(): void
    {
        // Komputer di lantai produksi dipakai bergantian; kunci bersama membuat
        // draft operator A muncul di layar operator B.
        $this->assertStringContainsString('optima_draft_lhp_produksi_u'.auth()->id(), $this->halaman());
    }

    public function test_guest_tidak_bisa_membuka_halaman_batch(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->get(route('input.produksi.create'))->assertForbidden();
    }

    public function test_draft_dibersihkan_setelah_submit_bukan_dipulihkan_lagi(): void
    {
        // Draft yang tidak dibersihkan setelah submit akan "dipulihkan" saat
        // operator membuka halaman ini lagi, lalu terkirim untuk kedua kalinya —
        // baris LHP dobel yang menggelembungkan angka produksi tanpa ada yang lapor.
        $html = $this->get(route('input.produksi.create'))->assertOk()->getContent();

        $this->assertStringContainsString('optima_draft_lhp_produksi_terkirim_u'.auth()->id(), $html);
        $this->assertStringContainsString('formBatch', $html);
    }

    public function test_pemilih_proses_berada_dalam_scope_part_picker(): void
    {
        // prosesList adalah state partPicker(). Kalau <select> Proses berada di
        // LUAR elemen itu, Alpine tidak pernah bisa mengevaluasinya: daftar
        // proses tak pernah terisi dan tidak satu pun baris bisa disimpan.
        // Test render server tidak melihat ini — makanya diperiksa strukturnya.
        $html = $this->get(route('input.produksi.create'))->assertOk()->getContent();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cocok = $xpath->query(
            "//select[@name='id_part_proses']/ancestor::*[contains(@x-data, 'partPicker')]"
        );

        $this->assertNotFalse($cocok);
        $this->assertGreaterThan(0, $cocok->length,
            'Pemilih Proses berada di luar scope partPicker() — prosesList tidak akan pernah terjangkau Alpine.');
    }
}
