<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterPartProsesRepairProduksi extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_part_proses_repair_produksi';

    protected $fillable = ['id_part', 'id_proses', 'nama_proses', 'sph_standar_repair_produksi'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_part_proses_repair_produksi');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MasterPart::class, 'id_part');
    }

    public function transaksiRepairProduksi(): HasMany
    {
        return $this->hasMany(TransaksiRepairProduksi::class, 'id_part_proses_repair_produksi');
    }
}
