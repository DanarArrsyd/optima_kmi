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
 * User laporan: field Part di Edit Dandori masih <select> native — dengan
 * ratusan part di database, itu jadi dropdown OS mentah yang nol bisa
 * dicari, cuma bisa discroll manual. Diganti typeahead ketik-cari
 * (partGroupComp, komponen bersama yang sama dipakai dock filter index
 * & halaman lain), sama seperti pola "INPUT LHP atau DANDORI yang lainnya".
 */
class DandoriEditPartTypeaheadTest extends TestCase
{
    use RefreshDatabase;

    public function test_halaman_edit_pakai_typeahead_bukan_select_native(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'TA-01', 'nama_mesin' => 'Press TA', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'TA-P1', 'part_name' => 'PANEL TYPEAHEAD', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-TA', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 15, 'std_menit_progressive' => 15,
        ]);
        $dandori = TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $proses->id, 'id_losstime' => $loss->id,
            'actual_menit' => 20, 'std_menit' => 15, 'over_menit' => 5, 'created_by' => $admin->id,
        ]);

        $html = $this->actingAs($admin)
            ->get(route('input.dandori.edit', $dandori))
            ->assertOk()->getContent();

        // Select native lama sudah tidak ada.
        $this->assertStringNotContainsString('id="editPartSelect"', $html);
        $this->assertStringNotContainsString('id="editProsesSelect"', $html);
        $this->assertStringNotContainsString('onchange="loadProses', $html);

        // Komponen typeahead bersama terpasang, prefill part & proses saat ini benar.
        $this->assertStringContainsString('partGroupComp(', $html);
        $this->assertStringContainsString('TA-P1 — PANEL TYPEAHEAD', $html);
        $this->assertStringContainsString('1 — PROGRESSIVE', $html);
        $this->assertStringContainsString('name="id_part_proses"', $html);
    }

    public function test_submit_dengan_id_part_proses_dari_typeahead_tetap_tersimpan(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'TA-02', 'nama_mesin' => 'Press TA2', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'TA-P2', 'part_name' => 'PANEL LAMA']);
        $prosesLama = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);
        $partBaru = MasterPart::create(['part_no' => 'TA-P3', 'part_name' => 'PANEL BARU']);
        $prosesBaru = MasterPartProses::create([
            'id_part' => $partBaru->id, 'id_proses' => 1, 'nama_proses' => 'BENDING',
            'sph_standar_produksi' => 400, 'sph_standar_qc' => 200,
        ]);
        $loss = MasterLosstime::create([
            'kode' => 'LS-TB', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 15, 'std_menit_progressive' => 15,
        ]);
        $dandori = TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-05', 'id_shift' => $shift->id, 'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id, 'id_part_proses' => $prosesLama->id, 'id_losstime' => $loss->id,
            'actual_menit' => 20, 'std_menit' => 15, 'over_menit' => 5, 'created_by' => $admin->id,
        ]);

        // Ganti part lewat hidden field id_part_proses — persis nilai yang
        // diisi Alpine (prosesVal) saat user pilih dari dropdown typeahead.
        $response = $this->actingAs($admin)->put(route('input.dandori.update', $dandori), [
            'tanggal' => '2026-07-05',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $prosesBaru->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 20,
        ]);

        $response->assertSessionHasNoErrors();
        $this->assertSame($prosesBaru->id, $dandori->fresh()->id_part_proses);
    }
}
