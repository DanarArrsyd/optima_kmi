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
use App\Models\TransaksiQc;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * User laporan: note staff yang belum di-resolve gampang kelewat, tenggelam
 * di tengah ratusan baris lintas beberapa hari — nol ada cara ngeliat
 * "berapa yang belum gw tangani" tanpa scroll manual. Tombol toolbar
 * "Note" (sejajar Input Baru/Filter/anomali/Last Input) nunjukin badge
 * hitungan, klik menyaring tabel biar cuma baris ber-note terbuka yang
 * tampil.
 */
class NoteToolbarBadgeTest extends TestCase
{
    use RefreshDatabase;

    private array $ctx;

    protected function setUp(): void
    {
        parent::setUp();

        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $shift = MasterShift::create(['nama_shift' => 'Shift 1', 'jam_start' => '08:00', 'jam_finish' => '16:00']);
        $karyawan = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Operator N', 'departemen' => 'Produksi', 'is_aktif' => true,
        ]);
        $mesin = MasterMesin::create([
            'id_mesin' => 'N-01', 'nama_mesin' => 'Press N', 'line' => '1',
            'tipe_mesin' => 'PROGRESSIVE', 'kapasitas_max_stroke' => 100,
        ]);
        $part = MasterPart::create(['part_no' => 'N-P1', 'part_name' => 'Panel N', 'customer' => 'Toyota']);
        $proses = MasterPartProses::create([
            'id_part' => $part->id, 'id_proses' => 1, 'nama_proses' => 'PROGRESSIVE',
            'sph_standar_produksi' => 500, 'sph_standar_qc' => 250,
        ]);

        $this->ctx = compact('admin', 'staff', 'shift', 'karyawan', 'mesin', 'proses');
    }

    private function produksi(array $noteFields = []): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => now()->toDateString(),
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00',
            'jam_finish' => '16:00',
            'qty_aktual' => 100,
            'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
        ] + $noteFields);
    }

    private function openNote(): array
    {
        return [
            'staff_note' => 'Tolong dicek pak',
            'staff_note_at' => now(),
            'staff_note_by' => $this->ctx['staff']->id,
        ];
    }

    public function test_badge_menghitung_note_terbuka_bukan_yang_sudah_resolve(): void
    {
        $this->produksi($this->openNote());
        $this->produksi($this->openNote());
        $this->produksi($this->openNote() + [
            'staff_note_resolved_at' => now(),
            'staff_note_resolved_by' => $this->ctx['admin']->id,
        ]);
        $this->produksi(); // nol note sama sekali

        $html = $this->actingAs($this->ctx['admin'])
            ->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringContainsString('2', $html);
        $this->assertMatchesRegularExpression('/Note.{0,40}&bull;\s*2/s', $html);
    }

    public function test_toggle_only_open_notes_menyaring_hanya_baris_ber_note_terbuka(): void
    {
        $terbuka = $this->produksi($this->openNote());
        $bersih = $this->produksi();

        $response = $this->actingAs($this->ctx['admin'])
            ->get(route('input.produksi.index', ['only_open_notes' => 1]));

        $ids = $response->viewData('transaksi')->pluck('id');
        $this->assertTrue($ids->contains($terbuka->id));
        $this->assertFalse($ids->contains($bersih->id));
    }

    public function test_badge_menghitung_lintas_semua_halaman_bukan_cuma_halaman_ini(): void
    {
        // 35 baris ber-note terbuka > 30 per halaman, jadi kepotong ke halaman 2.
        for ($i = 0; $i < 35; $i++) {
            $this->produksi($this->openNote());
        }

        $html = $this->actingAs($this->ctx['admin'])
            ->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Note.{0,40}&bull;\s*35/s', $html);
    }

    public function test_non_admin_nol_lihat_tombol_note(): void
    {
        $this->produksi($this->openNote());

        $html = $this->actingAs($this->ctx['staff'])
            ->get(route('input.produksi.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('only_open_notes', $html);
    }

    public function test_qc_dan_dandori_punya_badge_yang_sama(): void
    {
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Inspector N', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        TransaksiQc::create([
            'tgl_pengecekan' => now()->toDateString(),
            'tgl_produksi_part' => now()->toDateString(),
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00', 'jam_finish' => '16:00',
            'qty_ok' => 100, 'qty_ng' => 0, 'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
        ] + $this->openNote());

        $loss = MasterLosstime::create([
            'kode' => 'LS-N', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        TransaksiDandoriRincian::create([
            'tanggal' => now()->toDateString(),
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 40, 'std_menit' => 15, 'over_menit' => 25,
            'created_by' => $this->ctx['admin']->id,
        ] + $this->openNote());

        $qcHtml = $this->actingAs($this->ctx['admin'])
            ->get(route('input.qc.index'))->assertOk()->getContent();
        $dandoriHtml = $this->actingAs($this->ctx['admin'])
            ->get(route('input.dandori.index'))->assertOk()->getContent();

        $this->assertMatchesRegularExpression('/Note.{0,40}&bull;\s*1/s', $qcHtml);
        $this->assertMatchesRegularExpression('/Note.{0,40}&bull;\s*1/s', $dandoriHtml);
    }
}
