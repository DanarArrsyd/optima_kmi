<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterPartProses extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_part_proses';
    protected $fillable = [
        'id_part', 'id_proses', 'nama_proses',
        'sph_standar_produksi', 'cycle_time_standar_produksi',
        'sph_standar_qc', 'cycle_time_standar_qc',
        'std_dandori_menit',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_part_proses');
    }

    public function part(): BelongsTo
    {
        return $this->belongsTo(MasterPart::class, 'id_part');
    }
    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_part_proses');
    }
    public function transaksiQc(): HasMany
    {
        return $this->hasMany(TransaksiQc::class, 'id_part_proses');
    }
    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandori::class, 'id_part_proses');
    }
}
