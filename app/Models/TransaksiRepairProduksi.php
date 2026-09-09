<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TransaksiRepairProduksi extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'transaksi_repair_produksi';

    protected $fillable = [
        'tanggal_repair_produksi', 'id_shift', 'id_karyawan', 'id_part_proses_repair_produksi',
        'jam_start', 'jam_finish', 'qty_aktual', 'actual_cycle_time',
        'created_by', 'updated_by',
    ];

    protected $casts = [
        'tanggal_repair_produksi' => 'date:Y-m-d',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('lhr_repair_produksi');
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function partProsesRepairProduksi(): BelongsTo
    {
        return $this->belongsTo(MasterPartProsesRepairProduksi::class, 'id_part_proses_repair_produksi');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    protected static function booted(): void
    {
        static::saving(function (self $lhr) {
            if (Schema::hasColumn('transaksi_repair_produksi', 'durasi_menit')) {
                $lhr->durasi_menit = self::hitungDurasiMenit($lhr->jam_start, $lhr->jam_finish);
            }
        });
    }

    /** Sama persis TransaksiProduksi::hitungDurasiMenit() — disalin, bukan dipanggil silang. */
    public static function hitungDurasiMenit(?string $jamStart, ?string $jamFinish): int
    {
        try {
            $start = Carbon::parse('2000-01-01 '.$jamStart);
            $finish = Carbon::parse('2000-01-01 '.$jamFinish);
            if ($finish->lte($start)) {
                $finish->addDay();
            }

            return (int) $start->diffInMinutes($finish);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function getWaktuKerjaJamAttribute(): float
    {
        $menit = $this->durasi_menit ?? self::hitungDurasiMenit($this->jam_start, $this->jam_finish);

        return round($menit / 60, 2);
    }

    /** SPH Aktual = qty_aktual / waktu_kerja_jam */
    public function getSphAktualAttribute(): int
    {
        $wkj = $this->waktu_kerja_jam;

        return $wkj > 0 ? (int) round($this->qty_aktual / $wkj) : 0;
    }
}
