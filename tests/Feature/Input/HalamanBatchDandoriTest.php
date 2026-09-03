<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HalamanBatchDandoriTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        MasterMesin::create(['id_mesin' => 'M-01', 'nama_mesin' => 'MESIN SATU', 'line' => 'A', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500, 'sph_standar_qc' => 250]);
        MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 15]);

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function halaman(): string
    {
        return $this->get(route('input.dandori.create'))->assertOk()->getContent();
    }

    public function test_form_batch_mengirim_ke_endpoint_batch(): void
    {
        $this->assertStringContainsString(route('input.dandori.batch'), $this->halaman());
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

    public function test_halaman_daftar_tidak_lagi_punya_form_penulis(): void
    {
        // Laci penulis dicabut: form penulis turun dari tiga jadi dua (halaman
        // batch + form Edit). Selama sisa-sisanya masih terender di sini, field
        // baru bisa diam-diam terpasang di satu form saja lalu menyimpang.
        $html = $this->get(route('input.dandori.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('id="formDandori"', $html);
        $this->assertStringNotContainsString('name="id_part_proses"', $html);
        $this->assertStringNotContainsString('taField(taKaryawan', $html);
    }

    public function test_tombol_input_baru_menautkan_ke_halaman_batch(): void
    {
        // Kalau tautannya hilang, halaman batch masih hidup tapi tidak punya
        // pintu masuk — operator kembali menganggap modul ini tidak bisa diisi.
        $html = $this->get(route('input.dandori.index'))->assertOk()->getContent();

        $this->assertStringContainsString(route('input.dandori.create'), $html);
    }

    public function test_guest_tidak_bisa_membuka_halaman_batch(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->get(route('input.dandori.create'))->assertForbidden();
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

        $this->assertSame($close, $open, 'Div tidak seimbang di halaman batch Dandori.');
    }

    public function test_kunci_draft_dipisah_per_pengguna(): void
    {
        // Komputer di lantai pabrik dipakai bergantian; kunci bersama membuat
        // draft operator A muncul di layar operator B.
        $this->assertStringContainsString('optima_draft_dandori_u'.auth()->id(), $this->halaman());
    }

    public function test_draft_dibersihkan_setelah_submit_bukan_dipulihkan_lagi(): void
    {
        // optima_draft_dandori_u{id} BUKAN substring dari
        // optima_draft_dandori_terkirim_u{id} (kata "_terkirim" ada di TENGAH,
        // sebelum _u{id}) — jadi test_kunci_draft_dipisah_per_pengguna di atas
        // tidak membuktikan apa pun soal ejaan kunci backup ini. Tanpa asersi
        // ini, typo seperti "optima_draft_dandori_u{id}_terkirim" akan tetap
        // lolos hijau.
        $html = $this->halaman();

        $this->assertStringContainsString('optima_draft_dandori_terkirim_u'.auth()->id(), $html);
        $this->assertStringContainsString('formBatch', $html);
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
        // mulai() memulihkannya lagi di kunjungan berikutnya, dan operator
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
        // DandoriController::batch()) — di luar itu index tidak menyentuh
        // localStorage sama sekali, dan itu benar (lihat komentar di
        // batal()/pindahkanKeBackup() di batchDraft() soal kenapa penghapusan
        // bersyarat).
        $index = $this->withSession(['batch_tersimpan' => 3])
            ->get(route('input.dandori.index'))->assertOk()->getContent();

        // Mencocokkan literal di dalam localStorage.removeItem('...') itu
        // sendiri, bukan di mana pun di halaman — kunci yang cuma disebut di
        // komentar akan tetap lolos dengan assertStringContainsString biasa
        // tanpa membuktikan apa pun soal penghapusannya.
        $this->assertMatchesRegularExpression(
            '/localStorage\.removeItem\(\''.preg_quote($kunci, '/').'\'\)/',
            $index,
            "localStorage.removeItem('{$kunci}') tidak ditemukan di halaman daftar."
        );
        $this->assertMatchesRegularExpression(
            '/localStorage\.removeItem\(\''.preg_quote($kunciBackup, '/').'\'\)/',
            $index,
            "localStorage.removeItem('{$kunciBackup}') tidak ditemukan di halaman daftar."
        );
    }

    public function test_pemilih_proses_berada_dalam_scope_part_picker(): void
    {
        // prosesList adalah state partPicker(). Kalau <select name="id_part_proses">
        // berada di LUAR elemen itu, Alpine tidak pernah bisa menjangkau
        // prosesList — dropdown Proses selalu kosong dan tidak satu pun baris
        // bisa disimpan. Test render server biasa tidak melihat ini — makanya
        // diperiksa strukturnya, bukan substring.
        $html = $this->halaman();

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
            '<select name="id_part_proses"> berada di luar scope partPicker() — prosesList tidak akan pernah terjangkau Alpine.');
    }

    public function test_pilihan_proses_membawa_penanda_progressive(): void
    {
        // data-progressive adalah jembatan yang membuat blok rincian dandori
        // memakai std_menit_progressive, bukan std_menit_default. Jangkarkan
        // ke blok <select name="id_part_proses"> di HTML — string
        // 'data-progressive' JUGA muncul di komentar JS partial
        // dandori-rows.blade.php, jadi pencarian seluruh halaman lolos tanpa
        // membuktikan apa pun.
        $part = MasterPart::create(['part_no' => 'P-2', 'part_name' => 'PART DUA']);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'FORMING',
            'sph_standar_produksi' => 400, 'sph_standar_qc' => 200,
        ]);

        $html = $this->halaman();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $select = $xpath->query("//select[@name='id_part_proses']");
        $this->assertGreaterThan(0, $select->length, '<select name="id_part_proses"> tidak ditemukan.');

        // Isi <select> dirakit oleh Alpine (x-for) dari partsData, bukan
        // server-rendered <option>, jadi bukti kongkretnya ada di blok
        // partsData yang dirender ke <script> — bukan di dalam <select> itu
        // sendiri di HTML mentah.
        $this->assertMatchesRegularExpression(
            '/const partsData\s*=\s*(\[.*?\]);/s', $html, 'blok partsData tidak ditemukan'
        );
        preg_match('/const partsData\s*=\s*(\[.*?\]);/s', $html, $m);
        $partsData = json_decode($m[1], true);

        $proses = collect($partsData)->firstWhere('id', $part->id)['proses'] ?? [];
        $progressive = collect($proses)->firstWhere('nama_proses', 'PROGRESSIVE');
        $forming = collect($proses)->firstWhere('nama_proses', 'FORMING');

        $this->assertNotNull($progressive, 'Proses PROGRESSIVE tidak ada di partsData.');
        $this->assertNotNull($forming, 'Proses FORMING tidak ada di partsData.');

        // partsData sendiri hanya perlu id/id_proses/nama_proses — penanda
        // data-progressive dihitung dari nama_proses di template Alpine
        // (:data-progressive="p.nama_proses...==='PROGRESSIVE' ? '1' : '0'"),
        // sama seperti input/produksi/create.blade.php. Buktikan template
        // Alpine untuk <option> membawa binding itu, dijangkarkan ke blok
        // <select name="id_part_proses"> di markup Blade mentah (sebelum
        // Alpine merender), bukan seluruh halaman.
        $selectPos = strpos($html, 'name="id_part_proses"');
        $this->assertNotFalse($selectPos);
        $selectEnd = strpos($html, '</select>', $selectPos);
        $this->assertNotFalse($selectEnd);
        $blokSelect = substr($html, $selectPos, $selectEnd - $selectPos);

        $this->assertStringContainsString('data-progressive', $blokSelect,
            'Binding data-progressive tidak ditemukan di dalam blok <select name="id_part_proses">.');
        $this->assertStringContainsString("=== 'PROGRESSIVE'", $blokSelect,
            'Binding data-progressive tidak memeriksa nama_proses === PROGRESSIVE.');
    }

    public function test_halaman_memuat_blok_rincian_dandori(): void
    {
        // Dandori WAJIB minimal 1 rincian — bukan checkbox pembungkus seperti
        // blok NG di QC/Produksi. Partial dandori-rows harus selalu dirender,
        // bukan disembunyikan di balik x-show sebuah checkbox.
        $html = $this->halaman();

        $this->assertStringContainsString('class="dandori-rows"', $html);
        $this->assertStringContainsString('dandoriRows(', $html);
    }

    public function test_halaman_tidak_mengirim_std_menit_dari_browser(): void
    {
        // Server (DandoriRincianService) yang menghitung std_menit/over_menit
        // dari MasterLosstime. Kalau browser ikut mengirim salah satu kunci
        // itu di payload(), nilainya bisa berbeda dari perhitungan server
        // (mis. karena data-progressive basi) tanpa error apa pun.
        // Jangkarkan ke blok payload( itu sendiri, bukan seluruh halaman —
        // string "std_menit"/"over_menit" muncul wajar di tempat lain
        // (pratinjau, label kolom, dandori-rows.blade.php).
        $html = $this->halaman();

        // Anchor mengharuskan " {" langsung sesudah "payload(r)" — bentuk
        // DEKLARASI fungsi. batchDraft() di layout memanggil cfg.payload(r)
        // (tanpa "{" sesudahnya) di SETIAP halaman yang memakainya, jadi
        // strpos tanpa " {" akan lolos di halaman manapun tanpa membuktikan
        // apa pun soal konfigDandori() — jebakan ini sempat lolos di draf
        // pertama test ini.
        $awal = strpos($html, 'payload(r) {');
        $this->assertNotFalse($awal, 'Deklarasi payload(r) { tidak ditemukan di konfigDandori().');

        $akhir = strpos($html, "\n        },", $awal);
        $this->assertNotFalse($akhir, 'Akhir blok payload(r) tidak ditemukan.');

        $blokPayload = substr($html, $awal, $akhir - $awal);

        $this->assertStringNotContainsString('std_menit', $blokPayload,
            'payload() mengirim std_menit dari browser — nilai itu wajib dihitung server.');
        $this->assertStringNotContainsString('over_menit', $blokPayload,
            'payload() mengirim over_menit dari browser — nilai itu wajib dihitung server.');
    }

    public function test_setiap_x_data_tafield_dan_partpicker_punya_deklarasi_const(): void
    {
        // Server-rendered HTML bisa 200 OK sempurna sementara browser
        // ReferenceError di JS — kelas kegagalan yang sudah berkali-kali
        // lolos dari suite ini (lihat CLAUDE.md). taField(taKaryawan, ...)
        // di dandori-rows.blade.php (partial bersama, blok "Operator
        // Dandori" opsional) butuh global taKaryawan didefinisikan di
        // halaman yang MENYERTAKAN partial itu — kalau lupa, Alpine tidak
        // menemukan taKaryawan di scope manapun, jatuh ke window.taKaryawan
        // (undefined), lempar ReferenceError, dan x-data itu runtuh jadi {}
        // ({query}/{val} tidak pernah ada). Kotaknya tetap tampil di
        // browser — cuma dropdownnya mati dan apa pun yang diketik dibuang
        // diam-diam. Tidak satu pun dari test render-server lain di berkas
        // ini menyentuh itu.
        //
        // Test ini menjangkar ke SETIAP pemanggilan x-data="taField(IDENT"
        // atau x-data="partPicker(IDENT" di HTML yang benar-benar dirender,
        // lalu membuktikan "const IDENT =" ada di halaman yang sama —
        // bukan cuma taKaryawan yang di-hardcode, supaya kalau nama global
        // lain (taOperator, taMesin, taParts) suatu saat lupa dideklarasikan
        // juga tertangkap tanpa mengubah test ini.
        $html = $this->halaman();

        preg_match_all('/x-data="(?:taField|partPicker)\((\w+)/', $html, $m);
        $ident = array_unique($m[1]);

        $this->assertNotEmpty(
            $ident,
            'Tidak ada x-data="taField(...)" atau x-data="partPicker(...)" ditemukan — regex perlu disesuaikan.'
        );

        foreach ($ident as $nama) {
            $this->assertMatchesRegularExpression(
                '/\bconst\s+'.preg_quote($nama, '/').'\s*=/',
                $html,
                "x-data memakai global '{$nama}' tapi tidak ada 'const {$nama} =' di halaman — ".
                'ReferenceError di browser, x-data runtuh jadi {} secara senyap.'
            );
        }
    }

    public function test_import_gagal_menampilkan_pesan_error_di_halaman_daftar(): void
    {
        // Laci penulis lama satu-satunya yang merender bag $errors bawaan
        // Laravel; setelah dicabut, kegagalan validasi upload (mis. file
        // bukan xlsx/xls/csv) redirect balik ke input.dandori.index TANPA
        // pesan apa pun kalau blok penggantinya hilang. assertSessionHasErrors
        // saja tidak cukup — itu membuktikan session-nya benar, bukan bahwa
        // halamannya menampilkan sesuatu ke user. Maka di sini dijangkarkan
        // ke TEKS pesan yang benar-benar DIRENDER pada request berikutnya
        // (sengaja TANPA assertSessionHasErrors di antaranya — memanggil
        // session()->start() manual di antara dua request nyata mengganggu
        // marshalling ulang $errors dari JSON gara-gara 'serialization' =>
        // 'json' di config/session.php, membuat pengujian ini gagal dengan
        // alasan yang salah walau perbaikannya sendiri benar).
        // dandori.import admin-only sejak audit keamanan 2026-09-03 (upsert lewat
        // import bisa nembus batas dandori.update yang admin-only) — panel importnya
        // sendiri di view juga sudah @if(hasRole('admin')), jadi ganti aktor di sini.
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        Storage::fake('local');

        $filePdf = UploadedFile::fake()->create('data.pdf', 10, 'application/pdf');

        $response = $this->from(route('input.dandori.index'))
            ->post(route('input.dandori.import'), ['file' => $filePdf]);

        $response->assertRedirect(route('input.dandori.index'));

        $halaman = $this->get(route('input.dandori.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'Import gagal',
            $halaman,
            'Halaman input.dandori.index tidak menampilkan pesan error validasi import — user melihat halaman seolah tidak terjadi apa-apa.'
        );
        $this->assertStringContainsString(
            'harus berformat',
            $halaman,
            'Pesan validasi file spesifik (file.mimes) tidak sampai ke halaman.'
        );
    }

    public function test_staff_produksi_tidak_boleh_import_dandori_403(): void
    {
        // Route ini upsert-by-natural-key (App\Imports\DandoriImport) — beda dari
        // batch() yang insert-only — jadi bisa dipakai nembus batas dandori.update
        // yang sengaja admin-only. Audit keamanan 2026-09-03.
        Storage::fake('local');
        $filePdf = UploadedFile::fake()->create('data.pdf', 10, 'application/pdf');

        // setUp() sudah actingAs staff_produksi.
        $this->post(route('input.dandori.import'), ['file' => $filePdf])
            ->assertForbidden();
    }
}
