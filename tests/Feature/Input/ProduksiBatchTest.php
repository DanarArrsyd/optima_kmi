<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
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
use Tests\TestCase;

/**
 * Satu submit membawa banyak baris. Semua-atau-tidak-sama-sekali: draft di
 * browser tetap utuh saat gagal, jadi server tidak boleh menyimpan separuh.
 */
class ProduksiBatchTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '07:00:00', 'jam_finish' => '15:00:00']);
        $karyawan = MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'OPERATOR SATU', 'departemen' => 'Produksi', 'is_aktif' => true]);
        $mesin = MasterMesin::create(['id_mesin' => 'A-001', 'nama_mesin' => 'MESIN A', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $mesinLain = MasterMesin::create(['id_mesin' => 'A-002', 'nama_mesin' => 'MESIN B', 'line' => 'A', 'tipe_mesin' => 'PRESS', 'kapasitas_max_stroke' => 100]);
        $part = MasterPart::create(['part_no' => 'P-1', 'part_name' => 'PART SATU']);
        $proses = MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BENDING', 'sph_standar_produksi' => 500]);
        $jenisNg = MasterJenisNg::create(['kode' => 'NG1', 'nama_ng' => 'GORES']);
        $losstime = MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'GANTI DIES', 'std_menit_default' => 30, 'std_menit_progressive' => 45]);

        $this->ref = compact('shift', 'karyawan', 'mesin', 'mesinLain', 'proses', 'jenisNg', 'losstime');

        $this->actingAs(User::factory()->create(['role' => 'staff_produksi']));
    }

    private function baris(array $override = []): array
    {
        return array_merge([
            'tanggal_produksi' => '2026-08-20',
            'id_shift' => $this->ref['shift']->id,
            'id_karyawan' => $this->ref['karyawan']->id,
            'id_mesin' => $this->ref['mesin']->id,
            'id_part_proses' => $this->ref['proses']->id,
            'jam_start' => '07:00',
            'jam_finish' => '09:00',
            'qty_aktual' => 1000,
            'ng_count' => 0,
        ], $override);
    }

    private function kirim(array $rows)
    {
        return $this->post(route('input.produksi.batch'), ['rows' => $rows]);
    }

    /** Jalur baru create.blade.php: rows dikirim sebagai satu field JSON
     *  supaya jumlah baris tidak terikat max_input_vars PHP. */
    private function kirimJson(array $rows)
    {
        return $this->post(route('input.produksi.batch'), ['rows_json' => json_encode($rows)]);
    }

    public function test_satu_submit_menulis_semua_baris(): void
    {
        $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00']),
            $this->baris(['jam_start' => '11:00', 'jam_finish' => '13:00']),
        ])->assertRedirect(route('input.produksi.index'))->assertSessionHas('success');

        $this->assertSame(3, TransaksiProduksi::count());
    }

    public function test_pesan_sukses_menyebut_jumlah_baris(): void
    {
        $this->kirim([$this->baris(), $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00'])]);

        $this->assertStringContainsString('2 baris', session('success'));
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        $this->kirim([$this->baris(['qty_aktual' => 0])]);

        // session('errors') di sini array mentah, bukan objek ViewErrorBag:
        // config/session.php pakai 'serialization' => 'json' (Laravel 11+),
        // jadi objek yang di-flash ke sesi pulang sebagai array biasa setelah
        // ditulis lalu dibaca ulang dari handler.
        $pesan = session('errors')['default']['messages']['rows.0.qty_aktual'][0] ?? '';

        $this->assertStringNotContainsString('field must be', $pesan);
        $this->assertNotSame('', $pesan);
    }

    public function test_rincian_menempel_pada_barisnya_masing_masing(): void
    {
        $this->kirim([
            $this->baris([
                'ada_ng' => 1, 'ng_count' => 1,
                'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
            ]),
            $this->baris([
                'jam_start' => '09:00', 'jam_finish' => '11:00',
                'ada_dandori' => 1, 'dandori_count' => 1,
                'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50]],
            ]),
        ])->assertRedirect();

        $baris = TransaksiProduksi::orderBy('id')->get();
        $this->assertSame(1, $baris[0]->ngDetails()->count());
        $this->assertSame(0, $baris[1]->ngDetails()->count());
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        // Baris ke-2 memakai id_part_proses yang tidak ada.
        $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00', 'id_part_proses' => 999999]),
            $this->baris(['jam_start' => '11:00', 'jam_finish' => '13:00']),
        ])->assertSessionHasErrors('rows.1.id_part_proses');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    {
        // Validasi LOLOS, jadi controller sungguh masuk DB::transaction() dan
        // baris pertama sungguh tertulis. Baru pada baris kedua layanan dandori
        // dipaksa meledak lewat double — membuktikan baris pertama ikut
        // dibatalkan, bukan sekadar request ditolak lebih awal.
        $this->app->instance(DandoriRincianService::class, new class extends DandoriRincianService
        {
            private int $panggilan = 0;

            public function simpan(array $sesi, array $rincian): int
            {
                $this->panggilan++;

                if ($this->panggilan >= 2) {
                    throw new \RuntimeException('baris kedua sengaja gagal untuk menguji rollback');
                }

                return 0;
            }
        });

        // Exception dibiarkan tembus supaya test ini deterministik: kalau
        // DB::transaction dicopot, error ini lolos sampai sini TANPA rollback
        // dan baris pertama akan bertahan.
        $this->withoutExceptionHandling();

        try {
            $this->kirim([
                $this->baris(),
                $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00']),
            ]);
        } catch (\RuntimeException $e) {
            $this->assertSame('baris kedua sengaja gagal untuk menguji rollback', $e->getMessage());
        }

        $this->assertSame(0, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiDandoriRincian::count());
    }

    public function test_hitungan_ng_tidak_cocok_ditolak_per_baris(): void
    {
        $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00', 'ada_ng' => 1, 'ng_count' => 2, 'ng' => [
                ['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5],
            ]]),
        ])->assertSessionHasErrors('rows.1.ng_count');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_hitungan_dandori_tidak_cocok_ditolak_per_baris(): void
    {
        $this->kirim([
            $this->baris(['ada_dandori' => 1, 'dandori_count' => 2, 'dandori' => [
                ['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 50],
            ]]),
        ])->assertSessionHasErrors('rows.0.dandori_count');
    }

    public function test_jam_tumpang_tindih_di_mesin_sama_ditolak(): void
    {
        $this->kirim([
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '15:00']),
            $this->baris(['jam_start' => '08:00', 'jam_finish' => '12:00']),
        ])->assertSessionHasErrors('rows.1.jam_start');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_jam_bersentuhan_tapi_tidak_tumpang_tindih_diterima(): void
    {
        // 07:00–09:00 lalu 09:00–11:00 bukan tumpang tindih.
        $this->kirim([
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '09:00']),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00']),
        ])->assertRedirect();

        $this->assertSame(2, TransaksiProduksi::count());
    }

    public function test_jam_sama_di_mesin_berbeda_diterima(): void
    {
        $this->kirim([
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '15:00']),
            $this->baris(['jam_start' => '07:00', 'jam_finish' => '15:00', 'id_mesin' => $this->ref['mesinLain']->id]),
        ])->assertRedirect();

        $this->assertSame(2, TransaksiProduksi::count());
    }

    public function test_baris_lewat_tengah_malam_dihitung_utuh(): void
    {
        // 22:00–06:00 menempati 22:00 hari itu sampai 06:00 esoknya. Baris
        // kedua 23:00–01:00 berada di dalamnya, jadi bentrok.
        $this->kirim([
            $this->baris(['jam_start' => '22:00', 'jam_finish' => '06:00']),
            $this->baris(['jam_start' => '23:00', 'jam_finish' => '01:00']),
        ])->assertSessionHasErrors('rows.1.jam_start');
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $this->actingAs(User::factory()->create(['role' => 'guest']));

        $this->kirim([$this->baris()])->assertForbidden();

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_centang_ng_menyala_tanpa_rincian_ditolak(): void
    {
        $this->kirim([$this->baris(['ada_ng' => 1, 'ng_count' => 0, 'ng' => []])])
            ->assertSessionHasErrors('rows.0.ng');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_centang_dandori_menyala_tanpa_rincian_ditolak(): void
    {
        $this->kirim([$this->baris(['ada_dandori' => 1, 'dandori_count' => 0, 'dandori' => []])])
            ->assertSessionHasErrors('rows.0.dandori');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_rincian_diabaikan_saat_centang_mati(): void
    {
        // Centang mati tapi klien tetap mengirim rincian: baris tersimpan
        // TANPA rincian, bukan ditolak — sama seperti form satu baris.
        $this->kirim([$this->baris([
            'ada_ng' => 0,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['jenisNg']->id, 'qty' => 5]],
        ])])->assertRedirect();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(0, TransaksiProduksi::first()->ngDetails()->count());
    }

    public function test_jam_finish_sama_dengan_start_ditolak(): void
    {
        $this->kirim([$this->baris(['jam_start' => '07:00', 'jam_finish' => '07:00'])])
            ->assertSessionHasErrors('rows.0.jam_finish');

        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_rows_json_menyimpan_baris(): void
    {
        // Jalur rows_json (dipakai create.blade.php) harus menyimpan sama
        // seperti jalur rows[] langsung (dipakai test lain di berkas ini).
        $this->kirimJson([
            $this->baris(),
            $this->baris(['jam_start' => '09:00', 'jam_finish' => '11:00']),
        ])->assertRedirect(route('input.produksi.index'))->assertSessionHas('success');

        $this->assertSame(2, TransaksiProduksi::count());
    }

    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    {
        // JSON tidak valid harus jatuh ke validasi 'rows' biasa (array kosong
        // → required|min:1 menolak), bukan melempar exception json_decode.
        $response = $this->post(route('input.produksi.batch'), ['rows_json' => '{ini bukan json valid']);

        $response->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiProduksi::count());
    }

    public function test_rows_json_menampung_50_baris_batas_server(): void
    {
        // 50 baris lewat rows[] flat (cara lama) melebihi max_input_vars PHP
        // (1051 > 1000 bawaan) sebelum sampai ke sini sama sekali — PHP akan
        // memotong $_POST diam-diam. Lewat rows_json jumlah variabel POST
        // tetap konstan berapa pun banyaknya baris, jadi batas 50 baris di
        // rules() (bukan max_input_vars) yang jadi penentu. Satu mesin per
        // baris supaya tidak ada satu pun yang bentrok jam — itu di luar apa
        // yang mau dibuktikan test ini.
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $mesin = MasterMesin::create([
                'id_mesin' => sprintf('B-%03d', $i),
                'nama_mesin' => "MESIN {$i}",
                'line' => 'B',
                'tipe_mesin' => 'PRESS',
                'kapasitas_max_stroke' => 100,
            ]);
            $rows[] = $this->baris(['id_mesin' => $mesin->id]);
        }

        $this->kirimJson($rows)->assertRedirect(route('input.produksi.index'))->assertSessionHas('success');

        $this->assertSame(50, TransaksiProduksi::count());
    }

    public function test_string_kosong_di_rows_json_jadi_null(): void
    {
        // Middleware bawaan Laravel mengubah '' jadi null pada field POST biasa,
        // tapi isi di dalam satu string JSON tidak terlihat olehnya. Sebelum
        // perbaikan ini, dandori_karyawan_id = '' lolos utuh, `??` tidak
        // menangkapnya (itu cuma menangkap null), dan MySQL menolak insert:
        // "Incorrect integer value: '' for column id_karyawan". Prod 500.
        $baris = $this->baris([
            'ada_dandori' => 1,
            'dandori_count' => 1,
            'dandori' => [['id_losstime' => $this->ref['losstime']->id, 'actual_menit' => 20]],
            'dandori_karyawan_id' => '',
        ]);

        $this->post(route('input.produksi.batch'), [
            'rows_json' => json_encode([$baris]),
        ])->assertRedirect();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(1, TransaksiDandoriRincian::count());

        // Kosong berarti "ikut operator LHP", bukan "operator nomor nol".
        $this->assertSame(
            $this->ref['karyawan']->id,
            TransaksiDandoriRincian::first()->id_karyawan
        );
    }
}
