<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class MasterPart extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_part';
    protected $fillable = ['part_no', 'part_name'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_part');
    }

    public function proses(): HasMany
    {
        return $this->hasMany(MasterPartProses::class, 'id_part');
    }
}
