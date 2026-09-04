<?php

namespace App\Imports\Concerns;

use App\Support\SaneDate;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date;

trait ParsesStrictImportDateTime
{
    /**
     * Tanggal di luar rentang wajar diperlakukan tak terbaca — tahun 4-digit apa pun lolos
     * parsing, jadi "0026-06-21" (salah ketik "2026") akan tersimpan diam-diam dan hilang
     * dari laporan yang memfilter per rentang tanggal.
     */
    protected function parseDate(mixed $value): ?string
    {
        $parsed = $this->parseDateUnbounded($value);

        return SaneDate::isSane($parsed) ? $parsed : null;
    }

    private function parseDateUnbounded(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_numeric($value)) {
            try {
                return Date::excelToDateTimeObject((float) $value)
                    ->format('Y-m-d');
            } catch (\Throwable) {
            }
        }
        if ($value instanceof Carbon || $value instanceof \DateTime) {
            return $value->format('Y-m-d');
        }
        $str = trim((string) $value);
        foreach (['Y-m-d', 'd/m/Y', 'd-m-Y', 'm/d/Y', 'Y/m/d'] as $fmt) {
            $d = \DateTime::createFromFormat($fmt, $str);
            if ($d && $d->format($fmt) === $str) {
                return $d->format('Y-m-d');
            }
        }

        return null;
    }

    protected function parseTime(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (is_numeric($value) && (float) $value < 1) {
            $secs = (int) round((float) $value * 86400);

            return sprintf('%02d:%02d', intdiv($secs, 3600), intdiv($secs % 3600, 60));
        }
        $str = trim((string) $value);
        if (preg_match('/^(\d{1,2}):(\d{2})/', $str, $mt)) {
            return sprintf('%02d:%02d', (int) $mt[1], (int) $mt[2]);
        }

        return null;
    }
}
