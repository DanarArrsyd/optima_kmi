<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterPartProsesRepair extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_part_proses_repair';

    protected $fillable = ['id_part', 'id_proses', 'nama_proses', 'sph_standar_repair'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_part_proses_repair');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MasterPart::class, 'id_part');
    }

    public function transaksiRepair(): HasMany
    {
        return $this->hasMany(TransaksiRepair::class, 'id_part_proses_repair');
    }
}
