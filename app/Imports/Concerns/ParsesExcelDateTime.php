<?php
namespace App\Imports\Concerns;

use Illuminate\Support\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

trait ParsesExcelDateTime
{
    /** Excel date cell (serial number atau string) → Y-m-d, atau null. */
    protected function parseDate($raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            if (is_numeric($raw)) {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            }
            return Carbon::parse(trim((string) $raw))->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Excel time cell (fraction of day atau string "08:00") → H:i, atau null. */
    protected function parseTime($raw): ?string
    {
        if ($raw === null || trim((string) $raw) === '') {
            return null;
        }
        try {
            if (is_numeric($raw)) {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('H:i');
            }
            return Carbon::parse(trim((string) $raw))->format('H:i');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
