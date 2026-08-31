<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PreventiveScheduleItem extends Model
{
    protected $fillable = [
        'schedule_id', 'id_mesin', 'id_part_proses',
        'stroke_snapshot', 'pct_snapshot', 'threshold_snapshot',
        'tanggal_rencana', 'minggu_ke',
    ];

    protected $casts = [
        'tanggal_rencana' => 'date:Y-m-d',
        'pct_snapshot' => 'float',
    ];

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
}
