<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterShift extends Model
{
    use SoftDeletes;

    protected $table = 'master_shift';

    protected $fillable = [
        'nama_shift',
        'jam_start', 'jam_finish',
        'jam_start_jumat', 'jam_finish_jumat',
        'jam_start_sabtu', 'jam_finish_sabtu',
        'dihitung_availability',
    ];

    protected $casts = [
        'dihitung_availability' => 'boolean',
    ];

    /** Semua istirahat untuk shift ini. */
    public function istirahat(): HasMany
    {
        return $this->hasMany(MasterShiftIstirahat::class, 'id_shift')->orderBy('kelompok_hari')->orderBy('jam_start');
    }

    /** Helper: istirahat per kelompok hari. */
    public function istirahatSenin(): HasMany
    {
        return $this->hasMany(MasterShiftIstirahat::class, 'id_shift')
            ->where('kelompok_hari', 'senin_kamis')->orderBy('jam_start');
    }

    public function istirahatJumat(): HasMany
    {
        return $this->hasMany(MasterShiftIstirahat::class, 'id_shift')
            ->where('kelompok_hari', 'jumat')->orderBy('jam_start');
    }

    public function istirahatSabtu(): HasMany
    {
        return $this->hasMany(MasterShiftIstirahat::class, 'id_shift')
            ->where('kelompok_hari', 'sabtu')->orderBy('jam_start');
    }

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
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_shift');
    }
}
