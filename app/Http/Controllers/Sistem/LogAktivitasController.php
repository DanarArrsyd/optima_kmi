<?php

namespace App\Http\Controllers\Sistem;

use App\Exports\TabelExport;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Maatwebsite\Excel\Facades\Excel;
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
        'lhp_produksi' => 'LHP Produksi',
        'lhp_qc' => 'LHP Quality',
        'dandori' => 'Dandori',
        'breakdown_mesin' => 'Breakdown Mesin',
        'breakdown_dies' => 'Breakdown Dies',
        'preventive_mesin' => 'Preventive Mesin',
        'preventive_dies' => 'Preventive Dies',
        'auth' => 'Autentikasi',
    ];

    public function index(Request $request)
    {
        ['query' => $query, 'dateFrom' => $dateFrom, 'dateTo' => $dateTo, 'pakaiTanggal' => $pakaiTanggal,
            'modul' => $modul, 'event' => $event, 'userId' => $userId, 'search' => $search] = $this->queryLog($request);

        $logs = $query->paginate(50)->withQueryString();

        return view('sistem.log-aktivitas.index', [
            'logs' => $logs,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'pakaiTanggal' => $pakaiTanggal,
            'modul' => $modul,
            'event' => $event,
            'userId' => $userId,
            'search' => $search,
            'userList' => User::orderBy('name')->get(['id', 'name']),
            'eventLabels' => self::EVENT_LABELS,
            'modulLabels' => self::MODUL_LABELS,
        ]);
    }

    /** Query log + nilai filternya — BERSAMA index() dan export(). */
    private function queryLog(Request $request): array
    {
        /* Default dihitung dari hari WIB, karena rentang di bawah dibaca WIB.
         * now() (UTC) membuat log 00:00–07:00 WIB hilang dari tampilan bawaan. */
        $hariIni = now('Asia/Jakarta');
        $dateFrom = $request->input('date_from', $hariIni->copy()->subDays(7)->toDateString());
        $dateTo = $request->input('date_to', $hariIni->toDateString());
        $modul = $request->input('modul', '');
        $event = $request->input('event', '');
        $userId = $request->input('user_id', '');
        $search = trim((string) $request->input('search', ''));

        /* Centang Filter by Date di dock — pola sama dengan LHP Produksi:
         * bawaannya nyala, dan filter_apply membedakan "dimatikan" dari
         * "halaman baru dibuka". */
        $pakaiTanggal = $request->boolean('pakai_tanggal', ! $request->has('filter_apply'));

        /* created_at tersimpan UTC, filter tanggal dari user itu WIB (UTC+7) —
         * whereDate polos di kolom UTC menggeser batas hari sampai 7 jam.
         * Konversi rentang WIB ke UTC dulu di sini. */
        $rentangUtcFrom = Carbon::parse($dateFrom, 'Asia/Jakarta')->startOfDay()->utc();
        $rentangUtcTo = Carbon::parse($dateTo, 'Asia/Jakarta')->endOfDay()->utc();

        $query = Activity::query()
            ->with('causer')
            ->when($pakaiTanggal, fn ($q) => $q->whereBetween('created_at', [$rentangUtcFrom, $rentangUtcTo]))
            ->when($modul !== '', fn ($q) => $q->where('log_name', $modul))
            ->when($event !== '', fn ($q) => $q->where('event', $event))
            ->when($userId !== '', fn ($q) => $q->where('causer_id', $userId)->where('causer_type', User::class))
            ->when($search !== '', fn ($q) => $q->where('description', 'like', "%{$search}%"))
            ->latest('id');

        return compact('query', 'dateFrom', 'dateTo', 'pakaiTanggal', 'modul', 'event', 'userId', 'search');
    }

    /** Batas baris ekspor: log tumbuh tanpa batas, satu unduhan tak boleh menghabiskan memori server. */
    private const BATAS_EKSPOR = 10000;

    /** Export Excel log sesuai filter aktif. Waktu dalam WIB seperti di layar. */
    public function export(Request $request)
    {
        $query = $this->queryLog($request)['query'];
        $total = (clone $query)->count();

        $baris = $query->limit(self::BATAS_EKSPOR)->get()->map(function ($log) {
            $old = $log->properties['old'] ?? [];
            $baru = $log->properties['attributes'] ?? [];
            $ubah = collect(array_unique(array_merge(array_keys($old), array_keys($baru))))
                ->map(fn ($k) => $k.': '.json_encode($old[$k] ?? null, JSON_UNESCAPED_UNICODE).' → '.json_encode($baru[$k] ?? null, JSON_UNESCAPED_UNICODE))
                ->implode("\n");

            return [
                $log->created_at->clone()->timezone('Asia/Jakarta')->format('Y-m-d H:i'),
                $log->causer?->name ?? '—',
                self::MODUL_LABELS[$log->log_name] ?? ($log->log_name ?? '—'),
                self::EVENT_LABELS[$log->event] ?? ($log->event ?? '—'),
                $log->description,
                $ubah,
            ];
        })->all();

        if ($total > self::BATAS_EKSPOR) {
            $baris[] = ['', '', '', '', "Dipotong: {$total} baris cocok, yang diekspor ".self::BATAS_EKSPOR.' terbaru. Persempit filter untuk sisanya.', ''];
        }

        return Excel::download(new TabelExport(['Waktu (WIB)', 'User', 'Modul', 'Aksi', 'Deskripsi', 'Perubahan'], $baris, 'Log Aktivitas'),
            'log-aktivitas-'.now()->format('Ymd-His').'.xlsx');
    }
}
