<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterJenisNg extends Model
{
    use SoftDeletes;

    protected $table = 'master_jenis_ng';

    protected $fillable = ['kode', 'nama_ng', 'deskripsi'];

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_jenis_ng');
    }

    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_jenis_ng');
    }
}
