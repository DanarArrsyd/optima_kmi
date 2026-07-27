<?php

namespace App\Models;

use App\Models\Concerns\NgDetailRow;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiProduksiNg extends Model
{
    use NgDetailRow;

    protected $table = 'transaksi_produksi_ng';

    protected $fillable = ['id_transaksi_produksi', 'id_jenis_ng', 'qty'];

    protected $casts = [
        'qty' => 'integer',
    ];

    public function transaksi(): BelongsTo
    {
        return $this->belongsTo(TransaksiProduksi::class, 'id_transaksi_produksi');
    }
}
