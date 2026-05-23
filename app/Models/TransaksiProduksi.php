<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class TransaksiProduksi extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'transaksi_produksi';
    protected $fillable = [
        'tanggal_produksi', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses',
        'jam_start', 'jam_finish', 'qty_aktual', 'actual_cycle_time', 'created_by', 'updated_by',
    ];
    protected $casts = ['tanggal_produksi' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('transaksi_produksi');
    }

    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawan(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan'); }
    public function mesin(): BelongsTo { return $this->belongsTo(MasterMesin::class, 'id_mesin'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
