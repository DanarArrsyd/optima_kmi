<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class TransaksiQc extends Model
{
    use SoftDeletes;

    protected $table = 'transaksi_qc';

    protected $fillable = [
        'tgl_pengecekan', 'tgl_produksi_part', 'id_shift', 'id_karyawan_qc', 'id_part_proses',
        'jam_start', 'jam_finish', 'qty_ok', 'qty_ng', 'id_jenis_ng', 'actual_cycle_time', 'created_by', 'updated_by',
        'staff_note', 'staff_note_at', 'staff_note_by',
        'staff_note_resolved_at', 'staff_note_resolved_by',
    ];

    protected $casts = [
        'tgl_pengecekan' => 'date',
        'tgl_produksi_part' => 'date',
        'staff_note_at' => 'datetime',
        'staff_note_resolved_at' => 'datetime',
    ];

    public function shift(): BelongsTo
    {
        return $this->belongsTo(MasterShift::class, 'id_shift');
    }

    public function karyawanQc(): BelongsTo
    {
        return $this->belongsTo(MasterKaryawan::class, 'id_karyawan_qc');
    }

    public function partProses(): BelongsTo
    {
        return $this->belongsTo(MasterPartProses::class, 'id_part_proses');
    }

    public function jenisNg(): BelongsTo
    {
        return $this->belongsTo(MasterJenisNg::class, 'id_jenis_ng');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function noteBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_note_by');
    }

    public function noteResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'staff_note_resolved_by');
    }

    /** Waktu kerja dalam desimal jam — handle overnight shift */
    public function getWaktuKerjaJamAttribute(): float
    {
        try {
            $start = Carbon::parse('2000-01-01 '.$this->jam_start);
            $finish = Carbon::parse('2000-01-01 '.$this->jam_finish);
            if ($finish->lte($start)) {
                $finish->addDay();
            }
            $menit = $start->diffInMinutes($finish);

            return round($menit / 60, 2);
        } catch (\Throwable) {
            return 0;
        }
    }

    /** SPH Aktual QC = total qty inspeksi / waktu_kerja_jam */
    public function getSphAktualAttribute(): int
    {
        $wkj = $this->waktu_kerja_jam;
        $totalQty = ($this->qty_ok ?? 0) + ($this->qty_ng ?? 0);

        return $wkj > 0 ? (int) round($totalQty / $wkj) : 0;
    }
}
