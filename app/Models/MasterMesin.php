<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterMesin extends Model
{
    use SoftDeletes;
    protected $table = 'master_mesin';
    protected $fillable = ['id_mesin', 'nama_mesin', 'line', 'tipe_mesin', 'kapasitas_max_stroke'];
    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_mesin');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_mesin');
    }
}
