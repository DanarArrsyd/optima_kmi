<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class TransaksiQc extends Model
{
    use SoftDeletes, LogsActivity;
    protected $table = 'transaksi_qc';
    protected $fillable = [
        'tgl_pengecekan', 'tgl_produksi_part', 'id_shift', 'id_karyawan_qc', 'id_part_proses',
        'jam_start', 'jam_finish', 'qty_ok', 'qty_ng', 'actual_cycle_time', 'created_by', 'updated_by',
    ];
    protected $casts = ['tgl_pengecekan' => 'date', 'tgl_produksi_part' => 'date'];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logAll()->useLogName('transaksi_qc');
    }

    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawanQc(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan_qc'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
}
