<?php

namespace App\Http\Controllers\Sistem;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Activity;

class LogAktivitasController extends Controller
{
    /** Label event dalam Bahasa Indonesia — satu-satunya tempat, dipakai dropdown filter dan tabel. */
    public const EVENT_LABELS = [
        'created' => 'Ditambah',
        'updated' => 'Diubah',
        'deleted' => 'Dihapus',
        'login' => 'Login',
        'logout' => 'Logout',
        'failed_login' => 'Login Gagal',
    ];

    /** Label modul dari log_name — satu-satunya tempat, dipakai dropdown filter dan tabel. */
    public const MODUL_LABELS = [
        'users' => 'Users',
        'master_karyawan' => 'Master Karyawan',
        'master_mesin' => 'Master Mesin',
        'master_part' => 'Master Part',
        'master_part_proses' => 'Master Part Proses',
        'master_shift' => 'Master Shift',
        'master_losstime' => 'Master Loss Time',
        'master_jenis_ng' => 'Master Jenis NG',
        'auth' => 'Autentikasi',
    ];

    public function index(Request $request)
    {
        $dateFrom = $request->input('date_from', now()->subDays(7)->toDateString());
        $dateTo = $request->input('date_to', now()->toDateString());
        $modul = $request->input('modul', '');
        $event = $request->input('event', '');
        $userId = $request->input('user_id', '');
        $search = trim((string) $request->input('search', ''));

        /* created_at tersimpan UTC, filter tanggal dari user itu WIB (UTC+7) —
         * whereDate polos di kolom UTC menggeser batas hari sampai 7 jam.
         * Konversi rentang WIB ke UTC dulu di sini. */
        $rentangUtcFrom = Carbon::parse($dateFrom, 'Asia/Jakarta')->startOfDay()->utc();
        $rentangUtcTo = Carbon::parse($dateTo, 'Asia/Jakarta')->endOfDay()->utc();

        $query = Activity::query()
            ->with('causer')
            ->whereBetween('created_at', [$rentangUtcFrom, $rentangUtcTo])
            ->when($modul !== '', fn ($q) => $q->where('log_name', $modul))
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($userId !== '', fn ($q) => $q->where('causer_id', $userId)->where('causer_type', User::class))
            ->when($search !== '', fn ($q) => $q->where('description', 'like', "%{$search}%"))
            ->latest('id');

        $logs = $query->paginate(50)->withQueryString();

        return view('sistem.log-aktivitas.index', [
            'logs' => $logs,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'modul' => $modul,
            'event' => $event,
            'userId' => $userId,
            'search' => $search,
            'userList' => User::orderBy('name')->get(['id', 'name']),
            'eventLabels' => self::EVENT_LABELS,
            'modulLabels' => self::MODUL_LABELS,
        ]);
    }
}
