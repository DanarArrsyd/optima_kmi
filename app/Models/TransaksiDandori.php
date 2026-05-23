<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class TransaksiDandori extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'transaksi_dandori';
    protected $fillable = [
        'tanggal', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses',
        'total_aktual_menit', 'standard_time_menit', 'status', 'created_by', 'updated_by',
    ];
    protected $casts = ['tanggal' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('transaksi_dandori');
    }

    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawan(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan'); }
    public function mesin(): BelongsTo { return $this->belongsTo(MasterMesin::class, 'id_mesin'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function rincian(): HasMany { return $this->hasMany(TransaksiDandoriRincian::class, 'id_transaksi_dandori'); }
}
