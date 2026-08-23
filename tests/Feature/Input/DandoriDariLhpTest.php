<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use App\Services\DandoriRincianService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Dandori dicatat dari form LHP Produksi. Yang digabungkan INPUT-nya, bukan
 * tabelnya: baris rincian tetap ditulis dengan bentuk yang sama persis, jadi
 * tidak satu pun perhitungan (Availability, OEE, Achievement, Loss Time)
 * membaca sesuatu yang berbeda.
 */
class DandoriDariLhpTest extends TestCase
{
    use RefreshDatabase;

    private $shift;

    private $karyawan;

    private $karyawanLain;

    private $mesin;

    private $proses;

    private $prosesProgressive;

    private $losstime;

    private $losstimeKedua;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shift = MasterShift::create([
            'nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00',
        ]);
        $this->karyawan = MasterKaryawan::create([
            'nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->karyawanLain = MasterKaryawan::create([
            'nik' => 'K2', 'nama_karyawan' => 'OPERATOR DUA', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $this->mesin = MasterMesin::create([
            'id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A',
            'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $this->proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500,
        ]);
        $this->prosesProgressive = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 2, 'nama_proses' => 'PROGRESSIVE', 'sph_standar_produksi' => 500,
        ]);
        $this->losstime = MasterLosstime::create([
            'kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES',
            'std_menit_default' => 30, 'std_menit_progressive' => 45,
        ]);
        $this->losstimeKedua = MasterLosstime::create([
            'kode' => 'LT2', 'jenis_losstime' => 'SETTING',
            'std_menit_default' => 15, 'std_menit_progressive' => 20,
        ]);

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function sesi(?int $idProses = null): array
    {
        return [
            'tanggal' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $idProses ?? $this->proses->id,
        ];
    }

    public function test_menulis_satu_baris_per_rincian(): void
    {
        $jumlah = app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $this->assertSame(1, $jumlah);
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    public function test_std_menit_diambil_dari_master_dan_over_dihitung(): void
    {
        app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertEquals(30, $baris->std_menit);
        $this->assertEquals(20, $baris->over_menit);
    }

    public function test_proses_progressive_memakai_std_progressive(): void
    {
        // Cabang PROGRESSIVE sudah ada di DandoriController; ia harus ikut
        // pindah utuh, bukan disederhanakan.
        app(DandoriRincianService::class)->simpan($this->sesi($this->prosesProgressive->id), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
        ]);

        $baris = TransaksiDandoriRincian::first();
        $this->assertEquals(45, $baris->std_menit);
        $this->assertEquals(5, $baris->over_menit);
    }

    public function test_over_menit_tidak_pernah_negatif(): void
    {
        app(DandoriRincianService::class)->simpan($this->sesi(), [
            ['id_losstime' => $this->losstime->id, 'actual_menit' => 10],
        ]);

        $this->assertEquals(0, TransaksiDandoriRincian::first()->over_menit);
    }

