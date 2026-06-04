<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterPartProsesSphLog extends Model
{
    protected $table      = 'master_part_proses_sph_log';
    public    $timestamps = false;               // kolom manual: upgraded_at

    protected $fillable = [
        'id_part_proses',
        'type',           // 'produksi' | 'qc'
        'sph_standar_lama',
        'sph_standar_baru',
        'upgraded_by',
        'catatan',
        'upgraded_at',
    ];

    protected $casts = [
        'upgraded_at' => 'datetime',
    ];

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function upgradedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'upgraded_by');
    }
}
