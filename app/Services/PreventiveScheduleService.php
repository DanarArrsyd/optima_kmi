<?php

namespace App\Services;

use App\Models\PreventiveSchedule;
use App\Models\PreventiveScheduleItem;
use App\Models\TransaksiProduksi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class PreventiveScheduleService
{
    private const ROMAN = [1 => 'I', 2 => 'II', 3 => 'III', 4 => 'IV', 5 => 'V', 6 => 'VI', 7 => 'VII', 8 => 'VIII', 9 => 'IX', 10 => 'X', 11 => 'XI', 12 => 'XII'];

    /** Slot preventive per minggu, per jenis. Hardcode — nol UI setting (lihat spec). */
    private const KAPASITAS = ['mesin' => 1, 'dies' => 5];

    /**
     * Hari (isoWeekday, 1=Senin...7=Minggu) yang boleh jadi tanggal PLAN, per
     * jenis. Mesin Senin-Kamis — Jumat (14 jam) & Sabtu (10 jam) di master_shift
     * kependekan buat PM penuh. Dies Senin-Jumat — Sabtu tetap dihindari.
     * Minggu & hari_libur SUDAH dikeluarkan duluan oleh hariKerjaDalamBulan(),
     * jadi daftar ini nol perlu nyebut 7.
     */
    private const HARI_DIIZINKAN = [
        'mesin' => [1, 2, 3, 4],
        'dies' => [1, 2, 3, 4, 5],
    ];

    /** Jendela hari buat hitung laju stroke/hari — lihat lajuHarian(). */
    private const HARI_WINDOW_LAJU = 30;

    /** @return array<int> Hari (isoWeekday) yang diizinkan buat tanggal PLAN, per jenis. */
    public function hariDiizinkan(string $jenis): array
    {
        return self::HARI_DIIZINKAN[$jenis] ?? [];
    }

    public function __construct(
        private MaintenanceDashboardService $mesinSvc,
        private MaintenanceDiesDashboardService $diesSvc,
    ) {}

    /**
     * Generate (atau regenerate) jadwal preventive satu bulan, satu jenis.
     * Regenerate bulan yang SAMA: UPDATE record (nomor_dokumen dipertahankan),
     * items lama dibuang dan diganti snapshot baru — jadwal yg udah dicetak nggak
     * berubah kalau stroke jalan terus SETELAH dicetak, tapi regenerate manual
     * sengaja menang (operator yg klik ulang mau data terbaru).
     */
    public function generate(string $jenis, Carbon $bulan): PreventiveSchedule
    {
        if (! array_key_exists($jenis, self::KAPASITAS)) {
            throw new \InvalidArgumentException("Jenis preventive schedule tidak dikenal: {$jenis}");
        }

        $bulan = $bulan->copy()->startOfMonth();

        return DB::transaction(function () use ($jenis, $bulan) {
            $schedule = PreventiveSchedule::firstOrNew([
                'bulan' => $bulan->toDateString(),
                'jenis' => $jenis,
            ]);
            $sudahAda = $schedule->exists;
            if (! $sudahAda) {
                $schedule->nomor_dokumen = $this->nomorDokumen($jenis, $bulan);
            }
            $schedule->generated_at = now();
            $schedule->generated_by = auth()->id();
            $schedule->save();

            // Kolom backlog_since bisa belum ada di DB kalau kode ini live SEBELUM
            // migrate jalan (jendela rsync→migrate saat deploy) — deploy target
            // migrasi ini di window sama, tapi jaga-jaga cron collide, degradasi ke
            // perilaku fase 1 (item nggak kebagian slot cuma dilog, bukan disimpan).
            $backlogDidukung = Schema::hasColumn('preventive_schedule_items', 'backlog_since');
            $backlogLama = $backlogDidukung ? $this->backlogSinceLama($jenis, $bulan, $sudahAda ? $schedule : null) : [];

            $schedule->items()->where('is_manual_override', false)->delete();

            $eligible = $this->eligibleItems($jenis, $bulan);
            $idTargetLocked = $schedule->items()
                ->where('is_manual_override', true)
                ->pluck($jenis === 'mesin' ? 'id_mesin' : 'id_part_proses')
                ->all();
            if ($idTargetLocked !== []) {
                $kolomIdEligible = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';
                $eligible = $eligible->reject(fn ($row) => in_array($row[$kolomIdEligible], $idTargetLocked, true))->values();
            }
            $hariKerja = $this->hariKerjaDalamBulan($bulan)
                ->filter(fn (Carbon $d) => in_array($d->isoWeekday(), self::HARI_DIIZINKAN[$jenis], true))
                ->values();
            $mingguBuckets = $this->kelompokkanPerMinggu($hariKerja);
            $kapasitas = self::KAPASITAS[$jenis];
            $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

            $idx = 0;
            // Slot diisi urut prioritas (proyeksi paling dekat duluan), TAPI tanggal
            // PLAN yang didapat murni posisi slot pertama tersedia — nol usaha
            // menyelaraskan PLAN persis sebelum proyeksi_overdue item itu sendiri.
            // Item yang proyeksinya jauh (mis. akhir bulan) bisa kebagian slot di
            // awal bulan kalau dia kandidat prioritas tertinggi bulan itu — trade-off
            // sengaja, bukan bug: "earliest available slot wins" tetap lebih
            // sederhana & predictable daripada alokasi presisi-tanggal untuk fase 1.
            // Kalau ini jadi masalah nyata (banyak siklus stroke kebuang), itu
            // kandidat kerjaan fase 2.
            foreach ($mingguBuckets as $mingguIndex => $hariList) {
                $mingguKe = $mingguIndex + 1;
                $n = $hariList->count();
                // Offset berputar per minggu — minggu 1 mulai dari hari
                // pertama daftar hari-diizinkan, minggu 2 geser satu, dst,
                // muter balik ke 0 kalau sudah lewat jumlah hari diizinkan.
                // Ini yang mencegah PLAN mesin selalu jatuh Senin.
                $offset = $n > 0 ? $mingguIndex % $n : 0;

                for ($slot = 0; $slot < $kapasitas; $slot++) {
                    if ($idx >= $eligible->count()) {
                        break 2;
                    }
                    if ($slot >= $n) {
                        // Minggu ini kehabisan hari diizinkan buat slot ke-$slot
                        // — kapasitas sisa minggu ini HANGUS, bukan dipindah ke
                        // minggu lain.
                        continue;
                    }

                    $posisi = ($offset + $slot) % $n;
                    $hari = $hariList[$posisi];

                    $item = $eligible[$idx];
                    PreventiveScheduleItem::create([
                        'schedule_id' => $schedule->id,
                        'id_mesin' => $jenis === 'mesin' ? $item['id_mesin'] : null,
                        'id_part_proses' => $jenis === 'dies' ? $item['id_part_proses'] : null,
                        'stroke_snapshot' => $item['current_stroke'],
                        'pct_snapshot' => $item['pct'],
                        'threshold_snapshot' => $item['threshold'],
                        'tanggal_rencana' => $hari->toDateString(),
                        'minggu_ke' => $mingguKe,
                        ...$backlogDidukung ? ['backlog_since' => null] : [],
                    ]);
                    $idx++;
                }
            }

            if ($idx < $eligible->count()) {
                $idxSebelumBacklog = $idx;
                if ($backlogDidukung) {
                    $sisaHariIni = now()->timezone('Asia/Jakarta')->toDateString();
                    for (; $idx < $eligible->count(); $idx++) {
                        $item = $eligible[$idx];
                        PreventiveScheduleItem::create([
                            'schedule_id' => $schedule->id,
                            'id_mesin' => $jenis === 'mesin' ? $item['id_mesin'] : null,
                            'id_part_proses' => $jenis === 'dies' ? $item['id_part_proses'] : null,
                            'stroke_snapshot' => $item['current_stroke'],
                            'pct_snapshot' => $item['pct'],
                            'threshold_snapshot' => $item['threshold'],
                            'tanggal_rencana' => null,
                            'minggu_ke' => null,
                            'backlog_since' => $backlogLama[$item[$kolomId]] ?? $sisaHariIni,
                        ]);
                    }
                }
                Log::info(sprintf(
                    'PreventiveSchedule %s %s: %d item eligible dicatat sebagai backlog (kapasitas bulan ini habis).',
                    $jenis, $bulan->toDateString(), $eligible->count() - $idxSebelumBacklog
                ));
            }

            return $schedule->fresh('items');
        });
    }

    /**
     * Map target_id => backlog_since LAMA, dipakai carry-over supaya
     * backlog_since nggak reset tiap generate. Sumbernya beda tergantung
     * situasi (lihat spec bagian "Logika generate()"):
     * - Regenerate bulan SAMA ($scheduleExists non-null) → baris lama
     *   milik schedule itu sendiri (sebentar lagi dihapus & dibangun ulang).
     * - Generate bulan BARU ($scheduleExists null) → schedule jenis yang
     *   sama di bulan SEBELUM $bulan yang PALING BARU.
     *
     * @return array<int, string> [id_mesin_atau_id_part_proses => 'Y-m-d']
     */
    private function backlogSinceLama(string $jenis, Carbon $bulan, ?PreventiveSchedule $scheduleExists): array
    {
        $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

        if ($scheduleExists !== null) {
            $sumber = $scheduleExists;
        } else {
            $sumber = PreventiveSchedule::where('jenis', $jenis)
                ->where('bulan', '<', $bulan->toDateString())
                ->orderByDesc('bulan')
                ->first();
        }

        if ($sumber === null) {
            return [];
        }

        return $sumber->items()
            ->whereNull('tanggal_rencana')
            ->whereNotNull('backlog_since')
            ->get([$kolomId, 'backlog_since'])
            ->mapWithKeys(fn ($row) => [$row->{$kolomId} => $row->backlog_since->toDateString()])
            ->all();
    }

    /**
     * Laju stroke per hari, SATU query batch per jenis (bukan N+1 per item — dies
     * bisa ratusan baris). Window 30 hari kalender SELESAI sebelum hari ini
     * (T-30 s.d. T-1) — hari T sendiri tidak dihitung karena datanya masih parsial.
     *
     * @return array<int, float> [id_mesin_atau_id_part_proses => stroke/hari]
     */
    private function lajuHarian(string $jenis): array
    {
        $akhir = Carbon::now()->subDay()->toDateString();
        $mulai = Carbon::now()->subDays(self::HARI_WINDOW_LAJU)->toDateString();
        $kolom = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';

        return TransaksiProduksi::query()
            ->whereBetween('tanggal_produksi', [$mulai, $akhir])
            ->selectRaw("{$kolom} as target_id, COALESCE(SUM(qty_aktual + COALESCE(qty_ng,0)),0) as total")
            ->groupBy($kolom)
            ->pluck('total', 'target_id')
            ->map(fn ($total) => (float) $total / self::HARI_WINDOW_LAJU)
            ->all();
    }

    /**
     * Union dua kriteria — pct≥80% (perilaku lama, safety net) ATAU proyeksi
     * overdue sebelum generate bulan depan (proaktif, baru) — diurut proyeksi
     * PALING DEKAT duluan. Item overdue sekarang (pct≥100) otomatis proyeksi=hari
     * ini, jadi selalu paling depan.
     */
    private function eligibleItems(string $jenis, Carbon $bulan): Collection
    {
        $rows = collect($jenis === 'mesin' ? $this->mesinSvc->computeCurrentCycle() : $this->diesSvc->computeCurrentCycle());
        $laju = $this->lajuHarian($jenis);
        $kolomId = $jenis === 'mesin' ? 'id_mesin' : 'id_part_proses';
        $today = Carbon::now();
        $batasProyeksi = $bulan->copy()->addMonthNoOverflow()->startOfMonth();

        return $rows
            ->map(function ($row) use ($laju, $kolomId, $today) {
                $rate = $laju[$row[$kolomId]] ?? 0.0;
                $sisa = max(0, $row['threshold'] - $row['current_stroke']);

                if ($row['pct'] >= 100) {
                    $proyeksi = $today->copy();
                } elseif ($rate > 0) {
                    $proyeksi = $today->copy()->addDays((int) ceil($sisa / $rate));
                } elseif ($row['pct'] >= 80) {
                    $proyeksi = $today->copy();
                } else {
                    $proyeksi = null;
                }

                $row['proyeksi_overdue'] = $proyeksi;

                return $row;
            })
            ->filter(fn ($row) => $row['pct'] >= 80 || ($row['proyeksi_overdue'] && $row['proyeksi_overdue']->lt($batasProyeksi)))
            // Two-key: proyeksi paling dekat duluan, dasi (proyeksi sama —
            // umumnya semua item overdue pct>=100 sama-sama proyeksi=hari ini)
            // dipecah pct DESC (paling jauh di atas ambang duluan). Sengaja
            // TIDAK bergantung pada urutan computeCurrentCycle() di
            // MaintenanceDashboardService/MaintenanceDiesDashboardService —
            // itu diurutkan buat tampilan dashboard, bukan kontrak buat file
            // ini, dan tidak boleh jadi satu-satunya sumber urutan prioritas.
            ->sortBy(fn ($row) => [$row['proyeksi_overdue']->timestamp, -$row['pct']])
            ->values();
    }

    /** Semua hari kerja (nol Minggu, nol hari_libur) dalam satu bulan, urut tanggal. */
    private function hariKerjaDalamBulan(Carbon $bulan): Collection
    {
        $akhir = $bulan->copy()->endOfMonth();
        $hari = collect();
        for ($d = $bulan->copy(); $d->lte($akhir); $d->addDay()) {
            if (KalenderKerjaService::hariKerja($d)) {
                $hari->push($d->copy());
            }
        }

        return $hari;
    }

    /**
     * Kelompokkan hari kerja per minggu kalender (Senin-Minggu ISO). minggu_ke yang
     * disimpan adalah URUTAN RELATIF dalam bulan ini (1, 2, 3, ...), BUKAN nomor
     * minggu ISO mentah (mis. "minggu 35") — lebih gampang dibaca di PDF.
     *
     * @return Collection<int, Collection<int, Carbon>>
     */
    private function kelompokkanPerMinggu(Collection $hariKerja): Collection
    {
        return $hariKerja
            ->groupBy(fn (Carbon $d) => $d->format('o-W'))
            ->values()
            ->map(fn ($grp) => $grp->values());
    }

    private function nomorDokumen(string $jenis, Carbon $bulan): string
    {
        $prefix = $jenis === 'mesin' ? 'PM-MESIN' : 'PM-DIES';
        $tahun = $bulan->year;

        $maxNomor = PreventiveSchedule::where('jenis', $jenis)
            ->whereYear('bulan', $tahun)
            ->get()
            ->map(function ($s) {
                $bagian = explode('/', $s->nomor_dokumen);

                return (int) ($bagian[1] ?? 0);
            })
            ->max() ?? 0;

        $roman = self::ROMAN[$bulan->month] ?? 'I';

        return "{$prefix}/".($maxNomor + 1)."/{$roman}/{$tahun}";
    }
}