    public function test_rincian_kosong_tidak_menulis_apa_pun(): void
    {
        $jumlah = app(DandoriRincianService::class)->simpan($this->sesi(), []);

        $this->assertSame(0, $jumlah);
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    /** Payload LHP minimal yang sah; blok dandori ditambahkan per test. */
    private function payloadLhp(array $tambahan = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00',
            'jam_finish' => '15:00',
            'qty_aktual' => 1000,
            'ng_count' => 0,
        ], $tambahan);
    }

    /** Satu baris dikirim lewat endpoint batch. */
    private function simpanLhp(array $payload)
    {
        return $this->post(route('input.produksi.batch'), ['rows' => [$payload]]);
    }

    public function test_menyimpan_rincian_dandori_bersama_lhp(): void
    {
        $this->simpanLhp($this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 2,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
                ['id_losstime' => $this->losstimeKedua->id, 'actual_menit' => 10],
            ],
        ]))->assertRedirect();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(2, TransaksiDandoriRincian::count());

        // Field sesi harus persis mengikuti baris LHP-nya.
        $baris = TransaksiDandoriRincian::first();
        $this->assertSame('2026-08-20', $baris->tanggal->toDateString());
        $this->assertSame($this->shift->id, $baris->id_shift);
        $this->assertSame($this->mesin->id, $baris->id_mesin);
        $this->assertSame($this->proses->id, $baris->id_part_proses);
        $this->assertSame($this->karyawan->id, $baris->id_karyawan);
    }

    public function test_tanpa_centang_tidak_menulis_dandori(): void
    {
        $this->simpanLhp($this->payloadLhp([
            'dandori_count' => 0,
        ]))->assertRedirect();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_hitungan_tidak_cocok_ditolak(): void
    {
        // dandori_count membuktikan Alpine hidup, bukan bahwa baris dandori[]
        // benar-benar sampai. Tanpa penjaga ini, payload terpotong menyimpan
        // NOL baris dandori tanpa satu pun error — operator mengira tercatat.
        $this->simpanLhp($this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 2,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
            ],
        ]))->assertSessionHasErrors('rows.0.dandori_count');

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_jenis_loss_time_kembar_diterima(): void
    {
        // Satu sesi bisa punya beberapa pergantian dies, dan tiap pergantian
        // memunculkan jenis loss time yang sama lagi — itu kejadian normal di
        // lantai produksi, bukan salah ketik. Halaman Dandori memang sudah
        // mengizinkannya; jalur batch sempat menolaknya dan bikin operator
        // buntu. Dua-duanya tersimpan sebagai baris terpisah, menitnya utuh.
        $this->simpanLhp($this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 2,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 20],
            ],
        ]))->assertRedirect();

        $this->assertSame(2, TransaksiDandoriRincian::count());

        $menit = TransaksiDandoriRincian::orderBy('id')->pluck('actual_menit')
            ->map(fn ($m) => (float) $m)->all();
        $this->assertSame([50.0, 20.0], $menit);

        // Keduanya jenis yang sama — tidak digabung jadi satu baris 70 menit.
        $this->assertSame(
            [$this->losstime->id, $this->losstime->id],
            TransaksiDandoriRincian::orderBy('id')->pluck('id_losstime')->all()
        );
    }

    public function test_operator_dandori_bisa_beda_dari_lhp(): void
    {
        // Probe prod menemukan 250 sesi dengan operator dandori berbeda dari
        // operator LHP-nya; mewarisi tanpa bisa diubah memaksa data yang salah.
        $this->simpanLhp($this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori_karyawan_id' => $this->karyawanLain->id,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
            ],
        ]))->assertRedirect();

        $this->assertSame($this->karyawanLain->id, TransaksiDandoriRincian::first()->id_karyawan);
    }

    public function test_validasi_gagal_sebelum_transaksi_menulis_apa_pun(): void
    {
        // Ini membuktikan urutan validasi FormRequest terjadi SEBELUM controller
        // (dan DB::transaction di dalamnya) pernah jalan — bukan membuktikan
        // rollback. qty_aktual negatif ditolak StoreProduksiBatchRequest, jadi baris
        // di bawah ini hijau walau transaksinya sendiri tak pernah dibuka.
        // Kasus rollback-di-dalam-transaksi ada di
        // test_gagal_di_tengah_transaksi_membatalkan_lhp_dan_dandori di bawah.
        $this->simpanLhp($this->payloadLhp([
            'qty_aktual' => -5,
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [
                ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
            ],
        ]))->assertSessionHasErrors();

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_gagal_di_tengah_transaksi_membatalkan_lhp_dan_dandori(): void
    {
        // Beda dari test di atas: di sini validasi LOLOS, jadi controller
        // sungguh masuk ke DB::transaction() dan TransaksiProduksi::create()
        // sungguh jalan. Baru setelah itu DandoriRincianService dipaksa
        // meledak lewat double — membuktikan baris LHP yang sudah tertulis
        // ikut dibatalkan, bukan cuma request-nya ditolak lebih awal.
        $this->app->instance(DandoriRincianService::class, new class extends DandoriRincianService
        {
            public function simpan(array $sesi, array $rincian): int
            {
                throw new \RuntimeException('dandori sengaja gagal untuk menguji rollback');
            }
        });

        // Exception dibiarkan tembus (bukan diubah handler jadi respons 500)
        // supaya test ini deterministik: kalau DB::transaction dicopot, error
        // ini akan lolos sampai sini TANPA rollback — baris LHP akan bertahan.
        $this->withoutExceptionHandling();

        try {
            $this->simpanLhp($this->payloadLhp([
                'ada_dandori' => 1,
                'dandori_count' => 1,
                'dandori' => [
                    ['id_losstime' => $this->losstime->id, 'actual_menit' => 50],
                ],
            ]));
        } catch (\RuntimeException $e) {
            $this->assertSame('dandori sengaja gagal untuk menguji rollback', $e->getMessage());
        }

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    /** Ambil isi satu blok saja — nama field muncul di lebih dari satu tempat di halaman. */
    /**
     * Potong tepat sepanjang blok dandori. Dijangkarkan ke penanda pembuka dan
     * penutupnya, bukan ke jumlah karakter tetap: nama field dandori muncul di
     * lebih dari satu tempat pada halaman yang sama, dan blok ini tumbuh.
     */
    private function blokDandori(string $html): string
    {
        $awal = strpos($html, 'class="dandori-rows"');
        $this->assertNotFalse($awal, 'Blok dandori tidak ditemukan.');

        $akhir = strpos($html, '<!-- /dandori-rows -->', $awal);
        $this->assertNotFalse($akhir, 'Penanda penutup blok dandori tidak ditemukan.');

        return substr($html, $awal, $akhir - $awal);
    }

    public function test_form_create_punya_blok_dandori(): void
    {
        $html = $this->get(route('input.produksi.create'))->assertOk()->getContent();

        $this->assertStringContainsString('name="ada_dandori"', $html);
        $this->assertStringContainsString('dandori_count', $this->blokDandori($html));
    }

    public function test_form_edit_tidak_punya_blok_dandori(): void
    {
        // Baris dandori tidak menyimpan tautan ke baris LHP, jadi di Edit tidak
        // ada cara jujur untuk tahu baris mana yang "milik" baris LHP ini.
        $produksi = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00:00',
            'jam_finish' => '15:00:00',
            'qty_aktual' => 1000,
            'created_by' => auth()->id(),
        ]);

        // Route edit LHP Produksi admin-only — beda dari create/index yang
        // dipakai staff_produksi di test lain pada berkas ini.
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $html = $this->get(route('input.produksi.edit', $produksi))->assertOk()->getContent();

        $this->assertStringNotContainsString('name="ada_dandori"', $html);
    }

    public function test_halaman_dandori_menandai_input_sebagai_jalur_sekunder(): void
    {
        // 17% sesi dandori pada era aplikasi tidak punya baris LHP — ganti dies
        // yang produksinya batal atau jalan di shift berikutnya. Mencabut
        // tombolnya membuat kejadian itu tidak bisa dicatat sama sekali.
        $html = $this->get(route('input.dandori.index'))->assertOk()->getContent();

        $this->assertStringContainsString('Dandori tanpa LHP', $html);
    }

    public function test_mengubah_lhp_tidak_menyentuh_dandori(): void
    {
        $this->simpanLhp($this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->losstime->id, 'actual_menit' => 50]],
        ]))->assertRedirect();

        $produksi = TransaksiProduksi::first();
        $sebelum = TransaksiDandoriRincian::first()->only(['id_losstime', 'actual_menit', 'std_menit', 'over_menit']);

        // Route edit/update LHP Produksi admin-only — beda dari create/store
        // yang dipakai staff_produksi di setUp() dan test lain pada berkas ini.
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $this->put(route('input.produksi.update', $produksi), $this->payloadLhp([
            'qty_aktual' => 2000,
        ]))->assertRedirect();

        $this->assertSame(1, TransaksiDandoriRincian::count());
        $this->assertSame($sebelum, TransaksiDandoriRincian::first()->only(['id_losstime', 'actual_menit', 'std_menit', 'over_menit']));
    }

    public function test_menghapus_lhp_tidak_menghapus_dandori(): void
    {
        // Keduanya catatan yang berdiri sendiri; dandori tetap sah walau baris
        // LHP-nya dihapus.
        $this->simpanLhp($this->payloadLhp([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->losstime->id, 'actual_menit' => 50]],
        ]))->assertRedirect();

        $this->delete(route('input.produksi.destroy', TransaksiProduksi::first()))->assertRedirect();

        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    /* ── Blok dandori dibuat seragam dengan halaman Dandori ───────────────── */

    public static function formPembuatan(): array
    {
        return [
            'create' => ['input.produksi.create'],
        ];
    }

    #[DataProvider('formPembuatan')]
    public function test_jenis_loss_time_memakai_typeahead_bukan_dropdown(string $rute): void
    {
        $blok = $this->blokDandori($this->get(route($rute))->assertOk()->getContent());

        // Dropdown apa pun di dalam blok berarti masih ada field yang belum
        // diseragamkan dengan halaman Dandori — termasuk Operator Dandori.
        $this->assertStringNotContainsString('<select', $blok);

        $this->assertStringContainsString('ta-input', $blok);
        $this->assertStringContainsString('Ketik kode atau jenis', $blok);
        $this->assertStringContainsString('dandori[${i}][id_losstime]', $blok);
    }

    #[DataProvider('formPembuatan')]
    public function test_blok_dandori_menampilkan_std_dan_over(string $rute): void
    {
        $blok = $this->blokDandori($this->get(route($rute))->assertOk()->getContent());

        $this->assertStringContainsString('Std (mnt)', $blok);
        $this->assertStringContainsString('Over (mnt)', $blok);
        $this->assertStringContainsString('std_menit_progressive', $blok);
    }

    #[DataProvider('formPembuatan')]
    public function test_operator_dandori_memakai_typeahead_bersama(string $rute): void
    {
        $blok = $this->blokDandori($this->get(route($rute))->assertOk()->getContent());

        // taField() satu definisi di layout — jangan disalin ulang di partial.
        $this->assertStringContainsString('taField(taKaryawan', $blok);
        $this->assertStringContainsString('name="dandori_karyawan_id"', $blok);
    }

    #[DataProvider('formPembuatan')]
    public function test_pilihan_proses_membawa_penanda_progressive(string $rute): void
    {
        // Pratinjau Std/Over di browser harus tahu proses PROGRESSIVE atau bukan.
        // Penandanya menempel di <option>, bukan menebak dari teks pilihannya.
        $html = $this->get(route($rute))->assertOk()->getContent();

        $this->assertStringContainsString('data-progressive', $html);
    }

    /* ── Kemasan blok dandori seragam dengan blok NG ──────────────────────── */

    /** Benar-benar memeriksa pohon DOM, bukan kedekatan teks di HTML. */
    private function dibungkusPanel(string $html, string $idCheckbox): bool
    {
        $dom = new \DOMDocument;
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();

        $xpath = new \DOMXPath($dom);
        $cocok = $xpath->query(
            "//input[@id='{$idCheckbox}']/ancestor::div[contains(concat(' ', normalize-space(@class), ' '), ' input-panel ')]"
        );

        return $cocok !== false && $cocok->length > 0;
    }

    #[DataProvider('formPembuatan')]
    public function test_blok_ng_dan_dandori_memakai_kemasan_yang_sama(string $rute): void
    {
        $html = $this->get(route($rute))->assertOk()->getContent();
        $akhiran = $rute === 'input.produksi.index' ? '_drawer' : '';

        $this->assertTrue(
            $this->dibungkusPanel($html, 'ada_ng'.$akhiran),
            "Blok NG di {$rute} tidak dibungkus .input-panel."
        );
        $this->assertTrue(
            $this->dibungkusPanel($html, 'ada_dandori'.$akhiran),
            "Blok Dandori di {$rute} tidak dibungkus .input-panel — kemasannya beda dari blok NG."
        );
    }

    public function test_kemasan_panel_tidak_ditulis_ulang_sebaris(): void
    {
        // Panel NG sempat punya dua bentuk berbeda (p-3 vs padding:12px) di dua
        // form. Gaya sebaris yang disalin ulang selalu berakhir menyimpang.
        $produksi = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->shift->id,
            'id_karyawan' => $this->karyawan->id,
            'id_mesin' => $this->mesin->id,
            'id_part_proses' => $this->proses->id,
            'jam_start' => '07:00:00',
            'jam_finish' => '15:00:00',
            'qty_aktual' => 1000,
            'created_by' => auth()->id(),
        ]);

        // Route edit admin-only; admin juga bisa membuka create dan index.
        $this->actingAs(User::factory()->create(['role' => 'admin']));

        $halaman = [
            route('input.produksi.create'),
            route('input.produksi.edit', $produksi),
        ];

        foreach ($halaman as $url) {
            $html = $this->get($url)->assertOk()->getContent();

            $blokNg = substr($html, (int) strpos($html, 'name="ada_ng"') - 600, 600);
            $this->assertStringNotContainsString('background:#fafafa', $blokNg,
                "Panel di {$url} masih memakai gaya sebaris, bukan .input-panel.");
        }
    }
}
