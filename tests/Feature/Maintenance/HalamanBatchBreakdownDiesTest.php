<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class HalamanBatchBreakdownDiesTest extends TestCase
{
    use RefreshDatabase;

    private int $partProsesId;

    protected function setUp(): void
    {
        parent::setUp();

        $part = MasterPart::create(['part_no' => 'BT-091A-3', 'customer' => 'Honda', 'part_name' => 'BASE JACK BRACKET']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 'OP10', 'nama_proses' => 'BLANKING']);
        $this->partProsesId = $proses->id;

        $this->actingAs(User::factory()->create(['role' => 'staff_maintenance']));
    }

    private function halaman(): string
    {
        return $this->get(route('maintenance.breakdown-dies.create'))->assertOk()->getContent();
    }

    public function test_form_batch_mengirim_ke_endpoint_batch(): void
    {
        $this->assertStringContainsString(route('maintenance.breakdown-dies.batch'), $this->halaman());
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
        $html = $this->halaman();

        $this->assertStringContainsString('x-data="batchDraft(', $html);
    }

    public function test_guest_tidak_bisa_membuka_halaman_batch(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->get(route('maintenance.breakdown-dies.create'))->assertForbidden();
    }

    public function test_form_submit_kirim_rows_json_bukan_input_per_baris(): void
    {
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

        $this->assertSame($close, $open, 'Div tidak seimbang di halaman batch Breakdown Dies.');
    }

    public function test_kunci_draft_dipisah_per_pengguna(): void
    {
        $this->assertStringContainsString('optima_draft_breakdown_dies_u'.auth()->id(), $this->halaman());
    }

    public function test_draft_dibersihkan_setelah_submit_bukan_dipulihkan_lagi(): void
    {
        $html = $this->halaman();

        $this->assertStringContainsString('optima_draft_breakdown_dies_terkirim_u'.auth()->id(), $html);
    }

    public function test_kunci_draft_cocok_dengan_yang_dihapus_index(): void
    {
        $create = $this->halaman();

        preg_match('/\bkunci:\s*\'([^\']+)\'/', $create, $mKunci);
        preg_match('/\bkunciBackup:\s*\'([^\']+)\'/', $create, $mBackup);

        $this->assertNotEmpty($mKunci, 'cfg.kunci tidak ditemukan di halaman create — regex perlu disesuaikan.');
        $this->assertNotEmpty($mBackup, 'cfg.kunciBackup tidak ditemukan di halaman create — regex perlu disesuaikan.');

        $kunci = $mKunci[1];
        $kunciBackup = $mBackup[1];

        $index = $this->withSession(['batch_tersimpan' => 3])
            ->get(route('maintenance.breakdown-dies.index'))->assertOk()->getContent();

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

    public function test_setiap_x_data_tafield_dan_partpicker_punya_deklarasi_const(): void
    {
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

    /**
     * Pemilih Proses (<select name="id_part_proses">) WAJIB berada di dalam
     * scope x-data="partPicker(...)" — prosesList milik komponen itu. Kalau
     * dipindah keluar (sibling, bukan descendant), Alpine tidak bisa
     * menjangkau prosesList dan halaman tidak bisa menyimpan satu baris pun
     * di peramban sungguhan, walau setiap test render server tetap lulus.
     * Diuji dengan DOMXPath, bukan substring, supaya posisi ancestor
     * benar-benar dibuktikan, bukan cuma keduanya sama-sama "ada di HTML".
     */
    public function test_pemilih_proses_ada_di_dalam_scope_partpicker(): void
    {
        $html = $this->halaman();

        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $nodes = $xpath->query("//select[@name='id_part_proses']/ancestor::*[contains(@x-data,'partPicker')]");

        $this->assertGreaterThan(
            0,
            $nodes->length,
            '<select name="id_part_proses"> tidak ditemukan di dalam ancestor x-data="partPicker(...)" — Alpine tidak bisa menjangkau prosesList.'
        );
    }

    public function test_nomor_urut_awal_diisi_dari_next_nomor_urut(): void
    {
        TransaksiBreakdownDies::create([
            'no_lkd' => '7/LKD/MTC/KMI/VIII/26',
            'id_part_proses' => $this->partProsesId,
            'tanggal_breakdown' => '2026-08-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-08-01', 'jam_mulai_perbaikan' => '08:10',
            'tanggal_selesai_perbaikan' => '2026-08-01', 'jam_selesai_perbaikan' => '09:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'pic_maintenance' => 'Budi',
            'repair_time' => 1, 'total_downtime' => 1, 'status_breakdown' => 'Sedang',
        ]);

        $html = $this->halaman();

        $this->assertMatchesRegularExpression(
            '/name="nomor_urut"[^>]*value="8"/',
            $html,
            'Input nomor_urut tidak dirender dengan value awal dari nextNomorUrut() (=8).'
        );
    }

    public function test_pic_maintenance_pakai_typeahead_bukan_input_bebas(): void
    {
        // pic_maintenance jadi dasar filter PIC di index (->where('pic_maintenance', ...))
        // dan sumber datanya sama-sama MasterKaryawan departemen Maintenance.
        // Input bebas (tanpa typeahead) membiarkan operator mengetik apa
        // saja — satu salah ketik dan baris itu jadi tidak kelihatan lagi
        // oleh filter PIC serta laporan per-PIC, tanpa error di mana pun.
        $html = $this->halaman();

        $this->assertStringContainsString(
            'x-data="taField(taPic)"',
            $html,
            'PIC Maintenance tidak memakai typeahead taField(taPic) — kembali jadi input bebas.'
        );

        $this->assertMatchesRegularExpression(
            '/name="pic_maintenance"[^>]*type="hidden"|type="hidden"[^>]*name="pic_maintenance"/',
            $html,
            'Field pic_maintenance yang benar-benar dikirim ke server bukan hidden input hasil pilihan typeahead.'
        );

        // Negatif: pastikan bentuk lama (input teks bebas beridentitas
        // f_pic yang LANGSUNG bernama pic_maintenance) sudah tidak ada.
        $this->assertDoesNotMatchRegularExpression(
            '/<input type="text" id="f_pic" name="pic_maintenance"/',
            $html,
            'PIC Maintenance masih berupa input teks bebas — typeahead belum benar-benar menggantikannya.'
        );
    }

    public function test_halaman_memasang_setelah_pulih(): void
    {
        $html = $this->halaman();

        $awal = strpos($html, 'function konfigBreakdownDies()');
        $this->assertNotFalse($awal, 'Deklarasi function konfigBreakdownDies() tidak ditemukan.');

        $blokKonfig = substr($html, $awal);

        $this->assertMatchesRegularExpression(
            '/\bsetelahPulih\s*\(\s*diri\s*\)\s*\{/',
            $blokKonfig,
            'konfigBreakdownDies() tidak memasang cfg.setelahPulih(diri) — nomor_urut tidak akan menaik setelah draft dipulihkan.'
        );

        // Regex di atas lolos untuk setelahPulih(diri) {} KOSONG — persis
        // kegagalan senyap yang seam ini dibuat untuk mencegah. Jangkarkan
        // KHUSUS ke isi blok setelahPulih (bukan seluruh blokKonfig, yang
        // juga memanggil majukanNomorUrut() dari resetLain() di dekatnya)
        // dan buktikan badannya benar-benar memanggil majukanNomorUrut(diri).
        $awalSetelahPulih = preg_match(
            '/\bsetelahPulih\s*\(\s*diri\s*\)\s*\{/',
            $blokKonfig,
            $mSetelahPulih,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $awalSetelahPulih, 'Blok setelahPulih(diri) { tidak ditemukan untuk diperiksa isinya.');

        $mulaiBadan = $mSetelahPulih[0][1] + strlen($mSetelahPulih[0][0]);
        $akhirBadan = strpos($blokKonfig, "\n        },", $mulaiBadan);
        $this->assertNotFalse($akhirBadan, 'Akhir blok setelahPulih(diri) tidak ditemukan.');

        $badanSetelahPulih = substr($blokKonfig, $mulaiBadan, $akhirBadan - $mulaiBadan);

        $this->assertStringContainsString(
            'majukanNomorUrut(diri)',
            $badanSetelahPulih,
            'setelahPulih(diri) { } kosong — tidak memanggil majukanNomorUrut(diri), jadi nomor_urut tidak akan menaik setelah draft dipulihkan walau seam-nya terpasang.'
        );
    }

    public function test_resetlain_memanggil_majukan_nomor_urut(): void
    {
        // resetLain(diri) dipanggil di UJUNG resetSebagian() — yaitu SETIAP
        // kali SAVE TO DRAFT sukses, jalur yang jauh lebih sering dipakai
        // daripada setelahPulih (yang hanya sekali per pemulihan draft dari
        // localStorage). Test di atas (test_halaman_memasang_setelah_pulih)
        // sudah membuktikan setelahPulih memanggil majukanNomorUrut(diri);
        // test ini membuktikan hal yang sama untuk resetLain — tanpanya,
        // menghapus majukanNomorUrut(diri) dari resetLain tetap membuat
        // suite hijau walau di praktiknya baris kedua dan seterusnya dalam
        // satu sesi akan mengusulkan nomor_urut yang sama dengan baris
        // pertama, dan seluruh batch ditolak server saat SUBMIT DRAFT.
        $html = $this->halaman();

        $awal = strpos($html, 'function konfigBreakdownDies()');
        $this->assertNotFalse($awal, 'Deklarasi function konfigBreakdownDies() tidak ditemukan.');

        $blokKonfig = substr($html, $awal);

        $awalResetLain = preg_match(
            '/\bresetLain\s*\(\s*diri\s*\)\s*\{/',
            $blokKonfig,
            $mResetLain,
            PREG_OFFSET_CAPTURE
        );
        $this->assertSame(1, $awalResetLain, 'Blok resetLain(diri) { tidak ditemukan untuk diperiksa isinya.');

        $mulaiBadan = $mResetLain[0][1] + strlen($mResetLain[0][0]);
        $akhirBadan = strpos($blokKonfig, "\n        },", $mulaiBadan);
        $this->assertNotFalse($akhirBadan, 'Akhir blok resetLain(diri) tidak ditemukan.');

        $badanResetLain = substr($blokKonfig, $mulaiBadan, $akhirBadan - $mulaiBadan);

        $this->assertStringContainsString(
            'majukanNomorUrut(diri)',
            $badanResetLain,
            'resetLain(diri) tidak memanggil majukanNomorUrut(diri) — nomor_urut tidak akan menaik setelah SAVE TO DRAFT, baris kedua dst dalam satu sesi akan bentrok saat SUBMIT DRAFT.'
        );
    }

    public function test_halaman_edit_tidak_500(): void
    {
        $breakdown = TransaksiBreakdownDies::create([
            'no_lkd' => '1/LKD/MTC/KMI/VIII/26',
            'id_part_proses' => $this->partProsesId,
            'tanggal_breakdown' => '2026-08-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-08-01', 'jam_mulai_perbaikan' => '08:10',
            'tanggal_selesai_perbaikan' => '2026-08-01', 'jam_selesai_perbaikan' => '09:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'pic_maintenance' => 'Budi',
            'repair_time' => 1, 'total_downtime' => 1, 'status_breakdown' => 'Sedang',
        ]);

        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->get(route('maintenance.breakdown-dies.edit', $breakdown))->assertOk();
    }

    public function test_halaman_tidak_mengirim_angka_turunan_dari_browser(): void
    {
        $html = $this->halaman();

        $awalKonfig = strpos($html, 'function konfigBreakdownDies()');
        $this->assertNotFalse($awalKonfig, 'Deklarasi function konfigBreakdownDies() tidak ditemukan.');

        $blokKonfig = substr($html, $awalKonfig);

        $awal = strpos($blokKonfig, 'payload(r) {');
        $this->assertNotFalse($awal, 'Deklarasi payload(r) { tidak ditemukan di konfigBreakdownDies().');
        $html = $blokKonfig;

        $akhir = strpos($html, "\n        },", $awal);
        $this->assertNotFalse($akhir, 'Akhir blok payload(r) tidak ditemukan.');

        $blokPayload = substr($html, $awal, $akhir - $awal);

        $this->assertStringNotContainsString('repair_time', $blokPayload,
            'payload() mengirim repair_time dari browser — nilai itu wajib dihitung server.');
        $this->assertStringNotContainsString('total_downtime', $blokPayload,
            'payload() mengirim total_downtime dari browser — nilai itu wajib dihitung server.');
        $this->assertStringNotContainsString('status_breakdown', $blokPayload,
            'payload() mengirim status_breakdown dari browser — nilai itu wajib dihitung server.');
    }

    public function test_import_gagal_menampilkan_pesan_error_di_halaman_daftar(): void
    {
        Storage::fake('local');

        $filePdf = UploadedFile::fake()->create('data.pdf', 10, 'application/pdf');

        $response = $this->from(route('maintenance.breakdown-dies.index'))
            ->post(route('maintenance.breakdown-dies.import'), ['file' => $filePdf]);

        $response->assertRedirect(route('maintenance.breakdown-dies.index'));

        $halaman = $this->get(route('maintenance.breakdown-dies.index'))->assertOk()->getContent();

        $this->assertStringContainsString(
            'Import gagal',
            $halaman,
            'Halaman maintenance.breakdown-dies.index tidak menampilkan pesan error validasi import — user melihat halaman seolah tidak terjadi apa-apa.'
        );
    }
}
