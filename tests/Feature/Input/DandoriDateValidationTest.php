<?php

namespace Tests\Feature\Input;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Rule `date` menerima tahun 4-digit apa pun, termasuk salah ketik "0026" untuk
 * "2026" — dan MySQL menyimpannya diam-diam. Tiga baris produksi (id 24212–24214,
 * tanggal 0026-06-21) lolos lewat celah ini dan hilang dari semua laporan VCB yang
 * memfilter per rentang tanggal: tidak error, cuma tidak pernah ikut terhitung.
 *
 * Batas atas sengaja `tomorrow`, bukan `today`: app timezone = UTC sedangkan pabrik
 * di WIB (UTC+7), jadi tanggal "hari ini" versi operator bisa satu hari di depan UTC.
 */
class DandoriDateValidationTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator Tanggal', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'DT-01', 'nama_mesin' => 'Press DT', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'DT-P1', 'part_name' => 'Date Part', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-DT', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 30, 'std_menit_progressive' => 30,
        ]);

        $this->ctx = compact('admin', 'shift', 'karyawan', 'mesin', 'proses', 'loss');
    }

    private function baris(string $tanggal): array
    {
        return [
            'tanggal' => $tanggal,
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'rincian' => [
                ['id_losstime' => $this->ctx['loss']->id, 'actual_menit' => 40],
            ],
        ];
    }

    /** Jalur satu-satunya sekarang: halaman batch, satu baris di rows[]. */
    private function batchPayload(string $tanggal): array
    {
        return ['rows' => [$this->baris($tanggal)]];
    }

    private function existingRecord(): TransaksiDandoriRincian
    {
        return TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id, 'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id, 'id_losstime' => $this->ctx['loss']->id,
            'actual_menit' => 40, 'std_menit' => 30, 'over_menit' => 10,
            'created_by' => $this->ctx['admin']->id,
        ]);
    }

    public function test_batch_rejects_year_typo_that_produced_the_corrupt_rows(): void
    {
        $this->actingAs($this->ctx['admin'])
            ->post(route('input.dandori.batch'), $this->batchPayload('0026-06-21'))
            ->assertSessionHasErrors('rows.0.tanggal');

        $this->assertDatabaseCount('transaksi_dandori_rincian', 0);
    }

    public function test_update_rejects_year_typo(): void
    {
        $record = $this->existingRecord();

        $this->actingAs($this->ctx['admin'])
            ->put(route('input.dandori.update', $record), [
                'tanggal' => '0026-06-21',
                'id_shift' => $this->ctx['shift']->id,
                'id_karyawan' => $this->ctx['karyawan']->id,
                'id_mesin' => $this->ctx['mesin']->id,
                'id_part_proses' => $this->ctx['proses']->id,
                'id_losstime' => $this->ctx['loss']->id,
                'actual_menit' => 50,
            ])
            ->assertSessionHasErrors('tanggal');

        $this->assertSame('2026-07-05', $record->fresh()->tanggal->format('Y-m-d'));
    }

    public function test_batch_rejects_absurd_future_year(): void
    {
        $this->actingAs($this->ctx['admin'])
            ->post(route('input.dandori.batch'), $this->batchPayload('9999-01-01'))
            ->assertSessionHasErrors('rows.0.tanggal');
    }

    /** Batas tidak boleh terlalu ketat — input normal harus tetap lolos. */
    public function test_batch_still_accepts_a_normal_recent_date(): void
    {
        $this->actingAs($this->ctx['admin'])
            ->post(route('input.dandori.batch'), $this->batchPayload(now()->subDay()->format('Y-m-d')))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('transaksi_dandori_rincian', 1);
    }

    /** WIB (UTC+7) bisa satu hari di depan UTC — tanggal besok harus tetap diterima. */
    public function test_batch_accepts_tomorrow_to_absorb_wib_utc_skew(): void
    {
        $this->actingAs($this->ctx['admin'])
            ->post(route('input.dandori.batch'), $this->batchPayload(now()->addDay()->format('Y-m-d')))
            ->assertSessionHasNoErrors();
    }
}
