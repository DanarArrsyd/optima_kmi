<?php

namespace App\Services;

use App\Models\AppNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class NotificationService
{
    public function insertNoteSubmitted(object $record, string $notifiableType, string $tanggal): void
    {
        if (empty($record->staff_note)) {
            return;
        }

        $actor = Auth::user();
        $admins = User::where('role', 'admin')->pluck('id');
        $data = [
            'tanggal' => $tanggal,
            'nama_actor' => $actor->name,
            'preview_note' => mb_substr($record->staff_note, 0, 80),
        ];

        foreach ($admins as $adminId) {
            AppNotification::create([
                'type' => 'note_submitted',
                'user_id' => $adminId,
                'actor_id' => $actor->id,
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $record->id,
                'data' => $data,
                'created_at' => now(),
            ]);
        }
    }

    public function insertNoteResolved(object $record, string $notifiableType, string $tanggal): void
    {
        if (! $record->staff_note_by) {
            return;
        }

        $actor = Auth::user();

        AppNotification::create([
            'type' => 'note_resolved',
            'user_id' => $record->staff_note_by,
            'actor_id' => $actor->id,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $record->id,
            'data' => [
                'tanggal' => $tanggal,
                'nama_actor' => $actor->name,
            ],
            'created_at' => now(),
        ]);
    }
}
