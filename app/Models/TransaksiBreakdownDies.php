<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransaksiBreakdownDies extends Model
{
    use SoftDeletes;

    protected $table = 'transaksi_breakdown_dies';

    protected $fillable = [
        'no_lkd', 'id_part_proses',
        'tanggal_breakdown', 'jam_breakdown',
        'tanggal_mulai_perbaikan', 'jam_mulai_perbaikan',
        'tanggal_selesai_perbaikan', 'jam_selesai_perbaikan',
        'problem_dies', 'penyebab_breakdown', 'tindakan_perbaikan',
        'status_breakdown', 'pic_maintenance',
        'repair_time', 'total_downtime',
    ];

    protected $casts = [
        'tanggal_breakdown' => 'date',
        'tanggal_mulai_perbaikan' => 'date',
        'tanggal_selesai_perbaikan' => 'date',
        'repair_time' => 'decimal:2',
        'total_downtime' => 'decimal:2',
    ];

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }
}
