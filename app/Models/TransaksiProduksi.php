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
        'anomaly_reviewed_at', 'anomaly_reviewed_by',
        'staff_note', 'staff_note_at', 'staff_note_by',
        'staff_note_resolved_at', 'staff_note_resolved_by',
    ];
    protected $casts = [
        'tanggal_produksi'        => 'date:Y-m-d',
        'anomaly_reviewed_at'     => 'datetime',
        'staff_note_at'           => 'datetime',
        'staff_note_resolved_at'  => 'datetime',
    ];
    public function shift(): BelongsTo { return $this->belongsTo(MasterShift::class, 'id_shift'); }
    public function karyawan(): BelongsTo { return $this->belongsTo(MasterKaryawan::class, 'id_karyawan'); }
    public function mesin(): BelongsTo { return $this->belongsTo(MasterMesin::class, 'id_mesin'); }
    public function partProses(): BelongsTo { return $this->belongsTo(MasterPartProses::class, 'id_part_proses'); }
    public function jenisNg(): BelongsTo { return $this->belongsTo(MasterJenisNg::class, 'id_jenis_ng'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function updatedBy(): BelongsTo { return $this->belongsTo(User::class, 'updated_by'); }
    public function noteBy(): BelongsTo         { return $this->belongsTo(User::class, 'staff_note_by'); }
    public function noteResolvedBy(): BelongsTo { return $this->belongsTo(User::class, 'staff_note_resolved_by'); }

    /**
     * Isi durasi_menit otomatis tiap simpan (create & update) dari jam_start/finish,
     * jadi kolom persist selalu sinkron walau jam diedit. Sumber tunggal:
     * hitungDurasiMenit(). durasi_menit sengaja TIDAK di-$fillable — dikelola sistem.
     */
    protected static function booted(): void
    {
        static::saving(function (self $lhp) {
            $lhp->durasi_menit = self::hitungDurasiMenit($lhp->jam_start, $lhp->jam_finish);
        });
    }

    /**
     * Waktu operasi dalam MENIT dari jam_start→jam_finish. Handle shift lewat
     * tengah malam (finish ≤ start → +1 hari). Jam tak valid → 0. SATU tempat
     * logika ini hidup: dipakai saving hook, accessor, & backfill migrasi.
     */
    public static function hitungDurasiMenit(?string $jamStart, ?string $jamFinish): int
    {
        try {
            $start  = \Carbon\Carbon::parse('2000-01-01 ' . $jamStart);
            $finish = \Carbon\Carbon::parse('2000-01-01 ' . $jamFinish);
            if ($finish->lte($start)) $finish->addDay();
            return (int) $start->diffInMinutes($finish);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Waktu kerja dalam desimal jam — pakai durasi_menit tersimpan (fallback hitung live). */
    public function getWaktuKerjaJamAttribute(): float
    {
        $menit = $this->durasi_menit ?? self::hitungDurasiMenit($this->jam_start, $this->jam_finish);
        return round($menit / 60, 2);
    }

    /** SPH Aktual = qty_aktual / waktu_kerja_jam */
    public function getSphAktualAttribute(): int
    {
        $wkj = $this->waktu_kerja_jam;
        return $wkj > 0 ? (int) round($this->qty_aktual / $wkj) : 0;
    }
}
