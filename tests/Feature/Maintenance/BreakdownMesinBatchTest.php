<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterMesin;
use App\Models\TransaksiBreakdownMesin;
use App\Models\User;
use App\Services\BreakdownMesinService;
use App\Services\BreakdownMesinWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BreakdownMesinBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $mesinId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $mesin = MasterMesin::create([
            'id_mesin' => 'BM-001', 'nama_mesin' => 'MESIN BM', 'line' => 'A',
            'tipe_mesin' => 'STAMPING', 'kapasitas_max_stroke' => 100,
        ]);
        $this->mesinId = $mesin->id;
    }

    private function baris(array $ubah = []): array
    {
        return [
            'nomor_urut' => 1,
            'id_mesin' => $this->mesinId,
            'tanggal_breakdown' => '2026-07-28',
            'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-28',
            'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-28',
            'jam_selesai_perbaikan' => '11:00',
            'problem_mesin' => 'Motor overheat',
            'penyebab_breakdown' => 'Bearing aus',
            'tindakan_perbaikan' => 'Ganti bearing',
            'pic_maintenance' => 'Budi',
            ...$ubah,
        ];
    }

    private function kirim(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('maintenance.breakdown.batch'), ['rows' => $rows]);
    }

    private function kirimJson(array $rows)
    {
        return $this->actingAs($this->admin)
            ->post(route('maintenance.breakdown.batch'), ['rows_json' => json_encode($rows)]);
    }

    public function test_satu_submit_menulis_semua_baris(): void
    {
        $response = $this->kirim([
            $this->baris(['nomor_urut' => 1]),
            $this->baris(['nomor_urut' => 2]),
        ]);

        $response->assertRedirect(route('maintenance.breakdown.index'));
        $this->assertSame(2, TransaksiBreakdownMesin::count());
    }

    public function test_no_lkm_dirakit_dari_nomor_urut_dan_tanggal(): void
    {
        $this->kirim([
            $this->baris([
                'nomor_urut' => 5,
                'tanggal_breakdown' => '2026-08-10',
                'tanggal_mulai_perbaikan' => '2026-08-10',
                'tanggal_selesai_perbaikan' => '2026-08-10',
            ]),
        ]);

        $b = TransaksiBreakdownMesin::first();
        $this->assertSame('5/LKM/MTC/KMI/VIII/26', $b->no_lkm);
    }

    public function test_no_lkm_bentrok_dengan_data_lama_ditolak(): void
    {
        TransaksiBreakdownMesin::create([
            'no_lkm' => '3/LKM/MTC/KMI/VII/26',
            'id_mesin' => $this->mesinId,
            'tanggal_breakdown' => '2026-07-01',
            'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01',
            'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01',
            'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'pic_maintenance' => 'x', 'status_breakdown' => 'Ringan',
            'repair_time' => 1, 'total_downtime' => 2,
        ]);

        $response = $this->kirim([
            $this->baris(['nomor_urut' => 3, 'tanggal_breakdown' => '2026-07-28']),
        ]);

        $response->assertSessionHasErrors('rows.0.nomor_urut');
        $this->assertSame(1, TransaksiBreakdownMesin::count());
    }

    public function test_no_lkm_bentrok_dengan_baris_terhapus_juga_ditolak(): void
    {
        $b = TransaksiBreakdownMesin::create([
            'no_lkm' => '7/LKM/MTC/KMI/VII/26',
            'id_mesin' => $this->mesinId,
            'tanggal_breakdown' => '2026-07-01',
            'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-01',
            'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-01',
            'jam_selesai_perbaikan' => '10:00',
            'problem_mesin' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'pic_maintenance' => 'x', 'status_breakdown' => 'Ringan',
            'repair_time' => 1, 'total_downtime' => 2,
        ]);
        $b->delete();

        $response = $this->kirim([
            $this->baris(['nomor_urut' => 7, 'tanggal_breakdown' => '2026-07-15']),
        ]);

        $response->assertSessionHasErrors('rows.0.nomor_urut');
        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_no_lkm_kembar_antar_baris_dalam_satu_draft_ditolak(): void
    {
        // Baris pertama SAH sendirian supaya indeks yang diuji sungguh-sungguh
        // menunjuk ke baris KEDUA, bukan kebetulan baris pertama yang gagal.
        $response = $this->kirim([
            $this->baris(['nomor_urut' => 9, 'tanggal_breakdown' => '2026-07-05']),
            $this->baris(['nomor_urut' => 9, 'tanggal_breakdown' => '2026-07-20']),
        ]);

        $response->assertSessionHasErrors('rows.1.nomor_urut');
        $response->assertSessionDoesntHaveErrors('rows.0.nomor_urut');
        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_nomor_sama_di_bulan_berbeda_diterima(): void
    {
        // Ini mencegah "perbaikan" naif yang membandingkan nomor_urut
        // telanjang: 5/VIII dan 5/IX adalah dua No LKM sah yang berbeda.
        $response = $this->kirim([
            $this->baris([
                'nomor_urut' => 5,
                'tanggal_breakdown' => '2026-07-05',
                'tanggal_mulai_perbaikan' => '2026-07-05',
                'tanggal_selesai_perbaikan' => '2026-07-05',
            ]),
            $this->baris([
                'nomor_urut' => 5,
                'tanggal_breakdown' => '2026-08-05',
                'tanggal_mulai_perbaikan' => '2026-08-05',
                'tanggal_selesai_perbaikan' => '2026-08-05',
            ]),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(2, TransaksiBreakdownMesin::count());
    }

    public function test_angka_turunan_dihitung_server_bukan_dari_payload(): void
    {
        $this->kirim([
            $this->baris([
                'nomor_urut' => 11,
                'tanggal_breakdown' => '2026-07-28',
                'jam_breakdown' => '08:00',
                'tanggal_mulai_perbaikan' => '2026-07-28',
                'jam_mulai_perbaikan' => '09:00',
                'tanggal_selesai_perbaikan' => '2026-07-28',
                'jam_selesai_perbaikan' => '11:00',
                // Palsu — controller/service tidak pernah membaca angka
                // turunan ini dari payload, computeDerived() yang menghitung.
                'repair_time' => 999,
                'total_downtime' => 999,
                'status_breakdown' => 'Berat',
            ]),
        ]);

        $b = TransaksiBreakdownMesin::first();
        $this->assertSame('2.00', (string) $b->repair_time);
        $this->assertSame('3.00', (string) $b->total_downtime);
        $this->assertSame('Sedang', $b->status_breakdown);
    }

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        // Baris kedua memakai id_mesin yang tidak ada: request DITOLAK di
        // FormRequest, controller tidak pernah dimasuki, jadi tidak ada
        // satu pun baris yang sempat ditulis untuk dibatalkan.
        $response = $this->kirim([
            $this->baris(['nomor_urut' => 1]),
            $this->baris(['nomor_urut' => 2, 'id_mesin' => 999999]),
        ]);

        $response->assertSessionHasErrors('rows.1.id_mesin');
        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    {
        // Validasi LOLOS, jadi controller sungguh masuk DB::transaction() dan
        // baris pertama sungguh tertulis ke DB (double di bawah memanggil
        // parent::simpan() sungguhan untuk panggilan pertama). Baru pada
        // panggilan kedua BreakdownMesinWriteService dipaksa meledak —
        // membuktikan baris pertama ikut dibatalkan oleh transaksi.
        $this->app->instance(BreakdownMesinWriteService::class, new class(app(BreakdownMesinService::class)) extends BreakdownMesinWriteService
        {
            private int $panggilan = 0;

            public function simpan(array $baris): TransaksiBreakdownMesin
            {
                $this->panggilan++;

                if ($this->panggilan >= 2) {
                    throw new \RuntimeException('baris kedua sengaja gagal untuk menguji rollback');
                }

                return parent::simpan($baris);
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->kirim([
                $this->baris(['nomor_urut' => 1]),
                $this->baris(['nomor_urut' => 2]),
            ]);
            $this->fail('Seharusnya melempar RuntimeException dari baris kedua.');
        } catch (\RuntimeException $e) {
            $this->assertSame('baris kedua sengaja gagal untuk menguji rollback', $e->getMessage());
        }

        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('maintenance.breakdown.batch'), ['rows' => [$this->baris()]])
            ->assertForbidden();

        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_rows_json_menyimpan_baris(): void
    {
        $this->kirimJson([$this->baris()])->assertRedirect(route('maintenance.breakdown.index'));
        $this->assertSame(1, TransaksiBreakdownMesin::count());
    }

    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('maintenance.breakdown.batch'), ['rows_json' => '{bukan json'])
            ->assertSessionHasErrors('rows');

        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_rows_json_menampung_50_baris_batas_server(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = $this->baris(['nomor_urut' => $i]);
        }

        $this->kirimJson($rows)->assertRedirect(route('maintenance.breakdown.index'));
        $this->assertSame(50, TransaksiBreakdownMesin::count());
    }

    public function test_lebih_dari_50_baris_ditolak(): void
    {
        $rows = [];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = $this->baris(['nomor_urut' => $i]);
        }

        $this->kirimJson($rows)->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiBreakdownMesin::count());
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        // tanggal_breakdown.after_or_equal dipicu dengan tahun ketik salah
        // (0026), sesuai kejadian nyata di produksi yang melatarbelakangi
        // pesan versi single-row.
        $this->kirim([$this->baris(['tanggal_breakdown' => '0026-07-28'])]);

        $pesan = session('errors')['default']['messages']['rows.0.tanggal_breakdown'][0] ?? '';

        $this->assertNotSame('', $pesan);
        $this->assertStringContainsString('Baris 1', $pesan);
        $this->assertStringContainsString('tidak masuk akal', $pesan);
    }
}
