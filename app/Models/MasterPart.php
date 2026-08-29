<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterPart extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_part';

    protected $fillable = ['part_no', 'customer', 'part_name', 'tanggal_instalasi'];

    protected $casts = ['tanggal_instalasi' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_part');
    }

    public function proses(): HasMany
    {
        return $this->hasMany(MasterPartProses::class, 'id_part');
    }
}
