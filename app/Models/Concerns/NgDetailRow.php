<?php

namespace App\Models\Concerns;

use App\Models\MasterJenisNg;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Perilaku bersama dua model baris detail NG.
 *
 * Sengaja TANPA SoftDeletes: baris detail ditulis ulang seluruhnya tiap simpan,
 * dan baris ter-soft-delete akan menempati slot UNIQUE sehingga hapus-lalu-tambah
 * jenis yang sama gagal dengan duplicate key.
 */
trait NgDetailRow
{
    protected $casts = [
        'qty' => 'integer',
    ];

    public function jenisNg(): BelongsTo
    {
        return $this->belongsTo(MasterJenisNg::class, 'id_jenis_ng');
    }
}
