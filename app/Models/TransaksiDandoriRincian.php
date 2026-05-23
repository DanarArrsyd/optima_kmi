<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TransaksiDandoriRincian extends Model
{
    protected $table = 'transaksi_dandori_rincian';
    protected $fillable = ['id_transaksi_dandori', 'id_losstime', 'menit_loss', 'menit_over'];

    public function dandori(): BelongsTo { return $this->belongsTo(TransaksiDandori::class, 'id_transaksi_dandori'); }
    public function losstime(): BelongsTo { return $this->belongsTo(MasterLosstime::class, 'id_losstime'); }
}
