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
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Dulu admin harus dua kali jalan: buka halaman edit → perbaiki → simpan → balik ke index →
 * cari note-nya → klik "Tandai Selesai". Checkbox di form edit menyatukannya jadi satu simpan.
 *
 * Checkbox, bukan otomatis penuh. "Admin mengedit baris ini" tidak sama dengan "admin
 * mengerjakan yang diminta note": admin bisa mengedit untuk alasan lain, atau baru selesai
 * separuh. Kalau resolve ikut otomatis, staff menerima notifikasi "selesai" untuk sesuatu
 * yang belum tentu disentuh, dan sinyalnya jadi tak bisa dipercaya. Default tercentang
 * (kasus yang paling umum), tapi admin bisa membatalkannya.
 */
class NoteAutoResolveTest extends TestCase
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

    private function record(array $noteFields = []): TransaksiProduksi
    {
        return TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-10',
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

    private function withOpenNote(): TransaksiProduksi
    {
        return $this->record([
            'staff_note' => 'Qty aktual kayaknya kelebihan, tolong dicek pak',
            'staff_note_at' => now(),
            'staff_note_by' => $this->ctx['staff']->id,
        ]);
    }

    private function payload(array $extra = []): array
    {
        return [
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00',
            'jam_finish' => '16:00',
            'qty_aktual' => 90,
        ] + $extra;
    }

    private function update(TransaksiProduksi $r, array $extra = []): TestResponse
    {
        return $this->actingAs($this->ctx['admin'])
            ->put(route('input.produksi.update', $r), $this->payload($extra));
    }

    public function test_checked_box_resolves_the_note_in_the_same_save(): void
    {
        $r = $this->withOpenNote();

        $this->update($r, ['resolve_note' => 1])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertNotNull($r->staff_note_resolved_at, 'Note tidak ikut ter-resolve saat simpan.');
        $this->assertSame($this->ctx['admin']->id, $r->staff_note_resolved_by);
        $this->assertSame(90, $r->qty_aktual, 'Edit-nya sendiri harus tetap tersimpan.');
    }

    public function test_resolving_notifies_the_staff_who_wrote_the_note(): void
    {
        $r = $this->withOpenNote();

        $this->update($r, ['resolve_note' => 1]);

        $this->assertDatabaseHas('app_notifications', [
            'type' => 'note_resolved',
            'user_id' => $this->ctx['staff']->id,
            'notifiable_type' => 'produksi',
            'notifiable_id' => $r->id,
        ]);
    }

    /** Inti dari memilih checkbox, bukan otomatis penuh: admin bisa menolak. */
    public function test_unchecked_box_leaves_the_note_open(): void
    {
        $r = $this->withOpenNote();

        $this->update($r)->assertSessionHasNoErrors();   // tanpa resolve_note

        $r->refresh();
        $this->assertNull($r->staff_note_resolved_at, 'Note ter-resolve padahal checkbox tidak dicentang.');
        $this->assertSame(90, $r->qty_aktual);
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_editing_a_record_without_a_note_is_unaffected(): void
    {
        $r = $this->record();

        $this->update($r, ['resolve_note' => 1])->assertSessionHasNoErrors();

        $r->refresh();
        $this->assertNull($r->staff_note_resolved_at);
        $this->assertDatabaseCount('app_notifications', 0);
    }

    /** Note yang sudah selesai tak boleh di-stempel ulang — penyelesai & waktunya asli dipertahankan. */
    public function test_already_resolved_note_is_not_restamped_or_renotified(): void
    {
        $other = User::factory()->create(['role' => 'admin']);
        $r = $this->record([
            'staff_note' => 'Sudah ditangani',
            'staff_note_at' => now()->subDay(),
            'staff_note_by' => $this->ctx['staff']->id,
            'staff_note_resolved_at' => '2026-07-11 03:00:00',
            'staff_note_resolved_by' => $other->id,
        ]);

        $this->update($r, ['resolve_note' => 1]);

        $r->refresh();
        $this->assertSame($other->id, $r->staff_note_resolved_by, 'Penyelesai asli ketimpa.');
        $this->assertSame('2026-07-11 03:00:00', $r->staff_note_resolved_at->format('Y-m-d H:i:s'));
        $this->assertDatabaseCount('app_notifications', 0);
    }

    public function test_edit_page_shows_the_open_note_and_a_prechecked_box(): void
    {
        $r = $this->withOpenNote();

        $html = $this->actingAs($this->ctx['admin'])
            ->get(route('input.produksi.edit', $r))->assertOk()->getContent();

        $this->assertStringContainsString('Qty aktual kayaknya kelebihan', $html, 'Isi note tidak ditampilkan di form edit.');
        $this->assertStringContainsString('resolve_note', $html);
        $this->assertStringContainsString('checked', $html, 'Checkbox harus tercentang secara default.');
    }

    public function test_edit_page_has_no_box_when_there_is_no_open_note(): void
    {
        $html = $this->actingAs($this->ctx['admin'])
            ->get(route('input.produksi.edit', $this->record()))->assertOk()->getContent();

        $this->assertStringNotContainsString('resolve_note', $html);
    }

    /* ── QC & Dandori: trait yang sama, tapi update()-nya beda bentuk ─────────
     |  Produksi & QC pakai FormRequest, Dandori validasi inline dengan Request
     |  polos. Diuji terpisah supaya "berlaku di tiga modul" itu terbukti, bukan
     |  diasumsikan dari satu modul yang lewat.
     */

    public function test_qc_update_resolves_note_when_box_checked(): void
    {
        $inspector = MasterKaryawan::create([
            'nik' => uniqid(), 'nama_karyawan' => 'Inspector N', 'departemen' => 'QC', 'is_aktif' => true,
        ]);
        $qc = TransaksiQc::create([
            'tgl_pengecekan' => '2026-07-10',
            'tgl_produksi_part' => '2026-07-09',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00',
            'jam_finish' => '16:00',
            'qty_ok' => 100,
            'qty_ng' => 0,
            'actual_cycle_time' => 10,
            'created_by' => $this->ctx['admin']->id,
            'staff_note' => 'Tolong dicek qty NG-nya',
            'staff_note_at' => now(),
            'staff_note_by' => $this->ctx['staff']->id,
        ]);

        $this->actingAs($this->ctx['admin'])->put(route('input.qc.update', $qc), [
            'tgl_pengecekan' => '2026-07-10',
            'tgl_produksi_part' => '2026-07-09',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan_qc' => $inspector->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'jam_start' => '08:00',
            'jam_finish' => '16:00',
            'qty_ok' => 95,
            'qty_ng' => 0,
            'ng_count' => 0,
            'resolve_note' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($qc->fresh()->staff_note_resolved_at);
        $this->assertDatabaseHas('app_notifications', ['type' => 'note_resolved', 'notifiable_type' => 'qc']);
    }

    public function test_dandori_update_resolves_note_when_box_checked(): void
    {
        $loss = MasterLosstime::create([
            'kode' => 'LS-N', 'jenis_losstime' => 'Setting Dies',
            'std_menit_default' => 10, 'std_menit_progressive' => 15,
        ]);
        $d = TransaksiDandoriRincian::create([
            'tanggal' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 40, 'std_menit' => 15, 'over_menit' => 25,
            'created_by' => $this->ctx['admin']->id,
            'staff_note' => 'Menit-nya kelamaan, tolong dicek',
            'staff_note_at' => now(),
            'staff_note_by' => $this->ctx['staff']->id,
        ]);

        $this->actingAs($this->ctx['admin'])->put(route('input.dandori.update', $d), [
            'tanggal' => '2026-07-10',
            'id_shift' => $this->ctx['shift']->id,
            'id_karyawan' => $this->ctx['karyawan']->id,
            'id_mesin' => $this->ctx['mesin']->id,
            'id_part_proses' => $this->ctx['proses']->id,
            'id_losstime' => $loss->id,
            'actual_menit' => 30,
            'resolve_note' => 1,
        ])->assertSessionHasNoErrors();

        $this->assertNotNull($d->fresh()->staff_note_resolved_at);
        $this->assertDatabaseHas('app_notifications', ['type' => 'note_resolved', 'notifiable_type' => 'dandori']);
    }
}
