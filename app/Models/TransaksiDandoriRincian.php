<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiDandoriRincian extends Model
{
    use SoftDeletes;

    protected $table = 'transaksi_dandori_rincian';

    protected $fillable = [
        'tanggal',
        'id_shift',
        'id_karyawan',
        'id_mesin',
        'id_part_proses',
        'id_losstime',
        'actual_menit',
        'std_menit',
        'over_menit',
        'created_by',
    ];

    protected $casts = [
        'tanggal'      => 'date',
        'actual_menit' => 'decimal:2',
        'std_menit'    => 'decimal:2',
        'over_menit'   => 'decimal:2',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
    }

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function losstime(): BelongsTo
    {
        return $this->belongsTo(MasterLosstime::class, 'id_losstime');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
