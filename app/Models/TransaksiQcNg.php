<?php

namespace App\Models;

use App\Models\Concerns\NgDetailRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiQcNg extends Model
{
    use NgDetailRow;

    protected $table = 'transaksi_qc_ng';

    protected $fillable = ['id_transaksi_qc', 'id_jenis_ng', 'qty'];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(TransaksiQc::class, 'id_transaksi_qc');
    }
}
