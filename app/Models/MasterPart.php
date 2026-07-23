<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MasterPart extends Model
{
    use SoftDeletes;

    protected $table = 'master_part';

    protected $fillable = ['part_no', 'customer', 'part_name', 'tanggal_instalasi'];

    protected $casts = ['tanggal_instalasi' => 'date'];

    public function proses(): HasMany
    {
        return $this->hasMany(MasterPartProses::class, 'id_part');
    }
}
