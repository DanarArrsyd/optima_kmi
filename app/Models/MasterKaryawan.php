<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterKaryawan extends Model
{
    use SoftDeletes;

    protected $table = 'master_karyawan';

    protected $fillable = ['nik', 'nama_karyawan', 'departemen', 'is_aktif', 'tanggal_nonaktif'];

    protected $casts = ['is_aktif' => 'boolean', 'tanggal_nonaktif' => 'date'];

    public function scopeAktif($query)
    {
        return $query->where('is_aktif', true);
    }

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_karyawan');
    }

    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_karyawan_qc');
    }

    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_karyawan');
    }

    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id_karyawan');
    }
}
