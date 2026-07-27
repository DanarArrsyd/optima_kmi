<?php

namespace Tests\Feature;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App jalan di UTC (config/app.php) sementara pabrik di WIB (UTC+7), dan tak ada konversi
 * timezone di mana pun — jadi jam di dropdown notifikasi tampil 7 jam mundur dari kejadian
 * sebenarnya. Note yang dikirim 14:32 WIB muncul sebagai "07:32".
 *
 * Yang dikonversi hanya TAMPILAN. Kolom di DB tetap UTC: MySQL DATETIME tak menyimpan
 * timezone, jadi mengubah APP_TIMEZONE akan membuat setiap timestamp lama dibaca ulang
 * sebagai WIB — seluruh riwayat mundur 7 jam, diam-diam. Konversi saat render tak menyentuh
 * data sama sekali.
 *
 * `diffForHumans()` di halaman input tidak terpengaruh (now() dan created_at sama-sama UTC,
 * selisihnya benar), jadi sengaja tak diutak-atik.
 */
class NotificationTimezoneTest extends TestCase
{
    use RefreshDatabase;

    private function notifFor(User $user, string $utcCreatedAt): AppNotification
    {
        return AppNotification::create([
            'user_id' => $user->id,
            'actor_id' => User::factory()->create(['role' => 'staff_produksi'])->id,
            'type' => 'note_submitted',
            'notifiable_type' => 'produksi',
            'notifiable_id' => 1,
            'data' => ['nama_actor' => 'Gerrard Richardo', 'tanggal' => '2026-07-15'],
            'created_at' => $utcCreatedAt,
        ]);
    }

    public function test_poll_renders_timestamps_in_wib_not_utc(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // Kejadian nyata: note dikirim 14:32 WIB → tersimpan 07:32 UTC.
        $this->notifFor($user, '2026-07-15 07:32:00');

        $items = $this->actingAs($user)->getJson('/notifications/poll')->assertOk()->json('items');

        $this->assertSame('15 Jul 14:32', $items[0]['created_at']);
    }

    /** Konversi harus menggeser tanggal juga, bukan cuma jam. */
    public function test_late_night_utc_rolls_over_to_the_next_wib_day(): void
    {
        $user = User::factory()->create(['role' => 'admin']);

        // 2026-07-15 20:00 UTC = 2026-07-16 03:00 WIB.
        $this->notifFor($user, '2026-07-15 20:00:00');

        $items = $this->actingAs($user)->getJson('/notifications/poll')->assertOk()->json('items');

        $this->assertSame('16 Jul 03:00', $items[0]['created_at']);
    }

    /** Konversi tampilan tak boleh mengubah apa pun di DB. */
    public function test_stored_timestamp_stays_utc(): void
    {
        $user = User::factory()->create(['role' => 'admin']);
        $n = $this->notifFor($user, '2026-07-15 07:32:00');

        $this->actingAs($user)->getJson('/notifications/poll')->assertOk();

        $this->assertSame(
            '2026-07-15 07:32:00',
            AppNotification::find($n->id)->getRawOriginal('created_at'),
        );
    }
}
