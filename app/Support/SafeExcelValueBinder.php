<?php

namespace App\Support;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\DefaultValueBinder;

/**
 * Cegah formula injection di file Excel hasil export.
 *
 * Binder bawaan PhpSpreadsheet menulis string yang diawali "=" sebagai FORMULA.
 * Kolom teks bebas di OPTIMA (problem_mesin, penyebab_breakdown, tindakan_perbaikan,
 * nama part/proses/customer) diisi oleh operator dan bisa berisi "=HYPERLINK(...)"
 * atau semacamnya. Saat admin membuka hasil export, formula itu dieksekusi di
 * mesinnya — jadi datanya harus dipaksa jadi teks.
 *
 * Angka tetap dibiarkan lewat binder bawaan supaya format numerik tidak rusak.
 */
class SafeExcelValueBinder extends DefaultValueBinder
{
    /** Karakter pembuka yang membuat spreadsheet memperlakukan sel sebagai formula/perintah. */
    private const DANGEROUS_PREFIXES = ['=', '+', '@', "\t", "\r"];

    public function bindValue(Cell $cell, $value): bool
    {
        if (is_string($value) && $value !== '' && $this->isDangerous($value)) {
            $cell->setValueExplicit($value, DataType::TYPE_STRING);

            return true;
        }

        return parent::bindValue($cell, $value);
    }

    private function isDangerous(string $value): bool
    {
        if (in_array($value[0], self::DANGEROUS_PREFIXES, true)) {
            return true;
        }

        // "-" hanya berbahaya kalau bukan angka negatif biasa (mis. "-1+cmd").
        return $value[0] === '-' && ! is_numeric($value);
    }
}
