<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class MasterLosstime extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'master_losstime';
    protected $fillable = ['kode', 'jenis_losstime', 'std_menit_default', 'std_menit_progressive'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('master_losstime');
    }

    public function rincian(): HasMany
    {
        return $this->hasMany(TransaksiDandoriRincian::class, 'id_losstime');
    }
}
