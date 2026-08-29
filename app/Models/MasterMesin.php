<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterMesin extends Model
{
    use LogsActivity, SoftDeletes;

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logFillable()->logOnlyDirty()->dontLogEmptyChanges()->useLogName('master_mesin');
    }

    /** Sumber tunggal daftar tipe mesin (dipakai validasi + dropdown). Tambah tipe = edit di sini saja. */
    public const TIPE_MESIN = [
        'STAMPING',
        'PROGRESSIVE',
        'WELDING',
        'FORKLIFT',
        'HANDLIFT ELECTRIC',
        'HANDLIFT MANUAL',
        'ENGINEERING',
    ];

    protected $table = 'master_mesin';

    protected $fillable = ['id_mesin', 'nama_mesin', 'line', 'tipe_mesin', 'kapasitas_max_stroke', 'tanggal_instalasi'];

    protected $casts = ['tanggal_instalasi' => 'date'];

    public function transaksiProduksi(): HasMany
    {
        return $this->hasMany(TransaksiProduksi::class, 'id_mesin');
    }

    public function transaksiDandori(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_mesin');
    }

    public function breakdownMesin(): HasMany
    {
        return $this->hasMany(TransaksiBreakdownMesin::class, 'id_mesin');
    }

    public function preventiveLogs(): HasMany
    {
        return $this->hasMany(MesinPreventiveLog::class, 'id_mesin')
            ->orderByDesc('tanggal_preventive');
    }
}
