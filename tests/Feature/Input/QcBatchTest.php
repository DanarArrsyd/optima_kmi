<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Models\User;
use App\Services\NgDetailService;
use App\Services\QcService;
use App\Services\QcWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QcBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Insp Batch', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $part = MasterPart::create(['part_no' => 'QB-P1', 'part_name' => 'QC Batch Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->ref = [
            'shift' => $shift->id,
            'inspector' => $inspector->id,
            'proses' => $proses->id,
            'dented' => MasterJenisNg::create(['kode' => 'DNT', 'nama_ng' => 'Dented'])->id,
            'crack' => MasterJenisNg::create(['kode' => 'CRK', 'nama_ng' => 'Crack'])->id,
        ];
    }

    private function baris(array $ubah = []): array
    {
        return [
            'tgl_pengecekan' => '2026-07-28',
            'tgl_produksi_part' => '2026-07-27',
            'id_shift' => $this->ref['shift'],
            'id_karyawan_qc' => $this->ref['inspector'],
            'id_part_proses' => $this->ref['proses'],
            'jam_start' => '08:00',
            'jam_finish' => '12:00',
            'qty_ok' => 90,
            'ng_count' => 0,
            'ng' => [],
            ...$ubah,
        ];
    }

    private function kirim(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('input.qc.batch'), ['rows' => $rows]);
    }

    private function kirimJson(array $rows)
    {
        return $this->actingAs($this->admin)
            ->post(route('input.qc.batch'), ['rows_json' => json_encode($rows)]);
    }

    public function test_satu_submit_menulis_semua_baris(): void
    {
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['jam_start' => '13:00', 'jam_finish' => '16:00', 'qty_ok' => 50]),
        ]);

        $response->assertRedirect(route('input.qc.index'));
        $this->assertSame(2, TransaksiQc::count());
    }

    public function test_pesan_sukses_menyebut_jumlah_baris(): void
    {
        $this->kirim([$this->baris(), $this->baris(['qty_ok' => 10, 'jam_start' => '13:00', 'jam_finish' => '15:00'])])
            ->assertSessionHas('batch_tersimpan', 2);
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        // Rule 'rows.*.ng.*.qty' => 'required|integer|min:1' dipicu dengan
        // qty NG = 0 (bukan id_shift null): default Inggris Laravel untuk
        // required adalah "The :attribute field is required." — TIDAK
        // mengandung "field must be", jadi assertion lama lolos apapun isi
        // messages()/attributes(), bahkan kalau dua-duanya dihapus total.
        // min:1 defaultnya genuinely "The :attribute field must be at least
        // 1.", beda kata dengan versi custom, jadi baru rule ini yang
        // membuktikan messages() dipakai. ng_count tetap disamakan dengan
        // jumlah baris ng[] supaya baris ditolak oleh aturan min, bukan oleh
        // guard periksaHitunganNg().
        $this->kirim([$this->baris([
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 0]],
        ])]);

        // session('errors') di sini array mentah, bukan objek ViewErrorBag:
        // config/session.php pakai 'serialization' => 'json' (Laravel 11+),
        // jadi objek yang di-flash ke sesi pulang sebagai array biasa setelah
        // ditulis lalu dibaca ulang dari handler.
        $pesan = session('errors')['default']['messages']['rows.0.ng.0.qty'][0] ?? '';

        $this->assertSame('Baris 1: Qty NG minimal 1.', $pesan);
    }

    public function test_rincian_ng_menempel_pada_barisnya_masing_masing(): void
    {
        $this->kirim([
            $this->baris(['ng_count' => 1, 'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 4]]]),
            $this->baris(['jam_start' => '13:00', 'jam_finish' => '16:00', 'ng_count' => 0, 'ng' => []]),
        ]);

        $baris = TransaksiQc::orderBy('id')->get();
        $this->assertSame(4, (int) $baris[0]->qty_ng);
        $this->assertSame(0, (int) $baris[1]->qty_ng);
    }

    public function test_qty_ok_nol_tanpa_ng_ditolak_per_baris(): void
    {
        // periksaTotalQty(): total qty check (OK + NG) harus > 0 per baris.
        // Baris baik dikirim lebih dulu supaya index bukan kebetulan cocok:
        // implementasi yang hardcode rows.0 (atau meleset satu index) akan
        // tetap lolos kalau baris pelanggar selalu diletakkan di index 0.
        $this->kirim([
            $this->baris(),
            $this->baris(['qty_ok' => 0, 'ng_count' => 0, 'ng' => []]),
        ])
            ->assertSessionHasErrors('rows.1.qty_ok')
            ->assertSessionDoesntHaveErrors('rows.0.qty_ok');

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_qty_ok_nol_tapi_ada_ng_diterima(): void
    {
        $this->kirim([$this->baris([
            'qty_ok' => 0,
            'ng_count' => 1,
            'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 3]],
        ])])->assertRedirect(route('input.qc.index'));

        $this->assertSame(3, (int) TransaksiQc::first()->qty_ng);
    }

    public function test_hitungan_ng_tidak_cocok_ditolak_per_baris(): void
    {
        // ng_count membuktikan Alpine hidup, bukan bahwa baris ng[] sampai.
        // Baris baik dikirim lebih dulu supaya index bukan kebetulan cocok
        // (lihat catatan di test_qty_ok_nol_tanpa_ng_ditolak_per_baris).
        $this->kirim([
            $this->baris(),
            $this->baris(['ng_count' => 2, 'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 3]]]),
        ])
            ->assertSessionHasErrors('rows.1.ng_count')
            ->assertSessionDoesntHaveErrors('rows.0.ng_count');

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_jenis_ng_kembar_diterima_dan_qty_dijumlah(): void
    {
        $this->kirim([$this->baris([
            'ng_count' => 2,
            'ng' => [
                ['id_jenis_ng' => $this->ref['dented'], 'qty' => 5],
                ['id_jenis_ng' => $this->ref['dented'], 'qty' => 3],
            ],
        ])]);

        $trx = TransaksiQc::first();
        $this->assertCount(1, $trx->ngDetails);
        $this->assertSame(8, (int) $trx->qty_ng);
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('input.qc.batch'), ['rows' => [$this->baris()]])
            ->assertForbidden();

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_rows_json_menyimpan_baris(): void
    {
        $this->kirimJson([$this->baris()])->assertRedirect(route('input.qc.index'));
        $this->assertSame(1, TransaksiQc::count());
    }

    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('input.qc.batch'), ['rows_json' => '{bukan json'])
            ->assertSessionHasErrors('rows');

        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_rows_json_menampung_50_baris_batas_server(): void
    {
        $rows = [];
        for ($i = 0; $i < 50; $i++) {
            $tgl = date('Y-m-d', strtotime("2026-07-28 -{$i} day"));
            $rows[] = $this->baris(['qty_ok' => 10 + $i, 'tgl_pengecekan' => $tgl, 'tgl_produksi_part' => $tgl]);
        }

        $this->kirimJson($rows)->assertRedirect(route('input.qc.index'));
        $this->assertSame(50, TransaksiQc::count());
    }

    public function test_lebih_dari_50_baris_ditolak(): void
    {
        $rows = [];
        for ($i = 0; $i < 51; $i++) {
            $rows[] = $this->baris(['qty_ok' => 10 + $i]);
        }

        $this->kirimJson($rows)->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_string_kosong_di_rows_json_jadi_null(): void
    {
        // Browser mengirim "" untuk field yang tidak diisi. Tanpa normalisasi,
        // isi di dalam satu string JSON (rows_json) tidak terlihat oleh
        // middleware bawaan Laravel yang biasanya mengubah '' jadi null pada
        // field POST biasa, jadi '' lolos utuh sampai ke rules().
        //
        // `ng` adalah satu-satunya field QC di mana normalisasi ini benar-
        // benar mengubah hasil: aturannya `nullable|array`, dan '' gagal
        // `array` sementara null lolos `nullable`. Semua field lain wajib
        // (`required`), yang menolak '' dan null sama saja — makanya field
        // itu yang dipilih di sini, bukan sekadar mengulang default baris().
        $this->kirimJson([$this->baris(['ng' => '', 'ng_count' => 0])])
            ->assertRedirect(route('input.qc.index'));

        $this->assertSame(1, TransaksiQc::count());
    }

    public function test_satu_baris_gagal_membatalkan_semuanya_di_validasi(): void
    {
        // Baris ke-2 memakai id_shift yang tidak ada: request DITOLAK di
        // FormRequest, controller tidak pernah dimasuki, jadi tidak ada
        // satu pun baris yang sempat ditulis untuk dibatalkan. Ini beda
        // dari test_gagal_di_tengah_transaksi_tidak_menyisakan_baris di
        // bawah, yang membuktikan DB::transaction() SUNGGUH membatalkan
        // baris yang sudah tertulis.
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['id_shift' => 999999]),
        ]);

        $response->assertSessionHasErrors('rows.1.id_shift');
        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    {
        // Validasi LOLOS, jadi controller sungguh masuk DB::transaction() dan
        // baris pertama sungguh tertulis ke DB (double di bawah memanggil
        // parent::simpan() sungguhan untuk panggilan pertama). Baru pada
        // panggilan kedua QcWriteService dipaksa meledak — membuktikan baris
        // pertama ikut dibatalkan oleh transaksi, bukan sekadar request
        // ditolak lebih awal oleh validasi seperti test di atas.
        $this->app->instance(QcWriteService::class, new class($this->app->make(QcService::class), $this->app->make(NgDetailService::class)) extends QcWriteService
        {
            private int $panggilan = 0;

            public function simpan(array $baris): TransaksiQc
            {
                $this->panggilan++;

                if ($this->panggilan >= 2) {
                    throw new \RuntimeException('baris kedua sengaja gagal untuk menguji rollback');
                }

                return parent::simpan($baris);
            }
        });

        // Exception dibiarkan tembus supaya test ini deterministik: kalau
        // DB::transaction dicopot, error ini lolos sampai sini TANPA rollback
        // dan baris pertama akan bertahan.
        $this->withoutExceptionHandling();

        try {
            $this->kirim([
                $this->baris(),
                $this->baris(['jam_start' => '13:00', 'jam_finish' => '16:00']),
            ]);
        } catch (\RuntimeException $e) {
            $this->assertSame('baris kedua sengaja gagal untuk menguji rollback', $e->getMessage());
        }

        $this->assertSame(0, TransaksiQc::count());
    }
}
