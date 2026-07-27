<?php

namespace App\Http\Controllers;

use App\Models\AppNotification;
use Illuminate\Http\JsonResponse;

class NotificationController extends Controller
{
    /**
     * App jalan di UTC (config/app.php) sementara pabrik di WIB, jadi timestamp mentah
     * tampil 7 jam mundur — note yang dikirim 14:32 muncul sebagai "07:32".
     *
     * Konversi ditaruh di TAMPILAN, bukan di APP_TIMEZONE. MySQL DATETIME tak menyimpan
     * timezone: mengubah zona app akan membuat setiap timestamp lama (yang isinya UTC)
     * dibaca ulang sebagai WIB, jadi seluruh riwayat mundur 7 jam tanpa error apa pun.
     * Kolom DB tetap UTC; yang bergeser cuma yang dilihat orang.
     */
    private const DISPLAY_TZ = 'Asia/Jakarta';

    public function poll(): JsonResponse
    {
        $userId = auth()->id();

        $items = AppNotification::forUser($userId)
            ->orderByDesc('created_at')
            ->limit(15)
            ->get()
            ->map(fn ($n) => [
                'id' => $n->id,
                'type' => $n->type,
                'text' => $this->buildText($n),
                'url' => $this->buildUrl($n),
                'created_at' => $n->created_at->timezone(self::DISPLAY_TZ)->format('d M H:i'),
                'read_at' => $n->read_at,
            ]);

        $count = AppNotification::forUser($userId)->unread()->count();

        return response()->json(['count' => $count, 'items' => $items]);
    }

    public function markRead(int $id): JsonResponse
    {
        AppNotification::where('id', $id)
            ->where('user_id', auth()->id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    public function markAllRead(): JsonResponse
    {
        AppNotification::forUser(auth()->id())
            ->unread()
            ->update(['read_at' => now()]);

        return response()->json(['ok' => true]);
    }

    private function buildText(AppNotification $n): string
    {
        $map = ['produksi' => 'LHP Produksi', 'qc' => 'LHP Quality', 'dandori' => 'Dandori'];
        $label = $map[$n->notifiable_type] ?? ucfirst($n->notifiable_type);

        return match ($n->type) {
            'note_submitted' => ($n->data['nama_actor'] ?? 'Staff').' mengirim note di '.$label,
            'note_resolved' => 'Note kamu di '.$label.' sudah di-feedback admin',
            default => 'Notifikasi baru',
        };
    }

    private function buildUrl(AppNotification $n): string
    {
        $paths = [
            'produksi' => '/input/produksi',
            'qc' => '/input/qc',
            'dandori' => '/input/dandori',
        ];
        $path = $paths[$n->notifiable_type] ?? '/';
        $tgl = $n->data['tanggal'] ?? null;

        $base = $tgl ? $path.'?date_from='.$tgl.'&date_to='.$tgl : $path;

        return $base.($tgl ? '&' : '?').'highlight_id='.$n->notifiable_id;
    }
}
