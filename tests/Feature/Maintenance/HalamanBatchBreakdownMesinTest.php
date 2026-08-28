<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HalamanBatchBreakdownMesinTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        MasterMesin::create(['id_mesin' => 'M-01', 'nama_mesin' => 'MESIN SATU', 'line' => 'A', 'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100]);

        $this->actingAs(User::factory()->create(['role' => 'staff_maintenance']));
    }

    private function halaman(): string
    {
        return $this->get(route('maintenance.breakdown.create'))->assertOk()->getContent();
    }

    public function test_form_batch_mengirim_ke_endpoint_batch(): void
    {
        $this->assertStringContainsString(route('maintenance.breakdown.batch'), $this->halaman());
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

        $this->get(route('maintenance.breakdown.create'))->assertForbidden();
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

        $this->assertSame($close, $open, 'Div tidak seimbang di halaman batch Breakdown Mesin.');
    }

    public function test_kunci_draft_dipisah_per_pengguna(): void
    {
        $this->assertStringContainsString('optima_draft_breakdown_mesin_u'.auth()->id(), $this->halaman());
    }

    public function test_draft_dibersihkan_setelah_submit_bukan_dipulihkan_lagi(): void
    {
        // optima_draft_breakdown_mesin_u{id} BUKAN substring dari
        // optima_draft_breakdown_mesin_terkirim_u{id} (kata "_terkirim" ada di
        // TENGAH, sebelum _u{id}) — jadi test_kunci_draft_dipisah_per_pengguna
        // di atas tidak membuktikan apa pun soal ejaan kunci backup ini. Tanpa
        // asersi ini, typo seperti "optima_draft_breakdown_mesin_u{id}_terkirim"
        // akan tetap lolos hijau.
        $html = $this->halaman();

        $this->assertStringContainsString('optima_draft_breakdown_mesin_terkirim_u'.auth()->id(), $html);
        $this->assertStringContainsString('formBatch', $html);
    }

    public function test_kunci_draft_cocok_dengan_yang_dihapus_index(): void
    {
        // create.blade.php merender literal cfg.kunci/cfg.kunciBackup ke HTML.
        // index.blade.php WAJIB menghapus persis kunci yang sama lewat
        // localStorage.removeItem(...) setelah flash batch_tersimpan — kalau
        // kuncinya tidak cocok (typo salin-tempel), backup draft yang sudah
        // tersimpan di server tidak pernah dibersihkan, mulai() memulihkannya
        // lagi di kunjungan berikutnya, dan operator mengirim ulang batch yang
        // sudah tersimpan.
        //
        // Kuncinya diekstrak dari HTML create yang BENAR-BENAR dirender, bukan
        // dihardcode dua kali di sini.
        $create = $this->halaman();

        preg_match('/\bkunci:\s*\'([^\']+)\'/', $create, $mKunci);
        preg_match('/\bkunciBackup:\s*\'([^\']+)\'/', $create, $mBackup);

        $this->assertNotEmpty($mKunci, 'cfg.kunci tidak ditemukan di halaman create — regex perlu disesuaikan.');
        $this->assertNotEmpty($mBackup, 'cfg.kunciBackup tidak ditemukan di halaman create — regex perlu disesuaikan.');

        $kunci = $mKunci[1];
        $kunciBackup = $mBackup[1];

        $index = $this->withSession(['batch_tersimpan' => 3])
            ->get(route('maintenance.breakdown.index'))->assertOk()->getContent();

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
        // Server-rendered HTML bisa 200 OK sempurna sementara browser
        // ReferenceError di JS. Jangkarkan ke SETIAP pemanggilan
        // x-data="taField(IDENT" atau x-data="partPicker(IDENT" di HTML yang
        // benar-benar dirender, lalu buktikan "const IDENT =" ada di halaman
        // yang sama.
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

    public function test_nomor_urut_awal_diisi_dari_next_nomor_urut(): void
    {
        // nextNomorUrut() = tertinggi + 1 di seluruh no_lkm yang ada (termasuk
        // soft-deleted). Buat satu baris ber-no_lkm "7/LKM/..." supaya
        // nextNomorUrut() = 8, lalu buktikan nilai itu yang dirender sebagai
        // value awal input nomor_urut — bukan angka 1 hardcode.
        $mesin = MasterMesin::first();
        TransaksiBreakdownMesin::create([
            'no_lkm' => '7/LKM/MTC/KMI/VIII/26',
            'id_mesin' => $mesin->id,
            'tanggal_breakdown' => '2026-08-01', 'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-08-01', 'jam_mulai_perbaikan' => '08:10',
            'tanggal_selesai_perbaikan' => '2026-08-01', 'jam_selesai_perbaikan' => '09:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
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

    public function test_halaman_memasang_setelah_pulih(): void
    {
        // Jangkarkan ke DALAM blok konfigBreakdownMesin(), bukan di seluruh
        // HTML — nama "setelahPulih" juga muncul di definisi batchDraft() di
        // layout, yang terender di SETIAP halaman.
        $html = $this->halaman();

        $awal = strpos($html, 'function konfigBreakdownMesin()');
        $this->assertNotFalse($awal, 'Deklarasi function konfigBreakdownMesin() tidak ditemukan.');

        $blokKonfig = substr($html, $awal);

        $this->assertMatchesRegularExpression(
            '/\bsetelahPulih\s*\(\s*diri\s*\)\s*\{/',
            $blokKonfig,
            'konfigBreakdownMesin() tidak memasang cfg.setelahPulih(diri) — nomor_urut tidak akan menaik setelah draft dipulihkan.'
        );
    }

    public function test_halaman_tidak_mengirim_angka_turunan_dari_browser(): void
    {
        // Server (BreakdownMesinService::computeDerived) yang menghitung
        // repair_time/total_downtime/status_breakdown. Jangkarkan ke blok
        // payload( itu sendiri, bukan seluruh halaman.
        $html = $this->halaman();

        $awal = strpos($html, 'payload(r) {');
        $this->assertNotFalse($awal, 'Deklarasi payload(r) { tidak ditemukan di konfigBreakdownMesin().');

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
}
