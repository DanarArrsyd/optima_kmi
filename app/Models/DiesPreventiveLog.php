<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class DiesPreventiveLog extends Model
{
    use SoftDeletes;

    protected $table = 'dies_preventive_log';

    protected $fillable = [
        'id_part_proses', 'tanggal_preventive', 'id_karyawan',
        'stroke_at_preventive', 'catatan', 'checksheet_path', 'created_by',
    ];

    protected $casts = [
        'tanggal_preventive' => 'date:Y-m-d',
    ];

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function karyawan(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
