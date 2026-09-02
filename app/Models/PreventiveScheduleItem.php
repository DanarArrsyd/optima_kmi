<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class PreventiveScheduleItem extends Model
{
    use LogsActivity;

    protected $fillable = [
        'schedule_id', 'id_mesin', 'id_part_proses',
        'stroke_snapshot', 'pct_snapshot', 'threshold_snapshot',
        'tanggal_rencana', 'minggu_ke', 'backlog_since',
        'is_manual_override', 'overridden_by', 'overridden_at',
    ];

    protected $casts = [
        'tanggal_rencana' => 'date:Y-m-d',
        'pct_snapshot' => 'float',
        'backlog_since' => 'date:Y-m-d',
        'is_manual_override' => 'boolean',
        'overridden_at' => 'datetime',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logOnly(['tanggal_rencana', 'minggu_ke', 'is_manual_override', 'backlog_since'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges()
            ->useLogName('preventive_schedule_item');
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PreventiveSchedule::class, 'schedule_id');
    }

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function overriddenBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'overridden_by');
    }
}
