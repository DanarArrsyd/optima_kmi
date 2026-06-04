<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MasterPart extends Model
{
    use SoftDeletes;
    protected $table = 'master_part';
    protected $fillable = ['part_no', 'customer', 'part_name'];
    public function proses(): HasMany
    {
        return $this->hasMany(MasterPartProses::class, 'id_part');
    }
}
