<?php

namespace Tests\Feature\Input;

use App\Models\MasterJenisNg;
use App\Models\MasterKaryawan;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiQc;
use App\Models\User;
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
        $this->kirim([$this->baris(), $this->baris(['qty_ok' => 10])])
            ->assertSessionHas('batch_tersimpan', 2);
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

    public function test_satu_baris_gagal_membatalkan_semuanya(): void
    {
        $response = $this->kirim([
            $this->baris(),
            $this->baris(['id_shift' => 999999]),
        ]);

        $response->assertSessionHasErrors('rows.1.id_shift');
        $this->assertSame(0, TransaksiQc::count());
    }

    public function test_qty_ok_nol_tanpa_ng_ditolak_per_baris(): void
    {
        // Aturan yang sama dengan StoreQcRequest: total qty check harus > 0.
        $this->kirim([$this->baris(['qty_ok' => 0, 'ng_count' => 0, 'ng' => []])])
            ->assertSessionHasErrors('rows.0.qty_ok');

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
        $this->kirim([$this->baris(['ng_count' => 2, 'ng' => [['id_jenis_ng' => $this->ref['dented'], 'qty' => 3]]])])
            ->assertSessionHasErrors('rows.0.ng_count');

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
            $rows[] = $this->baris(['qty_ok' => 10 + $i]);
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
        // "" lolos `nullable` tapi gagal `integer` dengan pesan membingungkan.
        $this->kirimJson([$this->baris(['ng' => [], 'ng_count' => 0])])
            ->assertRedirect(route('input.qc.index'));

        $this->assertSame(1, TransaksiQc::count());
    }
}
