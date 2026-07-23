<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterLosstime extends Model
{
    use SoftDeletes;

    protected $table = 'master_losstime';

    protected $fillable = ['kode', 'jenis_losstime', 'std_menit_default', 'std_menit_progressive'];

    public function rincian(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_losstime');
    }
}
