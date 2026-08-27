<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchQcTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'INSPECTOR SATU', 'departemen' => 'QC', 'is_aktif' => true]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        MasterJenisNg::create(['kode' => 'NG1', 'nama_ng' => 'GORES']);

        $this->actingAs(User::factory()->create(['role' => 'staff_qc']));
    }

    private function halaman(): string
    {
        return $this->get(route('input.qc.create'))->assertOk()->getContent();
    }

    public function test_form_batch_mengirim_ke_endpoint_batch(): void
    {
        $this->assertStringContainsString(route('input.qc.batch'), $this->halaman());
    }

    public function test_halaman_punya_tabel_draft_dan_tombolnya(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('DRAFT TERSIMPAN', $html);
        $this->assertStringContainsString('SAVE TO DRAFT', $html);
        $this->assertStringContainsString('SUBMIT DRAFT', $html);
    }

    public function test_halaman_memakai_kerangka_bersama(): void
    {
        // Membuktikan halaman ini memanggil kerangka bersama lewat x-data
        // (batchDraft), bukan kerangka lokal salinan sendiri.
        $html = $this->halaman();

        $this->assertStringContainsString('x-data="batchDraft(', $html);
    }

    public function test_guest_tidak_bisa_membuka_halaman_batch(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->get(route('input.qc.create'))->assertForbidden();
    }

    public function test_form_submit_kirim_rows_json_bukan_input_per_baris(): void
    {
        // Jangkarkan ke markup <form id="formBatch"> itu sendiri, bukan
        // seluruh halaman — formBaris (form SAVE TO DRAFT) juga punya banyak
        // <input> yang tidak relevan.
        $html = $this->halaman();

        $idPos = strpos($html, 'id="formBatch"');
        $this->assertNotFalse($idPos, '<form id="formBatch"> tidak ditemukan.');

        $awal = strrpos(substr($html, 0, $idPos), '<form');
        $this->assertNotFalse($awal, 'Tag pembuka <form id="formBatch"> tidak ditemukan.');

        $akhir = strpos($html, '</form>', $idPos);
        $this->assertNotFalse($akhir, 'Penutup <form id="formBatch"> tidak ditemukan.');

        $blokForm = substr($html, $awal, $akhir - $awal);

        $this->assertStringContainsString('name="rows_json"', $blokForm);
        $this->assertStringNotContainsString('rows[${i}]', $blokForm);
    }

    public function test_div_tags_seimbang_di_halaman_batch(): void
    {
        $html = $this->halaman();

        $open = preg_match_all('/<div\b/i', $html);
        $close = preg_match_all('/<\/div>/i', $html);

        $this->assertSame($close, $open, 'Div tidak seimbang di halaman batch QC.');
    }

    public function test_kunci_draft_dipisah_per_pengguna(): void
    {
        // Komputer di lantai pabrik dipakai bergantian; kunci bersama membuat
        // draft inspector A muncul di layar inspector B.
        $this->assertStringContainsString('optima_draft_lhp_qc_u'.auth()->id(), $this->halaman());
    }

    public function test_kunci_draft_cocok_dengan_yang_dihapus_index(): void
    {
        // create.blade.php merender literal cfg.kunci/cfg.kunciBackup ke HTML
        // (lihat test_kunci_draft_dipisah_per_pengguna dan
        // test_draft_dibersihkan_setelah_submit_bukan_dipulihkan_lagi di atas).
        // index.blade.php WAJIB menghapus persis kunci yang sama lewat
        // localStorage.removeItem(...) di halaman daftar setelah flash
        // batch_tersimpan — kalau kuncinya tidak cocok (typo salin-tempel),
        // backup draft yang sudah tersimpan di server tidak pernah dibersihkan,
        // mulai() memulihkannya lagi di kunjungan berikutnya, dan inspector
        // mengirim ulang batch yang sudah tersimpan. Nol test yang sudah ada
        // menjangkar dua sisi ini SEKALIGUS satu sama lain.
        //
        // Kuncinya diekstrak dari HTML create yang BENAR-BENAR dirender, bukan
        // dihardcode dua kali di sini — kalau begitu, test ini akan tetap hijau
        // walau salah satu sisi (create ATAU index) berubah sendirian, sama
        // seperti asersi yang tak bisa gagal.
        $create = $this->halaman();

        preg_match('/\bkunci:\s*\'([^\']+)\'/', $create, $mKunci);
        preg_match('/\bkunciBackup:\s*\'([^\']+)\'/', $create, $mBackup);

        $this->assertNotEmpty($mKunci, 'cfg.kunci tidak ditemukan di halaman create — regex perlu disesuaikan.');
        $this->assertNotEmpty($mBackup, 'cfg.kunciBackup tidak ditemukan di halaman create — regex perlu disesuaikan.');

        $kunci = $mKunci[1];
        $kunciBackup = $mBackup[1];

        // Blok yang menghapus kunci di index.blade.php hanya dirender kalau
        // sesi punya flash batch_tersimpan (bukti sukses dari
        // QcController::batch()) — di luar itu index tidak menyentuh
        // localStorage sama sekali, dan itu benar (lihat komentar di
        // batchDraft()/pindahkanKeBackup() soal kenapa penghapusan bersyarat).
        $index = $this->withSession(['batch_tersimpan' => 3])
            ->get(route('input.qc.index'))->assertOk()->getContent();

        $this->assertStringContainsString($kunci, $index,
            "Kunci draft '{$kunci}' dari halaman create tidak dihapus di halaman daftar.");
        $this->assertStringContainsString($kunciBackup, $index,
            "Kunci backup '{$kunciBackup}' dari halaman create tidak dihapus di halaman daftar.");
    }

    public function test_halaman_memuat_blok_ng(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('name="ada_ng"', $html);
        $this->assertStringContainsString('class="ng-rows"', $html);
    }

    public function test_halaman_memakai_part_picker_bersama(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('x-data="partPicker(', $html);
    }

    public function test_hanya_proses_finish_good_yang_dikirim(): void
    {
        // QC hanya memeriksa produk jadi: proses dengan id_proses TERTINGGI.
        // Aturan itu hidup sebagai take(1) setelah orderByDesc('id_proses') di
        // QcController::create(). Kalau hilang, proses antara ikut muncul di
        // halaman dan inspector bisa mencatat pemeriksaan ke proses yang salah
        // — data keliru tanpa satu pun error. Karena itu dijaga di sini.
        $part = MasterPart::create([
            'part_no' => 'FG-1', 'part_name' => 'Finish Good Part', 'customer' => 'Toyota',
        ]);
        $blanking = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANKING',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $forming = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'FORMING',
            'sph_standar_produksi' => 400, 'sph_standar_qc' => 200,
        ]);
        $finish = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 3, 'nama_proses' => 'PIERCING',
            'sph_standar_produksi' => 300, 'sph_standar_qc' => 150,
        ]);

        $html = $this->halaman();

        // Dijangkarkan ke blok partsData, bukan ke seluruh HTML: id proses
        // yang sama bisa muncul di tempat lain (mis. daftar filter) dan
        // membuat asersi ini lolos tanpa membuktikan apa pun.
        $this->assertMatchesRegularExpression(
            '/const partsData\s*=\s*(\[.*?\]);/s', $html, 'blok partsData tidak ditemukan'
        );
        preg_match('/const partsData\s*=\s*(\[.*?\]);/s', $html, $m);
        $partsData = json_decode($m[1], true);

        $idProses = collect($partsData)
            ->firstWhere('id', $part->id)['proses'] ?? [];
        $idProses = array_column($idProses, 'id');

        $this->assertSame([$finish->id], $idProses);
        $this->assertNotContains($blanking->id, $idProses);
        $this->assertNotContains($forming->id, $idProses);
    }

    public function test_proses_finish_good_membawa_sph_qc_bukan_sph_produksi(): void
    {
        // Kolom "%" di tabel draft membandingkan SPH aktual dengan SPH STANDAR
        // QC. Memakai sph_standar_produksi di sana menghasilkan persentase yang
        // masuk akal tapi salah — angka yang tidak akan dikoreksi siapa pun.
        $part = MasterPart::create([
            'part_no' => 'SPH-1', 'part_name' => 'Sph Part', 'customer' => 'Toyota',
        ]);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 999, 'sph_standar_qc' => 111,
        ]);

        preg_match('/const partsData\s*=\s*(\[.*?\]);/s', $this->halaman(), $m);
        $proses = collect(json_decode($m[1], true))->firstWhere('id', $part->id)['proses'][0];

        $this->assertSame(111.0, (float) $proses['sph_standar_qc']);
    }

    public function test_pemilih_proses_tidak_ada_di_halaman(): void
    {
        // QC tidak memilih Proses secara manual — halaman hanya menampilkan
        // kotak info Proses Finish Good dari prosesList[0]. Kalau <select>
        // name="id_part_proses" kembali muncul, itu tanda halaman diam-diam
        // kembali ke pola produksi (yang membiarkan proses dipilih manual).
        $html = $this->halaman();

        $this->assertStringNotContainsString('<select id="f_proses" name="id_part_proses"', $html);
        $this->assertStringContainsString('type="hidden" name="id_part_proses"', $html);
    }

    public function test_pemilih_part_berada_dalam_scope_part_picker(): void
    {
        // prosesList adalah state partPicker(). Kalau hidden input
        // id_part_proses berada di LUAR elemen itu, Alpine tidak pernah bisa
        // mengevaluasi :value="prosesList[0] ? ... : ''" — id_part_proses
        // selalu kosong dan tidak satu pun baris bisa disimpan. Test render
        // server tidak melihat ini — makanya diperiksa strukturnya.
        $html = $this->halaman();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cocok = $xpath->query(
            "//input[@name='id_part_proses']/ancestor::*[contains(@x-data, 'partPicker')]"
        );

        $this->assertNotFalse($cocok);
        $this->assertGreaterThan(0, $cocok->length,
            'Hidden input id_part_proses berada di luar scope partPicker() — prosesList tidak akan pernah terjangkau Alpine.');
    }
}
