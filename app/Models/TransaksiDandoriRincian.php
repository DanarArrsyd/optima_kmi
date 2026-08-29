<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TransaksiDandoriRincian extends Model
{
    use LogsActivity;
    use SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('dandori');
    }

    protected $table = 'transaksi_dandori_rincian';

    protected $fillable = [
        'tanggal',
        'id_shift',
        'id_karyawan',
        'id_mesin',
        'id_part_proses',
        'id_losstime',
        'actual_menit',
        'std_menit',
        'over_menit',
        'created_by',
        'staff_note', 'staff_note_at', 'staff_note_by',
        'staff_note_resolved_at', 'staff_note_resolved_by',
    ];

    protected $casts = [
        'staff_note_at' => 'datetime',
        'staff_note_resolved_at' => 'datetime',
        'tanggal' => 'date',
        'actual_menit' => 'decimal:2',
        'std_menit' => 'decimal:2',
        'over_menit' => 'decimal:2',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function losstime(): BelongsTo
    {
        return $this->belongsTo(MasterLosstime::class, 'id_losstime');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function noteBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_note_by');
    }

    public function noteResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_note_resolved_by');
    }
}
