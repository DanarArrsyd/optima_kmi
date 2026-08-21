<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Dilempar BackupService::jalankan() saat lock 'backup-berjalan' sudah dipegang
 * pemanggil lain (klik manual vs jadwal terjadwal bertabrakan). Tipe terpisah
 * dari RuntimeException umum supaya pemanggil (controller, command) bisa
 * membedakan "sedang ada backup lain jalan" dari kegagalan sungguhan.
 */
class BackupSedangBerjalanException extends RuntimeException
{
    public function __construct(string $message = 'Backup sedang berjalan. Tunggu sampai selesai.')
    {
        parent::__construct($message);
    }
}
