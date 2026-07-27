<?php

namespace Tests\Unit;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $suffix = ''): User
    {
        return User::create([
            'name' => 'User'.$suffix,
            'email' => 'user'.$suffix.'@test.com',
            'password' => bcrypt('password'),
            'role' => 'staff_produksi',
        ]);
    }

    private function makeNotif(int $userId, int $actorId, ?string $readAt = null): AppNotification
    {
        return AppNotification::create([
            'type' => 'breakdown_created',
            'user_id' => $userId,
            'actor_id' => $actorId,
            'notifiable_type' => 'breakdown_mesin',
            'notifiable_id' => 1,
            'data' => ['message' => 'Test breakdown'],
            'read_at' => $readAt,
        ]);
    }

    public function test_can_create_notification(): void
    {
        $user = $this->makeUser('a');
        $actor = $this->makeUser('b');

        $notif = $this->makeNotif($user->id, $actor->id);

        $this->assertDatabaseHas('app_notifications', [
            'type' => 'breakdown_created',
            'user_id' => $user->id,
        ]);
        $this->assertIsArray($notif->data);
        $this->assertEquals('Test breakdown', $notif->data['message']);
    }

    public function test_scope_unread_excludes_read_notifications(): void
    {
        $user = $this->makeUser('c');
        $actor = $this->makeUser('d');

        $this->makeNotif($user->id, $actor->id);                        // unread
        $this->makeNotif($user->id, $actor->id, now()->toDateTimeString()); // read

        $unread = AppNotification::forUser($user->id)->unread()->get();
        $this->assertCount(1, $unread);
        $this->assertNull($unread->first()->read_at);
    }

    public function test_scope_for_user_filters_by_user_id(): void
    {
        $user1 = $this->makeUser('e');
        $user2 = $this->makeUser('f');
        $actor = $this->makeUser('g');

        $this->makeNotif($user1->id, $actor->id);
        $this->makeNotif($user2->id, $actor->id);

        $forUser1 = AppNotification::forUser($user1->id)->get();
        $this->assertCount(1, $forUser1);
        $this->assertEquals($user1->id, $forUser1->first()->user_id);
    }

    public function test_actor_and_recipient_relations(): void
    {
        $user = $this->makeUser('h');
        $actor = $this->makeUser('i');

        $notif = $this->makeNotif($user->id, $actor->id);
        $fresh = AppNotification::with(['actor', 'recipient'])->find($notif->id);

        $this->assertEquals($actor->id, $fresh->actor->id);
        $this->assertEquals($user->id, $fresh->recipient->id);
    }
}
