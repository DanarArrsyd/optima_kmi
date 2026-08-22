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
        // memakai std_menit_default. `data-progressive` juga muncul sebagai teks
        // di komentar JS pada dandori-rows.blade.php — jangkarkan ke dalam
        // <select> Proses itu sendiri, dan ke bentuk bindingnya (:data-progressive),
        // supaya literal di tempat lain tidak bisa meloloskan asersi ini.
        $html = $this->halaman();

        $awal = strpos($html, 'name="id_part_proses"');
        $this->assertNotFalse($awal, '<select> Proses tidak ditemukan.');

        $akhir = strpos($html, '</select>', $awal);
        $this->assertNotFalse($akhir, 'Penutup <select> Proses tidak ditemukan.');

        $blokSelect = substr($html, $awal, $akhir - $awal);

        $this->assertStringContainsString(':data-progressive="', $blokSelect,
            'Binding :data-progressive tidak ditemukan di dalam <select> Proses.');
    }

    public function test_kolom_dandori_ada_di_tabel_draft(): void
    {
        // Satu baris bisa punya NG dan dandori sekaligus; rancangan awal cuma
        // punya kolom NG.
        $html = $this->halaman();

        $this->assertStringContainsString('>Dandori<', $html);
        $this->assertStringContainsString('>Jenis NG<', $html);
    }

    public function test_tabel_draft_punya_label_ng_dan_losstime(): void
    {
        // Ringkasan yang menampilkan id mentah (#1 ×5) membuat tabel draft tidak
        // bisa dipakai memeriksa apa yang tadi diketik — padahal itu satu-satunya
        // gunanya sebelum SUBMIT. 'GORES' juga muncul di <option> pilihan NG dan
        // 'GANTI DIES' di data typeahead losstime dandori, jadi jangkarkan ke
        // deklarasi konstanta JSON ngLabel/ltLabel yang dipakai tabel draft itu
        // sendiri, bukan ke kemunculan nama di mana pun pada halaman.
        $html = $this->get(route('input.produksi.create'))->assertOk()->getContent();

        $this->assertStringContainsString('GORES', $this->konstanta($html, 'ngLabel'));
        $this->assertStringContainsString('GANTI DIES', $this->konstanta($html, 'ltLabel'));
    }

    /** Ambil isi pernyataan `const $nama = ...;` di @push('scripts') — dijangkarkan
     *  ke deklarasinya sendiri karena nilainya (mis. "GORES") bisa muncul di
     *  tempat lain pada halaman yang sama tanpa hubungan dengan konstanta ini. */
    private function konstanta(string $html, string $nama): string
    {
        $awal = strpos($html, "const {$nama}");
        $this->assertNotFalse($awal, "Konstanta {$nama} tidak ditemukan.");

        $akhir = strpos($html, ';', $awal);
        $this->assertNotFalse($akhir, "Akhir pernyataan konstanta {$nama} tidak ditemukan.");

        return substr($html, $awal, $akhir - $awal);
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

    public function test_halaman_daftar_tidak_lagi_punya_form_penulis(): void
    {
        // Laci input dicabut: form penulis turun dari tiga jadi dua (halaman
        // batch + form Edit). Selama masih ada di sini, field baru bisa
        // diam-diam terpasang di satu form saja.
        $html = $this->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="qty_aktual"', $html);
        $this->assertStringNotContainsString('name="ada_dandori"', $html);
        $this->assertStringNotContainsString('id="formProduksi"', $html);
    }

    public function test_tombol_input_baru_menautkan_ke_halaman_batch(): void
    {
        $html = $this->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('input.produksi.create'), $html);
    }
}
