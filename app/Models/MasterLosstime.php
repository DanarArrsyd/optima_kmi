<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterLosstime extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_losstime';

    protected $fillable = ['kode', 'jenis_losstime', 'std_menit_default', 'std_menit_progressive'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_losstime');
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_losstime');
    }
}
