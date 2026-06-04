<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiProduksi extends Model
{
    use SoftDeletes;
    protected $table = 'transaksi_produksi';
    protected $fillable = [
        'tanggal_produksi', 'id_shift', 'id_karyawan', 'id_mesin', 'id_part_proses',
        'jam_start', 'jam_finish', 'qty_aktual', 'qty_ng', 'id_jenis_ng',
        'actual_cycle_time', 'created_by', 'updated_by',
    ];
    protected $casts = ['tanggal_produksi' => 'date:Y-m-d'];
    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawan(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan'); }
    public function mesin(): BelongsTo { return $this->belongsTo(MasterMesin::class, 'id_mesin'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function jenisNg(): BelongsTo { return $this->belongsTo(MasterJenisNg::class, 'id_jenis_ng'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }

    /** Waktu kerja dalam desimal jam — handle overnight shift */
    public function getWaktuKerjaJamAttribute(): float
    {
        try {
            $start  = \Carbon\Carbon::parse('2000-01-01 ' . $this->jam_start);
            $finish = \Carbon\Carbon::parse('2000-01-01 ' . $this->jam_finish);
            if ($finish->lte($start)) $finish->addDay();
            $menit = $start->diffInMinutes($finish);
            return round($menit / 60, 2);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** SPH Aktual = qty_aktual / waktu_kerja_jam */
    public function getSphAktualAttribute(): int
    {
        $wkj = $this->waktu_kerja_jam;
        return $wkj > 0 ? (int) round($this->qty_aktual / $wkj) : 0;
    }
}
