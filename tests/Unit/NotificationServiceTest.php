<?php

namespace Tests\Unit;

use App\Models\AppNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class NotificationServiceTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $role, string $name = 'Test User'): User
    {
        return User::create([
            'name' => $name,
            'email' => uniqid().'@test.com',
            'password' => bcrypt('password'),
            'role' => $role,
        ]);
    }

    private function fakeRecord(int $id, string $note, int $noteBy): object
    {
        return (object) [
            'id' => $id,
            'staff_note' => $note,
            'staff_note_by' => $noteBy,
        ];
    }

    public function test_insert_note_submitted_creates_notif_for_each_admin(): void
    {
        $staff = $this->makeUser('staff_produksi', 'Budi');
        $admin1 = $this->makeUser('admin', 'Admin Satu');
        $admin2 = $this->makeUser('admin', 'Admin Dua');
        $this->makeUser('manager'); // tidak dapat notif

        Auth::login($staff);

        $record = $this->fakeRecord(99, 'ada masalah', $staff->id);
        (new NotificationService)->insertNoteSubmitted($record, 'produksi', '2026-06-27');

        $this->assertSame(2, AppNotification::count());
        $this->assertTrue(AppNotification::where('user_id', $admin1->id)->exists());
        $this->assertTrue(AppNotification::where('user_id', $admin2->id)->exists());

        $notif = AppNotification::first();
        $this->assertSame('note_submitted', $notif->type);
        $this->assertSame('produksi', $notif->notifiable_type);
        $this->assertSame(99, $notif->notifiable_id);
        $this->assertSame($staff->id, $notif->actor_id);
        $this->assertSame('2026-06-27', $notif->data['tanggal']);
        $this->assertSame('Budi', $notif->data['nama_actor']);
        $this->assertSame('ada masalah', $notif->data['preview_note']);
        $this->assertNull($notif->read_at);
    }

    public function test_insert_note_submitted_skipped_when_note_empty(): void
    {
        $staff = $this->makeUser('staff_produksi');
        $this->makeUser('admin');
        Auth::login($staff);

        $record = $this->fakeRecord(1, '', $staff->id);
        (new NotificationService)->insertNoteSubmitted($record, 'produksi', '2026-06-27');

        $this->assertSame(0, AppNotification::count());
    }

    public function test_insert_note_resolved_creates_notif_for_note_author(): void
    {
        $staff = $this->makeUser('staff_produksi', 'Siti');
        $admin = $this->makeUser('admin', 'Admin');
        Auth::login($admin);

        $record = $this->fakeRecord(55, 'masalah', $staff->id);
        (new NotificationService)->insertNoteResolved($record, 'qc', '2026-06-26');

        $this->assertSame(1, AppNotification::count());
        $notif = AppNotification::first();
        $this->assertSame('note_resolved', $notif->type);
        $this->assertSame('qc', $notif->notifiable_type);
        $this->assertSame(55, $notif->notifiable_id);
        $this->assertSame($staff->id, $notif->user_id);
        $this->assertSame($admin->id, $notif->actor_id);
        $this->assertSame('2026-06-26', $notif->data['tanggal']);
        $this->assertSame('Admin', $notif->data['nama_actor']);
    }
}
