<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PreventiveSchedule extends Model
{
    protected $fillable = [
        'bulan', 'jenis', 'nomor_dokumen', 'generated_at', 'generated_by',
    ];

    protected $casts = [
        'bulan' => 'date:Y-m-d',
        'generated_at' => 'datetime',
    ];

    public function items(): HasMany
    {
        return $this->hasMany(PreventiveScheduleItem::class, 'schedule_id');
    }

    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'generated_by');
    }
}
