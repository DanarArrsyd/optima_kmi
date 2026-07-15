<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterPartProses extends Model
{
    use SoftDeletes;
    protected $table = 'master_part_proses';
    protected $fillable = [
        'id_part', 'id_proses', 'nama_proses',
        'sph_standar_produksi', 'sph_last_upgraded_at',
        'sph_standar_qc',       'sph_qc_last_upgraded_at',
        'std_trial_qc_menit',   'std_setting_dies_menit',
    ];

    protected $casts = [
        'sph_last_upgraded_at'    => 'datetime',
        'sph_qc_last_upgraded_at' => 'datetime',
    ];

    /** Log semua upgrade (produksi + qc), terbaru dulu */
    public function sphLog(): HasMany
    {
        return $this->hasMany(MasterPartProsesSphLog::class, 'id_part_proses')
                    ->orderByDesc('upgraded_at');
    }

    /** Hanya log upgrade produksi */
    public function sphProduksiLog(): HasMany
    {
        return $this->hasMany(MasterPartProsesSphLog::class, 'id_part_proses')
                    ->where('type', 'produksi')
                    ->orderByDesc('upgraded_at');
    }

    /** Hanya log upgrade QC */
    public function sphQcLog(): HasMany
    {
        return $this->hasMany(MasterPartProsesSphLog::class, 'id_part_proses')
                    ->where('type', 'qc')
                    ->orderByDesc('upgraded_at');
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
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_part_proses');
    }

    public function preventiveLogs(): HasMany
    {
        return $this->hasMany(DiesPreventiveLog::class, 'id_part_proses')
                    ->orderByDesc('tanggal_preventive');
    }
}
