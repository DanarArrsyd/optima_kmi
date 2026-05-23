<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterShift extends Model
{
    use SoftDeletes;
    protected $table = 'master_shift';
    protected $fillable = ['nama_shift', 'jam_start', 'jam_finish'];

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_shift');
    }
    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_shift');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandori::class, 'id_shift');
    }
}
