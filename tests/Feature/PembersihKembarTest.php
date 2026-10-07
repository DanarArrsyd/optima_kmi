<?php

namespace Tests\Feature;

use App\Models\MasterKaryawan;
use App\Models\MasterLosstime;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiDandoriRincian;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** optima:bersih-kembar — pembersihan baris kembar lama (PembersihKembar). */
class PembersihKembarTest extends TestCase
{
    use RefreshDatabase;

    private array $ref;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role' => 'admin']));
        $part = MasterPart::create(['part_no' => 'K-1', 'part_name' => 'PART']);
        $this->ref = [
            'id_shift' => MasterShift::create(['nama_shift' => 'S1', 'jam_start' => '07:00', 'jam_finish' => '15:00'])->id,
            'id_karyawan' => MasterKaryawan::create(['nik' => 'K1', 'nama_karyawan' => 'Budi', 'departemen' => 'Produksi', 'is_aktif' => true])->id,
            'id_mesin' => MasterMesin::create(['id_mesin' => 'A9', 'nama_mesin' => 'M', 'line' => 'A', 'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100])->id,
            'id_part_proses' => MasterPartProses::create(['id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'BLANK', 'sph_standar_produksi' => 500])->id,
        ];
        $this->ref['lt'] = MasterLosstime::create(['kode' => 'LT1', 'jenis_losstime' => 'Ganti', 'std_menit_default' => 20])->id;
    }

    private function lhp(string $dibuat, array $ubah = []): TransaksiProduksi
    {
        $r = TransaksiProduksi::create([
            'tanggal_produksi' => '2026-09-01', 'jam_start' => '07:00', 'jam_finish' => '09:00', 'qty_aktual' => 500,
            'actual_cycle_time' => 1, 'created_by' => auth()->id(),
            ...array_diff_key($this->ref, ['lt' => 1]), ...$ubah,
        ]);
        DB::table('transaksi_produksi')->where('id', $r->id)->update(['created_at' => $dibuat]);

        return $r;
    }

    private function dandori(string $dibuat, float $menit = 10): TransaksiDandoriRincian
    {
        $r = TransaksiDandoriRincian::create([
            'tanggal' => '2026-09-01', 'id_losstime' => $this->ref['lt'], 'actual_menit' => $menit, 'std_menit' => 20, 'over_menit' => 0,
            'created_by' => auth()->id(), ...array_diff_key($this->ref, ['lt' => 1]),
        ]);
        DB::table('transaksi_dandori_rincian')->where('id', $r->id)->update(['created_at' => $dibuat]);

        return $r;
    }

    public function test_dry_run_tidak_menulis_apa_pun(): void
    {
        $this->lhp('2026-09-01 03:00:00');
        $this->lhp('2026-09-01 05:00:00');

        $this->artisan('optima:bersih-kembar')->expectsOutputToContain('DRY-RUN')->assertSuccessful();

        $this->assertSame(2, TransaksiProduksi::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_terapkan_menyimpan_input_pertama_lalu_pulihkan_mengembalikan(): void
    {
        $asli = $this->lhp('2026-09-01 03:00:00');
        $dobel = $this->lhp('2026-09-01 05:00:00');
        $lain = $this->lhp('2026-09-01 05:00:00', ['jam_start' => '09:00', 'jam_finish' => '10:00']);

        $this->artisan('optima:bersih-kembar --terapkan')->assertSuccessful();

        $this->assertEqualsCanonicalizing([$asli->id, $lain->id], TransaksiProduksi::pluck('id')->all());
        $this->assertSoftDeleted($dobel);
        $berkas = Storage::disk('local')->allFiles('bersih-kembar')[0];
        $this->assertSame([$dobel->id], json_decode(Storage::disk('local')->get($berkas), true)['produksi']);

        $this->artisan('optima:bersih-kembar', ['--pulihkan' => $berkas])->expectsOutputToContain('Dipulihkan: 1 baris')->assertSuccessful();
        $this->assertSame(3, TransaksiProduksi::count());
    }

    public function test_simpan_paksa_menentukan_baris_yang_bertahan(): void
    {
        $asli = $this->lhp('2026-09-01 03:00:00');
        $pilihan = $this->lhp('2026-09-01 05:00:00');

        $this->artisan('optima:bersih-kembar', ['--simpan' => ["produksi:{$pilihan->id}"], '--terapkan' => true])->assertSuccessful();

        $this->assertSame([$pilihan->id], TransaksiProduksi::pluck('id')->all());
        $this->assertSoftDeleted($asli);
    }

    public function test_baris_ber_staff_note_tidak_pernah_dihapus(): void
    {
        $this->lhp('2026-09-01 03:00:00');
        $this->lhp('2026-09-01 05:00:00', ['staff_note' => 'qty dikoreksi']);

        $this->artisan('optima:bersih-kembar --terapkan')->expectsOutputToContain('punya staff note')->assertSuccessful();

        $this->assertSame(2, TransaksiProduksi::count());
    }

    public function test_dandori_sedetik_dengan_lhp_tunggal_dianggap_ragu(): void
    {
        $this->lhp('2026-09-01 03:00:00');
        $this->dandori('2026-09-01 03:00:00');
        $this->dandori('2026-09-01 03:00:00');

        $this->artisan('optima:bersih-kembar --terapkan')->expectsOutputToContain('LHP-nya tidak dobel')->assertSuccessful();

        $this->assertSame(2, TransaksiDandoriRincian::count(), 'dua rincian sah dalam satu sesi LHP ikut terhapus');
    }

    public function test_dandori_sedetik_ikut_lhp_yang_dobel_dihapus(): void
    {
        $this->lhp('2026-09-01 03:00:00');
        $this->lhp('2026-09-01 03:00:00');
        $this->dandori('2026-09-01 03:00:00');
        $this->dandori('2026-09-01 03:00:00');

        $this->artisan('optima:bersih-kembar --terapkan')->assertSuccessful();

        $this->assertSame(1, TransaksiProduksi::count());
        $this->assertSame(1, TransaksiDandoriRincian::count());
    }

    /** Dua LHP sah (jam beda) di batch yang sama, masing-masing centang Ada Dandori. */
    public function test_dandori_dari_dua_lhp_jam_berbeda_tidak_dihapus(): void
    {
        $this->lhp('2026-09-01 03:00:00');
        $this->lhp('2026-09-01 03:00:00', ['jam_start' => '09:00', 'jam_finish' => '11:00']);
        $this->dandori('2026-09-01 03:00:00');
        $this->dandori('2026-09-01 03:00:00');

        $this->artisan('optima:bersih-kembar --terapkan')->expectsOutputToContain('LHP-nya tidak dobel')->assertSuccessful();

        $this->assertSame(2, TransaksiDandoriRincian::count());
    }

    public function test_dandori_form_sesi_rangkap_utuh_dihapus_dan_beda_waktu_dihapus(): void
    {
        // Form Dandori dikirim dua kali di detik yang sama: dua rincian, masing-masing rangkap.
        $this->dandori('2026-09-01 03:00:00', 10);
        $this->dandori('2026-09-01 03:00:00', 15);
        $this->dandori('2026-09-01 03:00:00', 10);
        $this->dandori('2026-09-01 03:00:00', 15);
        // Dikirim ulang 2 jam kemudian.
        $this->dandori('2026-09-01 05:00:00', 10);

        $this->artisan('optima:bersih-kembar --terapkan')->assertSuccessful();

        $this->assertEqualsCanonicalizing(['10.00', '15.00'], TransaksiDandoriRincian::pluck('actual_menit')->map(fn ($m) => number_format((float) $m, 2))->all());
    }
}
