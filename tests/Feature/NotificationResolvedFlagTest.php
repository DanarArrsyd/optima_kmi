<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\MasterKaryawan;
use App\Models\MasterMesin;
use App\Models\MasterPart;
use App\Models\MasterPartProses;
use App\Models\MasterShift;
use App\Models\TransaksiProduksi;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item note_submitted di bell harus tetap "kuning" (belum selesai) sampai admin BENERAN
 * resolve note-nya (staff_note_resolved_at keisi lewat checkbox resolve_note di form edit),
 * bukan cuma sampai admin klik/buka item-nya di dropdown (read_at). Dua sinyal itu sengaja
 * dipisah — lihat spec 2026-08-31-bell-notif-resolve-warna-design.md.
 */
class NotificationResolvedFlagTest extends TestCase
{
    use RefreshDatabase;

    private function record(array $noteFields = []): TransaksiProduksi
    {
        $admin = User::factory()->create(['role' => 'admin']);
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

        return TransaksiProduksi::create([
            'tanggal_produksi' => '2026-07-10',
            'id_shift' => $shift->id,
            'id_karyawan' => $karyawan->id,
            'id_mesin' => $mesin->id,
            'id_part_proses' => $proses->id,
            'jam_start' => '08:00',
            'jam_finish' => '16:00',
            'qty_aktual' => 100,
            'actual_cycle_time' => 10,
            'created_by' => $admin->id,
        ] + $noteFields);
    }

    private function notifFor(User $admin, TransaksiProduksi $r, string $type = 'note_submitted', ?string $readAt = null): AppNotification
    {
        return AppNotification::create([
            'user_id' => $admin->id,
            'actor_id' => User::factory()->create(['role' => 'staff_produksi'])->id,
            'type' => $type,
            'notifiable_type' => 'produksi',
            'notifiable_id' => $r->id,
            'data' => ['nama_actor' => 'Staff N', 'tanggal' => '2026-07-10'],
            'created_at' => now(),
            'read_at' => $readAt,
        ]);
    }

    public function test_unresolved_note_reports_resolved_false(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $r = $this->record([
            'staff_note' => 'Tolong dicek', 'staff_note_at' => now(), 'staff_note_by' => $admin->id,
        ]);
        $this->notifFor($admin, $r);

        $items = $this->actingAs($admin)->getJson('/notifications/poll')->assertOk()->json('items');

        $this->assertFalse($items[0]['resolved']);
    }

    /** Inti perbaikan: klik (read_at terisi) TIDAK BOLEH ikut menandai resolved. */
    public function test_clicked_but_unresolved_note_still_reports_resolved_false(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $r = $this->record([
            'staff_note' => 'Tolong dicek', 'staff_note_at' => now(), 'staff_note_by' => $admin->id,
        ]);
        $this->notifFor($admin, $r, readAt: now());

        $items = $this->actingAs($admin)->getJson('/notifications/poll')->assertOk()->json('items');

        $this->assertNotNull($items[0]['read_at'], 'Setup salah: read_at harusnya terisi.');
        $this->assertFalse($items[0]['resolved'], 'read_at terisi tidak boleh ikut menandai resolved.');
    }

    public function test_resolved_note_reports_resolved_true_even_when_unread(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $r = $this->record([
            'staff_note' => 'Tolong dicek', 'staff_note_at' => now(), 'staff_note_by' => $admin->id,
            'staff_note_resolved_at' => now(), 'staff_note_resolved_by' => $admin->id,
        ]);
        $this->notifFor($admin, $r);

        $items = $this->actingAs($admin)->getJson('/notifications/poll')->assertOk()->json('items');

        $this->assertNull($items[0]['read_at'], 'Setup salah: read_at harusnya masih kosong.');
        $this->assertTrue($items[0]['resolved']);
    }

    /** note_resolved (balik ke staff) tidak punya konsep resolve lanjutan — field-nya null. */
    public function test_note_resolved_type_has_null_resolved_flag(): void
    {
        $staff = User::factory()->create(['role' => 'staff_produksi']);
        $r = $this->record();
        $this->notifFor($staff, $r, type: 'note_resolved');

        $items = $this->actingAs($staff)->getJson('/notifications/poll')->assertOk()->json('items');

        $this->assertNull($items[0]['resolved']);
    }
}
