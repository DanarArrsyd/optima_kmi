<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterKaryawan extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_karyawan';
    protected $fillable = ['nik', 'nama_karyawan', 'departemen'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_karyawan');
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
        return $this->hasMany(TransaksiDandori::class, 'id_karyawan');
    }
    public function user(): HasOne
    {
        return $this->hasOne(User::class, 'id_karyawan');
    }
}
