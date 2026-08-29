<?php

namespace Tests\Feature\Maintenance;

use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\TransaksiBreakdownDies;
use App\Models\User;
use App\Services\BreakdownDiesService;
use App\Services\BreakdownDiesWriteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Exception khusus dipakai double di test_gagal_di_tengah_transaksi_tidak_menyisakan_baris
 * di bawah. Kalau double melempar \RuntimeException polos, catch() di test
 * itu juga menelan $this->fail() tepat di atasnya — di PHPUnit 12,
 * AssertionFailedError adalah turunan \RuntimeException. Memakai kelas ini
 * mempersempit catch ke exception yang BENAR-BENAR dilempar double, supaya
 * fail() sungguh berarti gagal kalau exception tidak pernah terlempar.
 */
class BreakdownDiesRollbackTestException extends \RuntimeException {}

class BreakdownDiesBatchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private int $partProsesId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => 'admin']);
        $part = MasterPart::create([
            'part_no' => 'BT-091A-3', 'customer' => 'Honda', 'part_name' => 'BASE JACK BRACKET',
        ]);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 'OP10', 'nama_proses' => 'BLANKING',
        ]);
        $this->partProsesId = $proses->id;
    }

    private function baris(array $ubah = []): array
    {
        return [
            'nomor_urut' => 1,
            'id_part_proses' => $this->partProsesId,
            'tanggal_breakdown' => '2026-07-28',
            'jam_breakdown' => '08:00',
            'tanggal_mulai_perbaikan' => '2026-07-28',
            'jam_mulai_perbaikan' => '09:00',
            'tanggal_selesai_perbaikan' => '2026-07-28',
            'jam_selesai_perbaikan' => '11:00',
            'problem_dies' => 'Punch patah',
            'penyebab_breakdown' => 'Material keras',
            'tindakan_perbaikan' => 'Ganti punch',
            'pic_maintenance' => 'Budi',
            ...$ubah,
        ];
    }

    private function kirim(array $rows)
    {
        return $this->actingAs($this->admin)->post(route('maintenance.breakdown-dies.batch'), ['rows' => $rows]);
    }

    private function kirimJson(array $rows)
    {
        return $this->actingAs($this->admin)
            ->post(route('maintenance.breakdown-dies.batch'), ['rows_json' => json_encode($rows)]);
    }

    public function test_satu_submit_menulis_semua_baris(): void
    {
        $response = $this->kirim([
            $this->baris(['nomor_urut' => 1]),
            $this->baris(['nomor_urut' => 2]),
        ]);

        $response->assertRedirect(route('maintenance.breakdown-dies.index'));
        $this->assertSame(2, TransaksiBreakdownDies::count());
    }

    public function test_flash_batch_tersimpan_menghitung_baris(): void
    {
        $this->kirim([
            $this->baris(['nomor_urut' => 1]),
            $this->baris(['nomor_urut' => 2]),
        ])->assertSessionHas('batch_tersimpan', 2);
    }

    public function test_no_lkd_dirakit_dari_nomor_urut_dan_tanggal(): void
    {
        $this->kirim([
            $this->baris([
                'nomor_urut' => 5,
                'tanggal_breakdown' => '2026-08-10',
                'tanggal_mulai_perbaikan' => '2026-08-10',
                'tanggal_selesai_perbaikan' => '2026-08-10',
            ]),
        ]);

        $b = TransaksiBreakdownDies::first();
        $this->assertSame('5/LKD/MTC/KMI/VIII/26', $b->no_lkd);
    }

    private function baseRowFields(): array
    {
        return [
            'jam_breakdown' => '08:00',
            'jam_mulai_perbaikan' => '09:00',
            'jam_selesai_perbaikan' => '10:00',
            'problem_dies' => 'x', 'penyebab_breakdown' => 'x', 'tindakan_perbaikan' => 'x',
            'pic_maintenance' => 'x', 'status_breakdown' => 'Ringan',
            'repair_time' => 1, 'total_downtime' => 2,
        ];
    }

    public function test_no_lkd_bentrok_dengan_data_lama_ditolak(): void
    {
        TransaksiBreakdownDies::create([
            'no_lkd' => '3/LKD/MTC/KMI/VII/26',
            'id_part_proses' => $this->partProsesId,
            'tanggal_breakdown' => '2026-07-01',
            'tanggal_mulai_perbaikan' => '2026-07-01',
            'tanggal_selesai_perbaikan' => '2026-07-01',
            ...$this->baseRowFields(),
        ]);

        $response = $this->kirim([
            $this->baris(['nomor_urut' => 3, 'tanggal_breakdown' => '2026-07-28']),
        ]);

        $response->assertSessionHasErrors('rows.0.nomor_urut');
        $this->assertSame(1, TransaksiBreakdownDies::count());
    }

    public function test_nomor_sama_dengan_data_lama_di_bulan_berbeda_diterima(): void
    {
        TransaksiBreakdownDies::create([
            'no_lkd' => '5/LKD/MTC/KMI/VII/26',
            'id_part_proses' => $this->partProsesId,
            'tanggal_breakdown' => '2026-07-01',
            'tanggal_mulai_perbaikan' => '2026-07-01',
            'tanggal_selesai_perbaikan' => '2026-07-01',
            ...$this->baseRowFields(),
        ]);

        $response = $this->kirim([
            $this->baris([
                'nomor_urut' => 5,
                'tanggal_breakdown' => '2026-08-05',
                'tanggal_mulai_perbaikan' => '2026-08-05',
                'tanggal_selesai_perbaikan' => '2026-08-05',
            ]),
        ]);

        $response->assertSessionDoesntHaveErrors();
        $this->assertSame(2, TransaksiBreakdownDies::count());
    }

    public function test_no_lkd_bentrok_dengan_baris_terhapus_juga_ditolak(): void
    {
        $b = TransaksiBreakdownDies::create([
            'no_lkd' => '7/LKD/MTC/KMI/VII/26',
            'id_part_proses' => $this->partProsesId,
            'tanggal_breakdown' => '2026-07-01',
            'tanggal_mulai_perbaikan' => '2026-07-01',
            'tanggal_selesai_perbaikan' => '2026-07-01',
            ...$this->baseRowFields(),
        ]);
        $b->delete();

        $response = $this->kirim([
            $this->baris(['nomor_urut' => 7, 'tanggal_breakdown' => '2026-07-15']),
        ]);

        $response->assertSessionHasErrors('rows.0.nomor_urut');
        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_no_lkd_kembar_antar_baris_dalam_satu_draft_ditolak(): void
    {
        $response = $this->kirim([
            $this->baris(['nomor_urut' => 9, 'tanggal_breakdown' => '2026-07-05']),
            $this->baris(['nomor_urut' => 9, 'tanggal_breakdown' => '2026-07-20']),
        ]);

        $response->assertSessionHasErrors('rows.1.nomor_urut');
        $response->assertSessionDoesntHaveErrors('rows.0.nomor_urut');
        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_nomor_sama_di_bulan_berbeda_diterima(): void
    {
        // Ini mencegah "perbaikan" naif yang membandingkan nomor_urut
        // telanjang: 5/VIII dan 5/IX adalah dua No LKD sah yang berbeda.
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
        $this->assertSame(2, TransaksiBreakdownDies::count());
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

        $b = TransaksiBreakdownDies::first();
        $this->assertSame('2.00', (string) $b->repair_time);
        $this->assertSame('3.00', (string) $b->total_downtime);
        $this->assertSame('Sedang', $b->status_breakdown);
    }

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        $response = $this->kirim([
            $this->baris(['nomor_urut' => 1]),
            $this->baris(['nomor_urut' => 2, 'id_part_proses' => 999999]),
        ]);

        $response->assertSessionHasErrors('rows.1.id_part_proses');
        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_gagal_di_tengah_transaksi_tidak_menyisakan_baris(): void
    {
        $this->app->instance(BreakdownDiesWriteService::class, new class(app(BreakdownDiesService::class)) extends BreakdownDiesWriteService
        {
            private int $panggilan = 0;

            public function simpan(array $baris): TransaksiBreakdownDies
            {
                $this->panggilan++;

                if ($this->panggilan >= 2) {
                    throw new BreakdownDiesRollbackTestException('baris kedua sengaja gagal untuk menguji rollback');
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
            $this->fail('Seharusnya melempar BreakdownDiesRollbackTestException dari baris kedua.');
        } catch (BreakdownDiesRollbackTestException $e) {
            $this->assertSame('baris kedua sengaja gagal untuk menguji rollback', $e->getMessage());
        }

        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_rows_kosong_ditolak(): void
    {
        $this->kirim([])->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_guest_tidak_bisa_menyimpan(): void
    {
        $guest = User::factory()->create(['role' => 'guest']);

        $this->actingAs($guest)->post(route('maintenance.breakdown-dies.batch'), ['rows' => [$this->baris()]])
            ->assertForbidden();

        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_rows_json_menyimpan_baris(): void
    {
        $this->kirimJson([$this->baris()])->assertRedirect(route('maintenance.breakdown-dies.index'));
        $this->assertSame(1, TransaksiBreakdownDies::count());
    }

    public function test_rows_json_rusak_ditolak_bukan_meledak(): void
    {
        $this->actingAs($this->admin)
            ->post(route('maintenance.breakdown-dies.batch'), ['rows_json' => '{bukan json'])
            ->assertSessionHasErrors('rows');

        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_rows_json_menampung_50_baris_batas_server(): void
    {
        $rows = [];
        for ($i = 1; $i <= 50; $i++) {
            $rows[] = $this->baris(['nomor_urut' => $i]);
        }

        $this->kirimJson($rows)->assertRedirect(route('maintenance.breakdown-dies.index'));
        $this->assertSame(50, TransaksiBreakdownDies::count());
    }

    public function test_lebih_dari_50_baris_ditolak(): void
    {
        $rows = [];
        for ($i = 1; $i <= 51; $i++) {
            $rows[] = $this->baris(['nomor_urut' => $i]);
        }

        $this->kirimJson($rows)->assertSessionHasErrors('rows');
        $this->assertSame(0, TransaksiBreakdownDies::count());
    }

    public function test_pesan_validasi_berbahasa_indonesia(): void
    {
        $this->kirim([$this->baris(['tanggal_breakdown' => '0026-07-28'])]);

        $pesan = session('errors')['default']['messages']['rows.0.tanggal_breakdown'][0] ?? '';

        $this->assertNotSame('', $pesan);
        $this->assertStringContainsString('Baris 1', $pesan);
        $this->assertStringContainsString('tidak masuk akal', $pesan);
    }

    public function test_tanggal_rusak_ditolak_bukan_meledak_500(): void
    {
        $response = $this->kirim([
            $this->baris(['tanggal_breakdown' => 'bukan-tanggal']),
        ]);

        $response->assertStatus(302);
        $response->assertSessionHasErrors('rows.0.tanggal_breakdown');
        $this->assertSame(0, TransaksiBreakdownDies::count());
    }
}
