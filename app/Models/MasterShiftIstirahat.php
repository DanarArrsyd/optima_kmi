<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MasterShiftIstirahat extends Model
{
    public $timestamps = false;

    protected $table = 'master_shift_istirahat';

    protected $fillable = ['id_shift', 'kelompok_hari', 'jam_start', 'jam_finish'];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }
}
