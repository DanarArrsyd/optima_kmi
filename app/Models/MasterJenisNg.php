<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterJenisNg extends Model
{
    use LogsActivity, SoftDeletes;

    protected $table = 'master_jenis_ng';

    protected $fillable = ['kode', 'nama_ng', 'kategori', 'deskripsi'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_jenis_ng');
    }

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_jenis_ng');
    }

    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_jenis_ng');
    }
}
