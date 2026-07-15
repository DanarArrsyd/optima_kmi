<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MesinPreventiveLog extends Model
{
    use SoftDeletes;

    protected $table = 'mesin_preventive_log';

    protected $fillable = [
        'id_mesin', 'tanggal_preventive', 'id_karyawan',
        'stroke_at_preventive', 'catatan', 'created_by',
    ];

    protected $casts = [
        'tanggal_preventive' => 'date:Y-m-d',
    ];

    public function mesin(): BelongsTo
    {
        return $this->belongsTo(MasterMesin::class, 'id_mesin');
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
