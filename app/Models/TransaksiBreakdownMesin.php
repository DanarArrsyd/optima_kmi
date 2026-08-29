<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TransaksiBreakdownMesin extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('breakdown_mesin');
    }

    protected $table = 'transaksi_breakdown_mesin';

    protected $fillable = [
        'no_lkm', 'id_mesin',
        'tanggal_breakdown', 'jam_breakdown',
        'tanggal_mulai_perbaikan', 'jam_mulai_perbaikan',
        'tanggal_selesai_perbaikan', 'jam_selesai_perbaikan',
        'problem_mesin', 'penyebab_breakdown', 'tindakan_perbaikan',
        'status_breakdown', 'pic_maintenance',
        'repair_time', 'total_downtime',
    ];

    protected $casts = [
        'tanggal_breakdown' => 'date',
        'tanggal_mulai_perbaikan' => 'date',
        'tanggal_selesai_perbaikan' => 'date',
        'repair_time' => 'decimal:2',
        'total_downtime' => 'decimal:2',
    ];

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }
}
